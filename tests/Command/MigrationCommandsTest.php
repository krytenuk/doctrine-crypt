<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Command;

use Doctrine\ORM\EntityManager;
use FwsDoctrineCrypt\Command\AbstractCommand;
use FwsDoctrineCrypt\Command\DecryptCommand;
use FwsDoctrineCrypt\Command\EncryptCommand;
use FwsDoctrineCrypt\Test\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrationCommandsTest extends TestCase
{
    private const ENTITIES = __DIR__ . '/../Support/CommandEntity';

    private TestEnvironment $environment;

    private int $exitCode = 0;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->setRow('someone@example.com', '0123');
    }

    protected function tearDown(): void
    {
        $this->environment->removeWorkingDirectory();
    }

    private function setRow(string $email, string $phone): void
    {
        $this->environment->database->tables['customer'][1] = [
            'id' => 1,
            'email' => $email,
            'emailIndex' => null,
            'phone' => $phone,
            'name' => 'name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return $this->environment->database->tables['customer'][1];
    }

    /**
     * @param class-string<AbstractCommand> $commandClass
     */
    private function runCommand(
        string $commandClass,
        array $options = [],
        string $key = TestEnvironment::KEY,
        ?EntityManager $entityManager = null
    ): string {
        $entityManager ??= $this->environment->entityManager(self::ENTITIES);
        $command = new $commandClass(
            $entityManager,
            $this->environment->attributes(),
            $this->environment->crypt($entityManager, $key)
        );
        $application = new Application();
        method_exists($application, 'addCommand') ? $application->addCommand($command) : $application->add($command);

        $tester = new CommandTester($command);
        $tester->setInputs(['y']);
        $this->exitCode = $tester->execute($options);

        return $tester->getDisplay();
    }

    public function testEncryptMigratesPlaintext(): void
    {
        $this->runCommand(EncryptCommand::class);

        self::assertStringStartsWith('brng:', $this->row()['email']);
        self::assertStringStartsWith('brng:', $this->row()['phone']);
        self::assertNotNull($this->row()['emailIndex']);
    }

    public function testEncryptCanBeRunAgainWithoutChangingOrFailing(): void
    {
        $this->runCommand(EncryptCommand::class);
        $encrypted = $this->row();

        $output = $this->runCommand(EncryptCommand::class);

        self::assertSame($encrypted, $this->row());
        self::assertStringNotContainsString('failed', $output);
    }

    public function testEncryptCompletesPartlyEncryptedRow(): void
    {
        $this->runCommand(EncryptCommand::class);
        $email = $this->row()['email'];
        $this->environment->database->tables['customer'][1]['phone'] = 'newly encrypted column';

        $this->runCommand(EncryptCommand::class);

        self::assertSame($email, $this->row()['email']);
        self::assertStringStartsWith('brng:', $this->row()['phone']);
    }

    public function testDecryptRestoresPlaintext(): void
    {
        $this->runCommand(EncryptCommand::class);

        $this->runCommand(DecryptCommand::class);

        self::assertSame('someone@example.com', $this->row()['email']);
        self::assertSame('0123', $this->row()['phone']);
    }

    public function testDryRunWritesNothing(): void
    {
        $before = $this->row();

        $this->runCommand(EncryptCommand::class, ['--dry-run' => true]);

        self::assertSame($before, $this->row());
    }

    public function testSuccessfulRunExitsWithSuccess(): void
    {
        $this->runCommand(EncryptCommand::class);

        self::assertSame(Command::SUCCESS, $this->exitCode);
    }

    public function testWrongKeyFailsAndLeavesTheDataAlone(): void
    {
        $this->runCommand(EncryptCommand::class);
        $encrypted = $this->row();

        foreach ([EncryptCommand::class, DecryptCommand::class] as $command) {
            $output = $this->runCommand($command, key: TestEnvironment::OTHER_KEY);

            self::assertSame(Command::FAILURE, $this->exitCode);
            self::assertStringContainsString('Failed to process record', $output);
            self::assertSame($encrypted, $this->row());
        }
    }

    public function testDecryptClearsNullableBlindIndex(): void
    {
        $this->runCommand(EncryptCommand::class);

        $this->runCommand(DecryptCommand::class);

        self::assertNull($this->row()['emailIndex']);
    }

    public function testDecryptKeepsBlindIndexWhoseColumnIsNotNullable(): void
    {
        $this->environment->database->tables['required_index'][1] = [
            'id' => 1,
            'secret' => 'value',
            'secretIndex' => 'placeholder',
        ];
        $this->runCommand(EncryptCommand::class);
        $index = $this->environment->database->tables['required_index'][1]['secretIndex'];

        $this->runCommand(DecryptCommand::class);

        self::assertSame(Command::SUCCESS, $this->exitCode);
        self::assertSame('value', $this->environment->database->tables['required_index'][1]['secret']);
        self::assertSame($index, $this->environment->database->tables['required_index'][1]['secretIndex']);
    }

    public function testCommandsWorkWhenTheSubscriberIsAttached(): void
    {
        $this->runCommand(
            EncryptCommand::class,
            entityManager: $this->environment->listeningEntityManager(entityDirectory: self::ENTITIES)
        );
        $encrypted = $this->row();
        self::assertSame(Command::SUCCESS, $this->exitCode);
        self::assertStringStartsWith('brng:', $encrypted['email']);

        $this->runCommand(
            EncryptCommand::class,
            entityManager: $this->environment->listeningEntityManager(entityDirectory: self::ENTITIES)
        );
        self::assertSame(Command::SUCCESS, $this->exitCode);
        self::assertSame($encrypted, $this->row());

        $this->runCommand(
            DecryptCommand::class,
            entityManager: $this->environment->listeningEntityManager(entityDirectory: self::ENTITIES)
        );
        self::assertSame('someone@example.com', $this->row()['email']);
    }

    public function testEncryptedTypedValuesSurviveAMigrationBothWays(): void
    {
        $this->environment->database->tables['typed_row'][1] = [
            'id' => 1,
            'age' => '42',
            'score' => '1.5',
            'active' => '0',
        ];

        $this->runCommand(EncryptCommand::class);
        self::assertSame(Command::SUCCESS, $this->exitCode);
        self::assertStringStartsWith('brng:', $this->environment->database->tables['typed_row'][1]['age']);

        $this->runCommand(DecryptCommand::class);
        self::assertSame(Command::SUCCESS, $this->exitCode);
        self::assertSame('42', $this->environment->database->tables['typed_row'][1]['age']);
        self::assertSame('1.5', $this->environment->database->tables['typed_row'][1]['score']);
        self::assertSame('0', $this->environment->database->tables['typed_row'][1]['active']);
    }
}
