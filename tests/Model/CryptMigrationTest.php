<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Model;

use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Filter\CryptIndex;
use FwsDoctrineCrypt\Test\Support\Entity\Person;
use FwsDoctrineCrypt\Test\Support\Entity\Unencrypted;
use FwsDoctrineCrypt\Test\Support\TestEnvironment;
use ParagonIE\CipherSweet\Backend\FIPSCrypto;
use PHPUnit\Framework\TestCase;

/**
 * The tolerant methods used by the CLI commands, which read rows with no subscriber attached
 * and have to cope with tables that are only partly migrated.
 */
final class CryptMigrationTest extends TestCase
{
    private const MAGIC_HEADER = 'brng:';

    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->removeWorkingDirectory();
    }

    public function testEncryptPlaintextEncryptsEverything(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());
        $person = new Person('person@example.com', '0123');

        $crypt->encryptPlaintext($person);

        self::assertStringStartsWith(self::MAGIC_HEADER, $person->getEmail());
        self::assertStringStartsWith(self::MAGIC_HEADER, $person->getPhone());
        self::assertNotNull($person->getEmailIndex());
    }

    public function testEncryptPlaintextIsIdempotent(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());
        $person = new Person();
        $crypt->encryptPlaintext($person);
        $email = $person->getEmail();
        $phone = $person->getPhone();

        $crypt->encryptPlaintext($person);

        self::assertSame($email, $person->getEmail());
        self::assertSame($phone, $person->getPhone());
    }

    public function testEncryptPlaintextHandlesPartlyEncryptedRowsInAnyOrder(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());

        $plain = new Person();
        $crypt->encryptPlaintext($plain);

        $partial = new Person('partial@example.com', '0123');
        $crypt->encryptPlaintext($partial);
        $email = $partial->getEmail();
        $partial->setPhone('new plaintext column');

        $crypt->encryptPlaintext($partial);

        self::assertSame($email, $partial->getEmail());
        self::assertStringStartsWith(self::MAGIC_HEADER, $partial->getPhone());
    }

    public function testEncryptPlaintextRefusesCiphertextItCannotDecrypt(): void
    {
        $entityManager = $this->environment->entityManager();
        $person = new Person('person@example.com', '0123');
        $this->environment->crypt($entityManager)->encryptPlaintext($person);
        $email = $person->getEmail();

        try {
            $this->environment->crypt($entityManager, TestEnvironment::OTHER_KEY)->encryptPlaintext($person);
            self::fail('Expected encryptPlaintext to refuse');
        } catch (DoctrineCryptException $exception) {
            self::assertStringContainsString('email', $exception->getMessage());
        }

        self::assertSame($email, $person->getEmail());
    }

    public function testDecryptEncryptedRestoresValues(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());
        $person = new Person('person@example.com', '0123');
        $crypt->encryptPlaintext($person);

        $crypt->decryptEncrypted($person);

        self::assertSame('person@example.com', $person->getEmail());
        self::assertSame('0123', $person->getPhone());
    }

    public function testDecryptEncryptedLeavesPlaintextAlone(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());
        $person = new Person('person@example.com', '0123');
        $crypt->encryptPlaintext($person);
        $person->setPhone('never encrypted');

        $crypt->decryptEncrypted($person);

        self::assertSame('person@example.com', $person->getEmail());
        self::assertSame('never encrypted', $person->getPhone());
    }

    public function testDecryptEncryptedRefusesCiphertextItCannotDecrypt(): void
    {
        $entityManager = $this->environment->entityManager();
        $person = new Person();
        $this->environment->crypt($entityManager)->encryptPlaintext($person);

        $this->expectException(DoctrineCryptException::class);
        $this->expectExceptionMessage('email');

        $this->environment->crypt($entityManager, TestEnvironment::OTHER_KEY)->decryptEncrypted($person);
    }

    public function testEntityWithoutAttributesHasNoEncryptedProperties(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());

        self::assertSame([], $crypt->getEncryptedProperties(Unencrypted::class));
    }

    public function testConfiguredEngineIsUsed(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager(), engine: FIPSCrypto::class);
        $person = new Person();

        $crypt->encryptPlaintext($person);

        self::assertInstanceOf(FIPSCrypto::class, $crypt->getEngine()->getBackend());
        self::assertStringStartsWith('fips:', $person->getEmail());
    }

    public function testInvalidEngineIsRejected(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager(), engine: \ArrayObject::class);

        $this->expectException(DoctrineCryptException::class);

        $crypt->getEngine();
    }

    public function testIndexOfPropertyThatIsNotEncryptedIsRefused(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());

        $this->expectException(DoctrineCryptException::class);

        $crypt->getIndex(Person::class, 'name', 'plaintext', null);
    }

    public function testIndexOfNullIsNull(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());

        self::assertNull($crypt->getIndex(Person::class, 'email', null, 'emailIndex'));
    }

    public function testFilterReturnsTheStoredBlindIndex(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());
        $person = new Person('person@example.com');
        $crypt->encryptPlaintext($person);

        $filter = new CryptIndex($crypt, ['entity_class_name' => Person::class, 'property' => 'email']);

        self::assertSame($person->getEmailIndex(), $filter->filter('person@example.com'));
    }

    public function testFilterReportsAnUnknownIndexNameInsteadOfFindingNothing(): void
    {
        $crypt = $this->environment->crypt($this->environment->entityManager());
        $filter = new CryptIndex($crypt, [
            'entity_class_name' => Person::class,
            'property' => 'email',
            'filter_name' => 'no_such_index',
        ]);

        $this->expectException(\Exception::class);

        $filter->filter('person@example.com');
    }
}
