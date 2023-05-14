<?php

namespace FwsDoctrineCrypt\Command;

use Doctrine\ORM\EntityManagerInterface;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Model\Crypt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReEncryptCommand extends AbstractCommand
{
    public function __construct(
        EntityManagerInterface $entityManager,
        protected array $config
    )
    {
        parent::__construct($entityManager);
    }

    /**
     * Setup CLI command
     */
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('doctrine-crypt:re-encrypt')
            ->setDescription('Change encryption on database from RSA to Block Cipher or vica-versa')
            ->setHelp("Decrypt and then encrypt sensitive data on database")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Perform test run, don't save to database")
            ->addArgument('decrypt', InputArgument::OPTIONAL, 'Decryption Method', null)
            ->addArgument('encrypt', InputArgument::OPTIONAL, 'Encryption Method', null);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws DoctrineCryptException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->init($input, $output);

        /** Attempt to set Crypt models */
        if (!$this->initCrypt()) {
            return Command::FAILURE;
        }

        $this->entities = $this->crypt->getEntityPropertiesFromConfig();

        $output->writeln(sprintf(
            'Re-encrypting database records using %s decryption and %s encryption',
            Crypt::$cryptNames[$this->crypt->getEncryptionMethod()],
            Crypt::$cryptNames[$this->reEncrypt->getEncryptionMethod()])
        );

        $processed = $this->processEntities(self::RE_ENCRYPT);
        if ($processed) {
            $output->writeln('<info>Finished re-encrypting your entities.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>There was a problem re-encrypting your entities.</info>');
        return Command::FAILURE;
    }

    /**
     * Initialize Crypt models
     * @return bool
     * @throws DoctrineCryptException
     */
    private function initCrypt(): bool
    {
        $decrypt = $this->input->getArgument('decrypt');
        $encrypt = $this->input->getArgument('encrypt');

        /** Crypt methods specified in command arguments */
        if ($decrypt && $encrypt) {
            if (!in_array($decrypt, Crypt::$allowedCrypts)) {
                $this->output->writeln(sprintf(
                    '<error>Decryption Method %s is not valid, expecting one of %s.</error>',
                    $decrypt,
                    implode(', ', Crypt::$allowedCrypts))
                );
                return false;
            }
            if (!in_array($encrypt, Crypt::$allowedCrypts)) {
                $this->output->writeln(sprintf(
                    '<error>Encryption Method %s is not valid, expecting one of %s.</error>',
                    $encrypt,
                    implode(', ', Crypt::$allowedCrypts))
                );
                return false;
            }
            if ($encrypt === $decrypt) {
                $this->output->writeln('<error>Encryption and decryption methods cannot be the same.</error>');
                return false;
            }

            return $this->setCrypt($decrypt, $encrypt);
        }

        /** Need both encrypt and decrypt arguments to specify crypt methods, only one set */
        if ($decrypt || $encrypt) {
            $this->output->writeln('<error>You must specify both decrypt and encrypt methods to manually re-encrypt your database.</error>');
            $this->output->writeln('For more information see <https://www.freedomwebservices.net/laminas/fws-doctrine-crypt#command-line-re-encrypt>https://www.freedomwebservices.net/laminas/fws-doctrine-crypt#command-line-re-encrypt</>');
            return false;
        }

        /** Determine decryption and re-encryption methods */
        $encrypt = $this->config['doctrineCrypt']['encryptionMethod'] ?? null;
        if (!$encrypt) {
            $this->output->writeln('<error>encryptionMethod key not set in config.</error>');
            return false;
        }
        if (!in_array($encrypt, Crypt::$allowedCrypts)) {
            $this->output->writeln(sprintf(
                    '<error>Encryption Method %s is not valid, expecting one of %s.</error>',
                    $encrypt,
                    implode(', ', Crypt::$allowedCrypts))
            );
            return false;
        }

        $decrypt = $encrypt === Crypt::CRYPT_BLOCK_CIPHER ? Crypt::CRYPT_RSA : Crypt::CRYPT_BLOCK_CIPHER;
        return $this->setCrypt($decrypt, $encrypt);
    }

    /**
     * Set Crypt models
     * @param string $decrypt
     * @param string $encrypt
     * @return bool
     * @throws DoctrineCryptException
     */
    private function setCrypt(string $decrypt, string $encrypt): bool
    {
        $config = $this->config['doctrineCrypt'] ?? null;
        if ($config === null) {
            $this->output->writeln('<error>doctrineCrypt key not set in config</error>');
            return false;
        }
        $config['encryptionMethod'] = $decrypt;
        $this->crypt = new Crypt($this->entityManager, ['doctrineCrypt' => $config]);
        $config['encryptionMethod'] = $encrypt;
        $this->reEncrypt = new Crypt($this->entityManager, ['doctrineCrypt' => $config]);
        return true;
    }
}