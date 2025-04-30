<?php

namespace FwsDoctrineCrypt\Command;

use Doctrine\Laminas\Hydrator\DoctrineObject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Exception;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\EntityAttributes;
use ParagonIE\CipherSweet\Exception\CipherSweetException;
use ParagonIE\CipherSweet\Exception\CryptoOperationException;
use ReflectionException;
use SodiumException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * AbstractCommand
 *
 * @author Garry Childs <info@freedomwebservices.net>
 */
abstract class AbstractCommand extends Command
{
    const ENCRYPT = 'encrypt';
    const DECRYPT = 'decrypt';
    const RE_ENCRYPT = 're-encrypt';
    const BATCH_SIZE = 20;

    protected InputInterface $input;
    protected OutputInterface $output;

    protected DoctrineObject $hydrator;
    protected array $entities;
    protected ?Crypt $reEncrypt = null;

    /**
     *
     * @param EntityManagerInterface $entityManager
     * @param EntityAttributes $entityAttributes
     * @param Crypt $crypt
     */
    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected EntityAttributes $entityAttributes,
        protected Crypt $crypt
    )
    {
        $this->hydrator = new DoctrineObject($this->entityManager);
        parent::__construct();
    }

    /**
     * Set input and output interfaces
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function init(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;

        $outputStyle = new OutputFormatterStyle('red', null, ['bold']);
        $output->getFormatter()->setStyle('warning', $outputStyle);
    }

    /**
     * Get entity repository
     * @param string $entityName
     * @return EntityRepository|null
     */
    protected function getRepository(string $entityName): ?EntityRepository
    {
        if (class_exists($entityName)) {
            $repository = $this->entityManager->getRepository($entityName);
            if ($repository instanceof EntityRepository) {
                return $repository;
            }
        }
        return null;
    }

    /** Process entity encryption/decryption
     * @param string $method
     * @return bool
     */
    protected function processEntities(string $method): bool
    {
        /**
         * @var string[] $entitiesClassNames
         */
        $entitiesClassNames = $this->entityManager->getConfiguration()->getMetadataDriverImpl()->getAllClassNames();

        $dryRun = $this->input->getOption('dry-run');
        if ($dryRun) {
            $this->output->writeln('Dry run option set, no records will be changed');
        } else {
            $this->output->writeln([
                '<warning>This will change the database records for the entities in your configuration</warning>',
                '<warning>Please ensure you have a backup before continuing</warning>'
            ]);
            /**
             * Confirm action with user
             * @var QuestionHelper $helper
             */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to continue?', false);

            if (!$helper->ask($this->input, $this->output, $question)) {
                return true;
            }
        }

        $cursor = new Cursor($this->output);
        /**
         * Loop through entities from config
         */
        foreach ($entitiesClassNames as $entityClassName) {

            /** Get doctrine crypt attributes for entity */
            $attributes = $this->getAttributes($entityClassName);
            if (!$attributes) {
                $this->output->writeln("<info>No crypt attributes found for $entityClassName</info>");
                continue;
            }

            $repository = $this->getRepository($entityClassName);
            if ($repository === null) {
                $this->output->writeln("<error>Entity repository for $entityClassName not found.</error>");
                continue;
            }

            $this->output->writeln("Processing entity $entityClassName");
            /** Create Doctrine query to retrieve records for entity class */
            $queryBuilder = $this->entityManager->createQueryBuilder();
            try {
                $total = $queryBuilder
                    ->select($queryBuilder->expr()->count('c'))
                    ->from($entityClassName, 'c')
                    ->getQuery()
                    ->getSingleScalarResult();
            } catch (NoResultException) {
                $this->output->write("No records found for entity $entityClassName");
                continue;
            } catch (NonUniqueResultException) {
                continue;
            }

            $query = ($this->entityManager->createQueryBuilder())
                ->select('t')
                ->from($entityClassName, 't')
                ->getQuery();

            $count = 1;
            /**
             * Process in batches through iterator to avoid memory allocation errors when processing large datasets
             * @see https://www.doctrine-project.org/projects/doctrine-orm/en/2.14/reference/batch-processing.html#iterating-results
             */
            foreach ($query->toIterable() as $entity) {
                try {
                    $this->crypt->$method($entity);
                    $cursor->clearLine()->moveToColumn(0);
                    $this->output->write("Processing entity $entityClassName ($count of $total)");
                } catch (Exception $e) {
                    $message = $e->getMessage();
                    $cursor->clearLine()->moveToColumn(0);
                    $this->output->writeln("<error>Encryption failed: $message</error>");
                    $count++;
                    continue;
                }

                if (($count++ % self::BATCH_SIZE) === 0) {
                    /** Not dry run, save entity batch */
                    if (!$dryRun) {
                        $this->entityManager->flush();
                    }
                    $this->entityManager->clear();
                }
            }

            /** Not dry run, save final entities */
            if (!$dryRun) {
                $this->entityManager->flush();
            }

            $this->output->writeln(PHP_EOL . "<info>Processed $total records for entity class $entityClassName</info>");
        }

        return true;
    }

    protected function getAttributes(string $entityName): ?array
    {
        if (!class_exists($entityName)) {
            return null;
        }

        try {
            return $this->entityAttributes->getAttributes($entityName);
        } catch (DoctrineCryptException|ReflectionException) {
            return null;
        }
    }

    /**
     * @param array $properties
     * @param string $method
     * @return array|null
     */
    private function processProperties(array $properties, string $method): ?array
    {
        $reEncrypt = false;
        if ($method === self::RE_ENCRYPT) {
            $reEncrypt = true;
            $method = self::DECRYPT;
        }
        if ($reEncrypt && ($this->reEncrypt ?? null) === null) {
            $this->output->writeln('<error>Re-encryption object not set</error>');
            return null;
        }

        if (!method_exists($this->crypt, $method)) {
            $this->output->writeln(sprintf('Method %s not found in %s', $method, $this->crypt::class));
            return null;
        }

        $returnProperties = [];
        foreach ($properties as $name => $value) {
            if (!$value) {
                $returnProperties[$name] = $value;
                continue;
            }
            if ($method === self::ENCRYPT) {
                if ($this->crypt->isEncrypted($value)) {
                    continue;
                }
            }
            $processedValue = $this->crypt->$method((string) $value) ?? $value;
            $returnProperties[$name] = ($reEncrypt ? ($this->reEncrypt->encrypt($processedValue) ?? $processedValue) : $processedValue);
        }
        return $returnProperties;
    }

    private function getProperties(object $entity, array $properties): array
    {
        $returnProperties = [];

        $entityProperties = $this->hydrator->extract($entity);
        if (!$entityProperties) {
            return $returnProperties;
        }

        foreach ($properties as $propertyName) {
            if (!array_key_exists($propertyName, $entityProperties)) {
                continue;
            }
            $returnProperties[$propertyName] = $entityProperties[$propertyName];
        }

        return $returnProperties;
    }

}
