<?php

namespace FwsDoctrineCrypt\Model;


use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use Laminas\Cache\Exception\ExceptionInterface;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\Contract\BackendInterface;
use ParagonIE\CipherSweet\Contract\TransformationInterface;
use ParagonIE\CipherSweet\EncryptedField;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Exception\BlindIndexNameCollisionException;
use ParagonIE\CipherSweet\Exception\CipherSweetException;
use ParagonIE\CipherSweet\Exception\CryptoOperationException;
use ParagonIE\CipherSweet\Exception\InvalidCiphertextException;
use ParagonIE\CipherSweet\FastBlindIndex;
use ParagonIE\CipherSweet\KeyProvider\StringProvider;
use ParagonIE\CipherSweet\Transformation\LastFourDigits;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use FwsDoctrineCrypt\Mapping\EncryptedField as MappedEncryptedField;
use ReflectionProperty;
use SodiumException;

/**
 * Crypt class
 * Performs encryption/decryption of data
 *
 * @author Garry Childs <info@freedomwebservices.net>
 *
 */
class Crypt
{
    private CipherSweet|null $engine = null;

    /**
     * @var EncryptedRow[]
     */
    private array $encryptedRow = [];

    /**
     * @var ReflectionClass[]
     */
    private array $reflectionEntity = [];

    /**
     *
     * @param EntityManager $entityManager
     * @param EntityAttributes $entityAttributes
     * @param array $config
     */
    public function __construct(
        protected EntityManager    $entityManager,
        protected EntityAttributes $entityAttributes,
        protected array            $config
    )
    {
    }

    /**
     * Get the CipherSweet engine
     * @throws DoctrineCryptException
     * @throws CryptoOperationException
     */
    public function getEngine(): CipherSweet
    {
        if ($this->engine) {
            return $this->engine;
        }

        $config = $this->config['doctrine-crypt']['cipherSweet'] ?? null;
        if (!$config) {
            throw new DoctrineCryptException('cipherSweet configuration is not set');
        }

        $engine = $this->config['engine'] ?? null;
        if ($engine) {
            if (!class_implements($engine, BackendInterface::class)) {
                throw new DoctrineCryptException(sprintf('Class %s is not a valid CipherSweet engine', $engine));
            }

            $engine = new $engine();
        }

        $key = (string)($config['encryptionKey'] ?? '');
        if (!$key) {
            throw new DoctrineCryptException('encryptionKey config key is not set');
        }

        $this->engine = new CipherSweet(new StringProvider($key), $engine);

        return $this->engine;
    }

    /**
     * Decrypt all property values in the given entity using the entities FWS Doctrine Crypt attributes
     * @throws CryptoOperationException
     * @throws CipherSweetException
     * @throws DoctrineCryptException
     * @throws ReflectionException
     * @throws SodiumException
     */
    public function decrypt(object $entity): object
    {
        $values = $this->prepEntity($entity, __FUNCTION__);
        if ($values === null) {
            return $entity;
        }

        try {
            $decrypted = $this->encryptedRow[$entity::class]->decryptRow($values);
        } catch (InvalidCiphertextException) {
            $decrypted = $values;
        }

        foreach ($decrypted as $propertyName => $value) {
            $this->reflectionEntity[$entity::class]->getProperty($propertyName)->setValue($entity, $value);
        }

        return $entity;
    }

    /**
     * @throws CipherSweetException
     * @throws CryptoOperationException
     * @throws ReflectionException|DoctrineCryptException
     * @throws SodiumException
     */
    public function encrypt(object $entity): object
    {
        $values = $this->prepEntity($entity, __FUNCTION__);
        if ($values === null) {
            return $entity;
        }

        if (empty($values)) {
            throw new DoctrineCryptException("Entity record is already encrypted");
        }

        $encryptedRow = $this->encryptedRow[$entity::class];

        $encrypted = $encryptedRow->prepareRowForStorage($values);

        if (count($encrypted) !== 2) {
            return $entity;
        }

        foreach (($encrypted[0] ?? []) as $propertyName => $value) {
            $this->reflectionEntity[$entity::class]->getProperty($propertyName)->setValue($entity, $value);
        }

        foreach (($encrypted[1] ?? []) as $indexProperty => $encryptedIndex) {
            $this->reflectionEntity[$entity::class]->getProperty($indexProperty)->setValue($entity, $encryptedIndex);
        }

        return $entity;
    }

    /**
     * @throws DoctrineCryptException
     * @throws CryptoOperationException
     */
    protected function prepEntity(object $entity, string $method): ?array
    {
        $attributes = $this->getAttributes($entity::class);
        if (!$attributes) {
            return null;
        }

        $entityMetadata = $this->entityManager->getClassMetadata($entity::class);

        $this->reflectionEntity[$entity::class] = new ReflectionClass($entity);
        $entityProperties = $this->reflectionEntity[$entity::class]->getProperties();

        if (!array_key_exists($entity::class, $this->encryptedRow)) {
            $this->encryptedRow[$entity::class] = new EncryptedRow($this->getEngine(), $this->entityManager->getClassMetadata($entity::class)->getName());
        }

        $magicHeader = $this->encryptedRow[$entity::class]->getBackend()->getPrefix();

        $values = [];
        foreach ($entityProperties as $reflectionProperty) {
            if (!array_key_exists($reflectionProperty->getName(), $attributes)) {
                continue;
            }

            $reflectionPropertyName = $reflectionProperty->getName();
            $reflectionPropertyValue = $reflectionProperty->getValue($entity);

            /**
             * Check if value is already encrypted
             */
            if (str_starts_with($reflectionPropertyValue, $magicHeader) && $method === 'encrypt') {
                continue;
            }

            $propertyMetadata = $entityMetadata->fieldMappings[$reflectionPropertyName] ?? null;
            if ($propertyMetadata === null) {
                continue;
            }

            $this->addFields(
                $this->encryptedRow[$entity::class],
                $reflectionPropertyName,
                $propertyMetadata['type'] ?? null
            );

            $this->addIndexes(
                $this->encryptedRow[$entity::class],
                $entity::class,
                $reflectionPropertyName,
                $attributes,
            );

            $values[$reflectionPropertyName] = $reflectionPropertyValue;
        }

        return $values;
    }

