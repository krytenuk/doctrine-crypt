<?php

namespace FwsDoctrineCrypt\Command;

use Doctrine\Laminas\Hydrator\DoctrineObject;
use Doctrine\ORM\EntityManagerInterface;
use FwsDoctrineCrypt\Model\Crypt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ORM\EntityRepository;
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
    const BATCH_SIZE = 20;

    protected InputInterface $input;
    protected OutputInterface $output;

    protected DoctrineObject $hydrator;
    protected array $entities;

    /**
     *
     * @param EntityManagerInterface $entityManager
     * @param Crypt $crypt
     */
    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected Crypt $crypt
    )
    {
        $this->hydrator = new DoctrineObject($this->entityManager);
        $this->entities = $this->crypt->getEntityPropertiesFromConfig();
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
     * Write to console if in verbose mode (-v, -vv or -vvv)
     * @param array|string $message
     * @return void
     */
    protected function verboseOutput(array|string $message): void
    {
        if ($this->input->getOption('verbose')) {
            $this->output->writeln($message);
        }
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
        /** Get entities to process */
        if (!$this->entities) {
            $this->output->writeln('<error>No entities found in config</error>');
            return false;
        }

        if (!$this->input->getOption('dry-run')) {
            $this->output->writeln([
                '<warning>This will change the database records for the entities in your configuration</warning>',
                '<warning>Please ensure you have a backup before continuing</warning>'
            ]);

            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to continue?', false);

            if (!$helper->ask($this->input, $this->output, $question)) {
                return true;
            }
        }

        foreach ($this->entities as $entityClass => $entityProperties) {

            if (!$entityProperties) {
                $this->output->writeln("<error>No properties specified for entity $entityClass in config</error>");
                continue;
            }

            $repository = $this->getRepository($entityClass);
            if ($repository === null) {
                $this->output->writeln("<error>Entity repository for $entityClass not found.</error>");
                continue;
            }

            $this->output->writeln("Processing entity $entityClass");
            /** Create Doctrine query to retrieve records for entity class */
            $queryBuilder = $this->entityManager->createQueryBuilder();
            $query = $queryBuilder->select('t')
                ->from($entityClass, 't')
                ->getQuery();

            $total = 0;
            $count = 1;
            /**
             * Process in batches through iterator to avoid memory allocation errors when processing large datasets
             * @see https://www.doctrine-project.org/projects/doctrine-orm/en/2.14/reference/batch-processing.html#iterating-results
             */
            foreach ($query->toIterable() as $entity) {
                $properties = $this->getProperties($entity, $entityProperties);
                if (!$properties) {
                    $this->verboseOutput('No properties found for entity');
                    continue;
                }
                $toHydrate = $this->processProperties($properties, $method);
                if (!$toHydrate) {
                    $this->verboseOutput('No properties to update in entity');
                    continue;
                }
                $total++;
                if (!$this->input->getOption('dry-run')) {
                    $this->hydrator->hydrate($toHydrate, $entity);
                }

                if ((++$count % self::BATCH_SIZE) === 0) {
                    if ($this->input->getOption('dry-run')) {
                        $this->entityManager->flush();
                    }
                    $this->entityManager->clear();
                }
            }
            if ($this->input->getOption('dry-run')) {
                $this->output->writeln("<comment>Dry run, records not updated on database</comment>");
            } else {
                $this->entityManager->flush();
            }
            $this->entityManager->clear();

            $this->output->writeln("<info>Processed $total records for entity class $entityClass</info>");
        }

        return true;
    }

    private function processProperties(array $properties, string $method): ?array
    {
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
            $returnProperties[$name] = $processedValue;
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
