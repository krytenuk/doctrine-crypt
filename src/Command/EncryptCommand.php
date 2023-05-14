<?php

namespace FwsDoctrineCrypt\Command;

use FwsDoctrineCrypt\Model\Crypt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Description of EncryptEntityCommand
 *
 * @author Garry Childs <info@freedomwebservices.net>
 */
class EncryptCommand extends AbstractCommand
{
    /**
     * Setup CLI command
     */
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('doctrine-crypt:encrypt')
            ->setDescription('Encrypt given entities data')
            ->setHelp("Encrypt sensitive data on database")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Perform test run, don't save to database");
    }

    /**
     * Execute command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->init($input, $output);

        $this->entities = $this->crypt->getEntityPropertiesFromConfig();

        $output->writeln(sprintf('Encrypting database records using %s encryption', Crypt::$cryptNames[$this->crypt->getEncryptionMethod()]));

        $processed = $this->processEntities(self::ENCRYPT);
        if ($processed) {
            $output->writeln('<info>Finished encrypting your entities.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>There was a problem encrypting your entities.</info>');
        return Command::FAILURE;
    }
}
