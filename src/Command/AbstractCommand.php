<?php

namespace FwsDoctrineCrypt\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Listener\DoctrineEntitySubscriber;
use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\EntityAttributes;
use ReflectionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

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

    /**
     * The commands read the stored values and write them straight back, so the subscriber must not
     * decrypt or encrypt anything on the way through.
     */
    private function detachSubscriber(): void
    {
        $eventManager = $this->entityManager->getEventManager();
        foreach ($eventManager->getAllListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof DoctrineEntitySubscriber) {
                    $eventManager->removeEventListener($event, $listener);
                }
            }
        }
    }

    /** Process entity encryption/decryption
     * @param string $method
     * @return bool Whether every record was processed without an error
     */
    protected function processEntities(string $method): bool
    {
        $this->detachSubscriber();

        $failures = 0;

        $dryRun = $this->input->getOption('dry-run');
        if ($dryRun) {
            $this->output->writeln('Dry run option set, no records will be changed');
        } else {
            $this->output->writeln([
                '<warning>This will change the database records for the entities in your configuration</warning>',
                '<warning>Please ensure you have a backup before continuing</warning>'
            ]);
            /**
             * Confirm action with user, unless running with --no-interaction
             * @var QuestionHelper $helper
             */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to continue?', false);

            if ($this->input->isInteractive() && !$helper->ask($this->input, $this->output, $question)) {
                return true;
            }
        }

        $cursor = new Cursor($this->output);
        /**
         * Loop through entities from config
         */
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            $entityClassName = $metadata->getName();

            /** Get doctrine crypt attributes for entity */
            try {
                $attributes = $this->entityAttributes->getAttributes($entityClassName);
                /** An entity whose only encrypted fields are in an embeddable would otherwise be skipped below */
                $this->crypt->getEncryptedProperties($entityClassName);
            } catch (DoctrineCryptException|ReflectionException $e) {
                $this->output->writeln("<error>Invalid crypt attributes for $entityClassName: {$e->getMessage()}</error>");
                $failures++;
                continue;
            }

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
                } catch (Throwable $e) {
                    $message = $e->getMessage();
                    $cursor->clearLine()->moveToColumn(0);
                    $this->output->writeln("<error>Failed to process record $count: $message</error>");
                    $failures++;
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

        return $failures === 0;
    }
}
