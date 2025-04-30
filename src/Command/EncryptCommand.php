<?php

namespace FwsDoctrineCrypt\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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

        $output->writeln('Encrypting database records');

        $processed = $this->processEntities('encrypt');
        if ($processed) {
            $output->writeln('<info>Finished encrypting your entities.</info>');
            if ($this->input->getOption('dry-run')) {
                $this->output->writeln('Dry run option set, no records were changed');
            }
            return Command::SUCCESS;
        }

        $output->writeln('<error>There was a problem encrypting your entities.</error>');
        return Command::FAILURE;
    }
}