    protected function getAttributes(string $entityClassName): array|null
    {
        try {
            return $this->entityAttributes->getAttributes($entityClassName);
        } catch (DoctrineCryptException|ReflectionException) {
            return null;
        }
    }

    /**
     * Add the EncryptedRow objects fields for the specified property name and type
     */
    protected function addFields(
        EncryptedRow $encryptedRow,
        string       $propertyName,
        ?string      $propertyType
    ): EncryptedRow
    {
        if ($propertyType === null) {
            return $encryptedRow;
        }

        if (Type::hasType($propertyType) === false) {
            return $encryptedRow;
        }

        switch ($propertyType) {
            case Types::SMALLINT:
            case Types::BIGINT:
            case Types::INTEGER:
                $encryptedRow->addIntegerField($propertyName);
                break;
            case Types::DECIMAL:
            case Types::FLOAT:
                $encryptedRow->addFloatField($propertyName);
                break;
            case Types::STRING:
            case Types::ASCII_STRING:
            case Types::TEXT:
            case Types::GUID:
            case Types::BINARY:
            case Types::BLOB:
                $encryptedRow->addTextField($propertyName);
                break;
            case Types::BOOLEAN:
                $encryptedRow->addBooleanField($propertyName);
                break;
        }

        return $encryptedRow;
    }

    /**
     * Add indexes to EncryptedRow object using the entity classes #[FWS\IndexableField()] attributes for the specified property name
     * @throws DoctrineCryptException
     */
    protected function addIndexes(
        EncryptedRow $encryptedField,
        string       $entityClassName,
        string       $propertyName,
        array        $attributes,
    ): void
    {
        if (!array_key_exists($propertyName, $attributes)) {
            return;
        }

        /** @var MappedEncryptedField|null $encryptedFieldAttribute */
        $encryptedFieldAttribute = $attributes[$propertyName] ?? null;
        if ($encryptedFieldAttribute === null) {
            return;
        }

        if ($encryptedFieldAttribute->isIndexable()) {
            $indexes = $encryptedFieldAttribute->getIndexes();
            foreach ($indexes as $indexAttribute) {
                if ($indexAttribute->getProperty() === null) {
                    throw new DoctrineCryptException(
                        sprintf('Index property not found in %s::%s', $entityClassName, $propertyName));
                }
                $encryptedField->addBlindIndex(
                    $propertyName,
                    new BlindIndex(
                        $indexAttribute->getProperty(),
                        $this->instantiateTransformationClasses($indexAttribute->getTransformationClasses()),
                        $indexAttribute->getFilterBits(),
                        $indexAttribute->useFastHash()
                    )
                );
            }
        }
    }

    /**
     * Return FWS Doctrine crypt index for specified entity class name and property
     * If property is omitted all class indexes are returned
     * @throws CryptoOperationException
     * @throws CipherSweetException
     * @throws DoctrineCryptException|SodiumException
     */
    public function getIndex(
        string  $entityClassName,
        string  $propertyName,
        mixed   $value,
        ?string $indexName,
    ): mixed
    {
        $entityMetadata = $this->entityManager->getClassMetadata($entityClassName);
        $propertyMetadata = $entityMetadata->fieldMappings[$propertyName] ?? null;

        if ($propertyMetadata === null) {
            return $value;
        }

        $attributes = $this->getAttributes($entityClassName);
        if (!$attributes) {
            return $value;
        }

        $encryptedRow = new EncryptedRow($this->getEngine(), $entityClassName);

        $this->addFields(
            $encryptedRow,
            $propertyName,
            $propertyMetadata['type'] ?? null
        );

        $this->addIndexes(
            $encryptedRow,
            $entityClassName,
            $propertyName,
            $attributes
        );

        if ($indexName) {
            return $encryptedRow->getBlindIndex($indexName, [$propertyName => $value]);
        }

        return $encryptedRow->getAllBlindIndexes([$propertyName => $value]);
    }

    /**
     * @param string[] $transformationClasses
     * @return TransformationInterface[]
     */
    protected function instantiateTransformationClasses(array $transformationClasses): array
    {
        $transformationObjects = [];
        foreach ($transformationClasses as $transformationClass) {
            if (!class_exists($transformationClass)) {
                continue;
            }

            if (!is_a($transformationClass, TransformationInterface::class, true)) {
                continue;
            }

            $transformationObjects[] = new $transformationClass();
        }

        return $transformationObjects;
    }
}
