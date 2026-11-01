<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Listener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Proxy\InternalProxy;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\KeyProvider\StringProvider;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Test\Support\Entity\InheritedSecret;
use FwsDoctrineCrypt\Test\Support\Entity\NullableContact;
use FwsDoctrineCrypt\Test\Support\Entity\Person;
use FwsDoctrineCrypt\Test\Support\Entity\TwoIndexes;
use FwsDoctrineCrypt\Test\Support\Entity\TypedValues;
use FwsDoctrineCrypt\Test\Support\Entity\Unencrypted;
use FwsDoctrineCrypt\Test\Support\MisconfiguredEntity\BadTransformation;
use FwsDoctrineCrypt\Test\Support\MisconfiguredEntity\IntegerColumn;
use FwsDoctrineCrypt\Test\Support\MisconfiguredEntity\IntegerProperty;
use FwsDoctrineCrypt\Test\Support\MisconfiguredEntity\MistypedIndex;
use FwsDoctrineCrypt\Test\Support\MisconfiguredEntity\UnsupportedType;
use FwsDoctrineCrypt\Test\Support\SimulatedDatabaseFailure;
use FwsDoctrineCrypt\Test\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DoctrineEntitySubscriberTest extends TestCase
{
    private const MAGIC_HEADER = 'brng:';
    private const MISCONFIGURED = __DIR__ . '/../Support/MisconfiguredEntity';

    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->removeWorkingDirectory();
    }

    private function storePerson(string $email = 'person@example.com'): int
    {
        $entityManager = $this->environment->listeningEntityManager();
        $person = new Person($email);
        $entityManager->persist($person);
        $entityManager->flush();
        $this->environment->database->writes = [];

        return $person->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(int $id): array
    {
        return $this->environment->database->tables['person'][$id];
    }

    public function testInsertStoresCiphertextAndBlindIndex(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $entityManager->persist(new Person('person@example.com', '0123'));
        $entityManager->flush();

        $inserts = $this->environment->database->writesTo('INSERT', 'person');
        self::assertCount(1, $inserts);
        self::assertStringStartsWith(self::MAGIC_HEADER, $inserts[0]['email']);
        self::assertStringStartsWith(self::MAGIC_HEADER, $inserts[0]['phone']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $inserts[0]['emailIndex']);
    }

    public function testValueChangedBetweenPersistAndFlushIsEncrypted(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $person = new Person('old@example.com');
        $entityManager->persist($person);
        $person->setEmail('new@example.com');
        $entityManager->flush();

        $id = $person->getId();
        self::assertStringStartsWith(self::MAGIC_HEADER, $this->storedRow($id)['email']);

        $loaded = $this->environment->listeningEntityManager()->find(Person::class, $id);
        self::assertSame('new@example.com', $loaded->getEmail());
    }

    public function testBlindIndexMatchesTheValueActuallyStored(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $person = new Person('old@example.com');
        $entityManager->persist($person);
        $person->setEmail('new@example.com');
        $entityManager->flush();

        $expected = $this->environment
            ->crypt($entityManager)
            ->getIndex(Person::class, 'email', 'new@example.com', 'emailIndex');

        self::assertSame($expected, $this->storedRow($person->getId())['emailIndex']);
    }

    public function testEntityHoldsPlaintextBetweenPersistAndFlush(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $person = new Person('person@example.com');
        $entityManager->persist($person);

        self::assertSame('person@example.com', $person->getEmail());
    }

    public function testEntityHoldsPlaintextAfterInsert(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $person = new Person('person@example.com', '0123');
        $entityManager->persist($person);
        $entityManager->flush();

        self::assertSame('person@example.com', $person->getEmail());
        self::assertSame('0123', $person->getPhone());
    }

    public function testBlindIndexIsAvailableOnEntityAfterInsert(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $person = new Person('person@example.com');
        $entityManager->persist($person);
        $entityManager->flush();

        self::assertSame($this->storedRow($person->getId())['emailIndex'], $person->getEmailIndex());
    }

    public function testLoadDecrypts(): void
    {
        $id = $this->storePerson('person@example.com');

        $person = $this->environment->listeningEntityManager()->find(Person::class, $id);

        self::assertSame('person@example.com', $person->getEmail());
    }

    public function testLoadingAndFlushingWithoutChangesWritesNothing(): void
    {
        $id = $this->storePerson();
        $stored = $this->storedRow($id);

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->find(Person::class, $id);
        $entityManager->flush();

        self::assertSame([], $this->environment->database->writes);
        self::assertSame($stored, $this->storedRow($id));
        self::assertSame('person@example.com', $person->getEmail());
    }

    public function testFlushAfterInsertWithoutChangesWritesNothing(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $entityManager->persist(new Person());
        $entityManager->flush();
        $this->environment->database->writes = [];

        $entityManager->flush();

        self::assertSame([], $this->environment->database->writes);
    }

    public function testChangingAnUnencryptedFieldLeavesCiphertextUntouched(): void
    {
        $id = $this->storePerson();
        $stored = $this->storedRow($id);

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->find(Person::class, $id);
        $person->setName('new name');
        $entityManager->flush();

        self::assertSame([['name' => 'new name']], $this->environment->database->writesTo('UPDATE', 'person'));
        self::assertSame($stored['email'], $this->storedRow($id)['email']);
        self::assertSame('person@example.com', $person->getEmail());
    }

    public function testChangingOneEncryptedFieldOnlyWritesThatFieldAndItsIndex(): void
    {
        $id = $this->storePerson('old@example.com');
        $stored = $this->storedRow($id);

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->find(Person::class, $id);
        $person->setEmail('new@example.com');
        $entityManager->flush();

        $updates = $this->environment->database->writesTo('UPDATE', 'person');
        self::assertCount(1, $updates);
        self::assertEqualsCanonicalizing(['email', 'emailIndex'], array_keys($updates[0]));
        self::assertStringStartsWith(self::MAGIC_HEADER, $updates[0]['email']);
        self::assertNotSame($stored['emailIndex'], $updates[0]['emailIndex']);
        self::assertSame($stored['phone'], $this->storedRow($id)['phone']);
        self::assertSame('new@example.com', $person->getEmail());
        self::assertSame($updates[0]['emailIndex'], $person->getEmailIndex());
    }

    public function testChangingAFieldWithoutAnIndexDoesNotTouchOtherFields(): void
    {
        $id = $this->storePerson();

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->find(Person::class, $id);
        $person->setPhone('999');
        $entityManager->flush();

        $updates = $this->environment->database->writesTo('UPDATE', 'person');
        self::assertCount(1, $updates);
        self::assertSame(['phone'], array_keys($updates[0]));
        self::assertStringStartsWith(self::MAGIC_HEADER, $updates[0]['phone']);
    }

    public function testRepeatedFlushesKeepWorking(): void
    {
        $id = $this->storePerson();

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->find(Person::class, $id);

        $person->setName('first');
        $entityManager->flush();
        $person->setEmail('second@example.com');
        $entityManager->flush();
        $person->setName('third');
        $entityManager->flush();

        self::assertCount(3, $this->environment->database->writesTo('UPDATE', 'person'));
        self::assertSame('second@example.com', $person->getEmail());

        $reloaded = $this->environment->listeningEntityManager()->find(Person::class, $id);
        self::assertSame('second@example.com', $reloaded->getEmail());
    }

    public function testValueBeginningWithMagicHeaderIsStillEncrypted(): void
    {
        $id = $this->storePerson('brng:not-really-ciphertext');

        self::assertNotSame('brng:not-really-ciphertext', $this->storedRow($id)['email']);

        $person = $this->environment->listeningEntityManager()->find(Person::class, $id);
        self::assertSame('brng:not-really-ciphertext', $person->getEmail());
    }

    public function testUpdatedValueBeginningWithMagicHeaderIsStillEncrypted(): void
    {
        $id = $this->storePerson();

        $entityManager = $this->environment->listeningEntityManager();
        $entityManager->find(Person::class, $id)->setEmail('brng:not-really-ciphertext');
        $entityManager->flush();

        self::assertNotSame('brng:not-really-ciphertext', $this->storedRow($id)['email']);
        self::assertSame(
            'brng:not-really-ciphertext',
            $this->environment->listeningEntityManager()->find(Person::class, $id)->getEmail()
        );
    }

    public function testRehydratingAManagedEntityDoesNotDecryptTwice(): void
    {
        $id = $this->storePerson();

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->find(Person::class, $id);
        $again = $entityManager->getRepository(Person::class)->findAll();

        self::assertSame($person, $again[0]);
        self::assertSame('person@example.com', $person->getEmail());
    }

    public function testRefreshDecryptsAndDiscardsLocalChanges(): void
    {
        $id = $this->storePerson('stored@example.com');

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->find(Person::class, $id);
        $person->setEmail('local@example.com');
        $entityManager->refresh($person);
        $entityManager->flush();

        self::assertSame('stored@example.com', $person->getEmail());
        self::assertSame([], $this->environment->database->writes);
    }

    public function testLazyProxyWithPrivatePropertiesIsDecrypted(): void
    {
        $id = $this->storePerson('person@example.com');

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->getReference(Person::class, $id);

        self::assertInstanceOf(InternalProxy::class, $person);
        self::assertSame('person@example.com', $person->getEmail());
        $entityManager->flush();
        self::assertSame([], $this->environment->database->writes);
    }

    public function testStoredPlaintextIsRejectedOnLoad(): void
    {
        $id = $this->storePerson();
        $this->environment->database->tables['person'][$id]['phone'] = 'never encrypted';

        $this->expectException(DoctrineCryptException::class);
        $this->expectExceptionMessage(Person::class);

        $this->environment->listeningEntityManager()->find(Person::class, $id);
    }

    public function testWrongKeyIsRejectedOnLoad(): void
    {
        $id = $this->storePerson();

        $this->expectException(DoctrineCryptException::class);
        $this->expectExceptionMessage(Person::class);

        $this->environment->listeningEntityManager(TestEnvironment::OTHER_KEY)->find(Person::class, $id);
    }

    public function testMistypedIndexPropertyFailsInsteadOfStoringPlaintext(): void
    {
        $entityManager = $this->environment->listeningEntityManager(entityDirectory: self::MISCONFIGURED);
        $entityManager->persist(new MistypedIndex());

        try {
            $entityManager->flush();
            self::fail('Expected flush to fail');
        } catch (DoctrineCryptException $exception) {
            self::assertStringContainsString('has property value noSuchProperty but no property exists', $exception->getMessage());
        }

        self::assertSame([], $this->environment->database->writes);
    }

    public function testUnsupportedTypeFailsInsteadOfStoringPlaintext(): void
    {
        $entityManager = $this->environment->listeningEntityManager(entityDirectory: self::MISCONFIGURED);
        $entityManager->persist(new UnsupportedType());

        try {
            $entityManager->flush();
            self::fail('Expected flush to fail');
        } catch (DoctrineCryptException $exception) {
            self::assertStringContainsString('datetime_immutable', $exception->getMessage());
        }

        self::assertSame([], $this->environment->database->writes);
    }

    public function testFailedFlushLeavesEntityInPlaintext(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $person = new Person('person@example.com');
        $entityManager->persist($person);
        $this->environment->database->failNextWrite = true;

        try {
            $entityManager->flush();
            self::fail('Expected flush to fail');
        } catch (SimulatedDatabaseFailure) {
        }

        self::assertSame('person@example.com', $person->getEmail());
    }

    public function testEntityWithoutAttributesIsStoredAsIs(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $entity = new Unencrypted();
        $entityManager->persist($entity);
        $entityManager->flush();

        self::assertSame('public', $this->environment->database->writesTo('INSERT', 'unencrypted')[0]['value']);
    }

    /**
     * Doctrine recomputes the change set when any preUpdate listener exists. That must not put plaintext back.
     */
    public function testAnotherPreUpdateListenerDoesNotCausePlaintextToBeWritten(): void
    {
        $id = $this->storePerson();

        $entityManager = $this->environment->listeningEntityManager();
        $entityManager->getEventManager()->addEventSubscriber(new class implements EventSubscriber {
            public function getSubscribedEvents(): array
            {
                return [Events::preUpdate];
            }

            public function preUpdate(PreUpdateEventArgs $event): void
            {
            }
        });
        $entityManager->find(Person::class, $id)->setEmail('new@example.com');
        $entityManager->flush();

        self::assertStringStartsWith(self::MAGIC_HEADER, $this->storedRow($id)['email']);
    }

    public function testNullIsStoredAsNullWithNullBlindIndex(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $contact = new NullableContact();
        $entityManager->persist($contact);
        $entityManager->flush();

        $row = $this->environment->database->tables['nullable_contact'][$contact->id];
        self::assertNull($row['notes']);
        self::assertNull($row['notesIndex']);

        $this->environment->database->writes = [];
        $entityManager->flush();
        self::assertSame([], $this->environment->database->writes);
    }

    public function testNullLoadsAsNull(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $contact = new NullableContact();
        $entityManager->persist($contact);
        $entityManager->flush();

        $loaded = $this->environment->listeningEntityManager()->find(NullableContact::class, $contact->id);

        self::assertNull($loaded->notes);
    }

    public function testValueCanBeChangedToNullAndBack(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $contact = new NullableContact();
        $contact->notes = 'first';
        $entityManager->persist($contact);
        $entityManager->flush();
        $table = 'nullable_contact';
        self::assertStringStartsWith(self::MAGIC_HEADER, $this->environment->database->tables[$table][$contact->id]['notes']);
        self::assertNotNull($this->environment->database->tables[$table][$contact->id]['notesIndex']);

        $contact->notes = null;
        $entityManager->flush();
        self::assertNull($this->environment->database->tables[$table][$contact->id]['notes']);
        self::assertNull($this->environment->database->tables[$table][$contact->id]['notesIndex']);
        self::assertNull($contact->notesIndex);

        $contact->notes = 'second';
        $entityManager->flush();
        self::assertStringStartsWith(self::MAGIC_HEADER, $this->environment->database->tables[$table][$contact->id]['notes']);
        self::assertSame('second', $contact->notes);
    }

    public function testIntegerFloatAndBooleanValuesRoundTrip(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $values = new TypedValues(42, 1.5, false);
        $entityManager->persist($values);
        $entityManager->flush();

        $row = $this->environment->database->tables['typed_row'][$values->id];
        foreach (['age', 'score', 'active'] as $column) {
            self::assertStringStartsWith(self::MAGIC_HEADER, $row[$column]);
        }
        self::assertSame(42, $values->age);

        $secondManager = $this->environment->listeningEntityManager();
        $loaded = $secondManager->find(TypedValues::class, $values->id);
        self::assertSame(42, $loaded->age);
        self::assertSame(1.5, $loaded->score);
        self::assertFalse($loaded->active);

        $this->environment->database->writes = [];
        $secondManager->flush();
        self::assertSame([], $this->environment->database->writes);
    }

    public function testWrongValueTypeFailsWithACryptException(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $entityManager->persist(new TypedValues('not a number', 1.5, true));

        $this->expectException(DoctrineCryptException::class);
        $this->expectExceptionMessage(TypedValues::class);

        $entityManager->flush();
    }

    public function testTwoBlindIndexesOnOneProperty(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $entity = new TwoIndexes();
        $entityManager->persist($entity);
        $entityManager->flush();

        $row = $this->environment->database->tables['two_indexes'][$entity->id];
        self::assertMatchesRegularExpression('/^[0-9a-f]{4}$/', $row['shortIndex']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $row['longIndex']);
    }

    public function testPrivatePropertyOfMappedSuperclassIsEncrypted(): void
    {
        $entityManager = $this->environment->listeningEntityManager();
        $entity = new InheritedSecret('inherited value');
        $entityManager->persist($entity);
        $entityManager->flush();

        $row = $this->environment->database->tables['inherited_secret'][$entity->id];
        self::assertStringStartsWith(self::MAGIC_HEADER, $row['secret']);

        $loaded = $this->environment->listeningEntityManager()->find(InheritedSecret::class, $entity->id);
        self::assertSame('inherited value', $loaded->getSecret());
    }

    /**
     * Data already stored must stay readable. If this fails, existing databases can no longer be decrypted.
     */
    public function testDataWrittenDirectlyWithCipherSweetIsStillReadable(): void
    {
        $row = (new EncryptedRow(new CipherSweet(new StringProvider(TestEnvironment::KEY)), Person::class))
            ->addTextField('email')
            ->addTextField('phone')
            ->addBlindIndex('email', new BlindIndex('emailIndex', [], 32, false));
        [$stored, $indexes] = $row->prepareRowForStorage(['email' => 'old@example.com', 'phone' => '0123']);

        $this->environment->database->tables['person'][1] = [
            'id' => 1,
            'email' => $stored['email'],
            'emailIndex' => $indexes['emailIndex'],
            'phone' => $stored['phone'],
            'name' => 'name',
        ];

        $entityManager = $this->environment->listeningEntityManager();
        $person = $entityManager->find(Person::class, 1);

        self::assertSame('old@example.com', $person->getEmail());
        self::assertSame(
            $indexes['emailIndex'],
            $this->environment->crypt($entityManager)->getIndex(Person::class, 'email', 'old@example.com', 'emailIndex')
        );
    }

    /**
     * @return array<string, array{0: object, 1: string}>
     */
    public static function misconfiguredEntities(): array
    {
        return [
            'integer column' => [new IntegerColumn(), 'cannot hold ciphertext'],
            'property that cannot hold ciphertext' => [new IntegerProperty(), 'does not accept a string'],
            'invalid transformation class' => [new BadTransformation(), 'not a CipherSweet transformation'],
        ];
    }

    #[DataProvider('misconfiguredEntities')]
    public function testMisconfigurationFailsBeforeAnythingIsWritten(object $entity, string $message): void
    {
        $entityManager = $this->environment->listeningEntityManager(entityDirectory: self::MISCONFIGURED);
        $entityManager->persist($entity);

        try {
            $entityManager->flush();
            self::fail('Expected flush to fail');
        } catch (DoctrineCryptException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }

        self::assertSame([], $this->environment->database->writes);
    }
}
