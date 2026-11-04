<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Command;

use FwsDoctrineCrypt\Command\GenerateKeyCommand;
use FwsDoctrineCrypt\Test\Support\Entity\Person;
use FwsDoctrineCrypt\Test\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateKeyCommandTest extends TestCase
{
    private function generateKey(): string
    {
        $tester = new CommandTester(new GenerateKeyCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        return $tester->getDisplay();
    }

    public function testOutputsOnlyAHexEncodedKey(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\n\z/', $this->generateKey());
    }

    public function testEachKeyIsDifferent(): void
    {
        self::assertNotSame($this->generateKey(), $this->generateKey());
    }

    public function testGeneratedKeyEncryptsAndDecrypts(): void
    {
        $environment = new TestEnvironment();
        $crypt = $environment->crypt($environment->entityManager(), trim($this->generateKey()));
        $person = new Person('person@example.com');

        $crypt->encryptPlaintext($person);
        self::assertStringStartsWith('brng:', $person->getEmail());

        $crypt->decryptEncrypted($person);
        self::assertSame('person@example.com', $person->getEmail());

        $environment->removeWorkingDirectory();
    }
}
