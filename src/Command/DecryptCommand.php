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
class DecryptCommand extends AbstractCommand
{
    /**
     * Setup CLI command
     */
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('doctrine-crypt:decrypt')
            ->setDescription('Decrypt given entities data')
            ->setHelp("Decrypt sensitive data on database")
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

        $output->writeln(sprintf('Decrypting database records using %s decryption', Crypt::$cryptNames[$this->crypt->getEncryptionMethod()]));

        $processed = $this->processEntities(self::DECRYPT);
        if ($processed) {
            $output->writeln('<info>Finished decrypting your entities.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>There was a problem decrypting your entities.</info>');
        return Command::FAILURE;
    }
}
