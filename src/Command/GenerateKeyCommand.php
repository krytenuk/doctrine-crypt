<?php

namespace FwsDoctrineCrypt\Command;

use ParagonIE\ConstantTime\Hex;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Outputs a random key for the doctrine-crypt.cipherSweet.encryptionKey config.
 * It needs no entity manager or existing key, so that it can run before the module is configured.
 *
 * @author Garry Childs <info@freedomwebservices.net>
 */
class GenerateKeyCommand extends Command
{
    /**
     * Setup CLI command
     */
    protected function configure(): void
    {
        $this
            ->setName('doctrine-crypt:generate-key')
            ->setDescription('Generate a configuration encryption key')
            ->setHelp('Output a random encryption key, for the doctrine-crypt.cipherSweet.encryptionKey config');
    }

    /**
     * Execute command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(Hex::encode(random_bytes(32)));

        return Command::SUCCESS;
    }
}
