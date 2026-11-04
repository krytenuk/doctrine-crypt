<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Integration;

use FwsDoctrineCrypt\Command\EncryptCommand;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Test\Support\EmbeddedEntity\Holder;
use FwsDoctrineCrypt\Test\Support\EmbeddedEntity\NestedHolder;
use FwsDoctrineCrypt\Test\Support\EmbeddedEntity\PlainHolder;
use FwsDoctrineCrypt\Test\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Encrypted fields in an embeddable are not supported, and must fail rather than be stored in plaintext.
 */
final class EmbeddableTest extends TestCase
{
    private const ENTITIES = __DIR__ . '/../Support/EmbeddedEntity';

    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->removeWorkingDirectory();
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function entitiesWithEncryptedEmbeddables(): array
    {
        return [
            'embeddable' => [Holder::class],
            'nested embeddable' => [NestedHolder::class],
        ];
    }

    #[DataProvider('entitiesWithEncryptedEmbeddables')]
    public function testFlushFailsInsteadOfStoringPlaintext(string $entityClass): void
    {
        $entityManager = $this->environment->listeningEntityManager(entityDirectory: self::ENTITIES);
        $entityManager->persist(new $entityClass());

        try {
            $entityManager->flush();
            self::fail('Expected flush to fail');
        } catch (DoctrineCryptException $exception) {
            self::assertStringContainsString('is an embeddable with encrypted fields', $exception->getMessage());
        }

        self::assertSame([], $this->environment->database->writes);
    }

    public function testLoadFails(): void
    {
        $this->environment->database->tables['holder'][1] = ['id' => 1, 'address_city' => 'Leeds'];

        $this->expectException(DoctrineCryptException::class);
        $this->expectExceptionMessage('is an embeddable with encrypted fields');

        $this->environment->listeningEntityManager(entityDirectory: self::ENTITIES)->find(Holder::class, 1);
    }

    public function testEncryptCommandReportsItAndWritesNothing(): void
    {
        $this->environment->database->tables['holder'][1] = ['id' => 1, 'address_city' => 'Leeds'];
        $entityManager = $this->environment->entityManager(self::ENTITIES);
        $command = new EncryptCommand(
            $entityManager,
            $this->environment->attributes(),
            $this->environment->crypt($entityManager)
        );
        $application = new Application();
        method_exists($application, 'addCommand') ? $application->addCommand($command) : $application->add($command);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(Holder::class . '::$address is an embeddable', $tester->getDisplay());
        self::assertSame('Leeds', $this->environment->database->tables['holder'][1]['address_city']);
    }

    public function testEmbeddableWithoutEncryptedFieldsStillWorks(): void
    {
        $entityManager = $this->environment->listeningEntityManager(entityDirectory: self::ENTITIES);
        $holder = new PlainHolder();
        $entityManager->persist($holder);
        $entityManager->flush();

        $row = $this->environment->database->tables['plain_holder'][$holder->id];
        self::assertStringStartsWith('brng:', $row['secret']);
        self::assertSame('Leeds', $row['address_city']);
    }
}
