<?php

namespace FwsDoctrineCrypt\Model;


use Closure;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Mapping\EncryptedField as MappedEncryptedField;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\Contract\BackendInterface;
use ParagonIE\CipherSweet\Contract\TransformationInterface;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Exception\CipherSweetException;
use ParagonIE\CipherSweet\Exception\CryptoOperationException;
use ParagonIE\CipherSweet\KeyProvider\StringProvider;
use ReflectionException;
use ReflectionProperty;
use SodiumException;
use TypeError;

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

    private ?EntityManagerInterface $entityManager = null;

    /**
     * The entity manager is resolved on first use. The subscriber depends on this class and is created
     * while the entity manager itself is being built, so asking for it here would never finish.
     *
     * @param Closure(): EntityManagerInterface $entityManagerProvider
     * @param EntityAttributes $entityAttributes
     * @param array $config
     */
    public function __construct(
        private readonly Closure   $entityManagerProvider,
        protected EntityAttributes $entityAttributes,
        protected array            $config
    )
    {
    }

    private function getMetadata(string $entityClassName): ClassMetadata
    {
        $this->entityManager ??= ($this->entityManagerProvider)();

        return $this->entityManager->getClassMetadata($entityClassName);
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

        $engine = $config['engine'] ?? null;
        if ($engine) {
            if (!is_a($engine, BackendInterface::class, true)) {
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
     * Names of the mapped entity properties that are stored encrypted
     * @return string[]
     * @throws DoctrineCryptException
     */
    public function getEncryptedProperties(string $entityClassName): array
    {
        $metadata = $this->getMetadata($entityClassName);

        return array_values(array_filter(
            array_keys($this->getAttributes($metadata->getName())),
            static fn(string $property): bool => isset($metadata->fieldMappings[$property])
        ));
    }

    /**
     * Decrypt all encrypted property values in the given entity. Null stays null.
     * Every other value must be valid ciphertext, anything else throws. Use decryptEncrypted() for partly migrated data.
     * @throws CryptoOperationException
     * @throws CipherSweetException
     * @throws DoctrineCryptException
     * @throws SodiumException
     */
    public function decrypt(object $entity): object
    {
        $values = [];
        foreach ($this->getEncryptedProperties($entity::class) as $property) {
            $value = $this->getPropertyValue($entity, $property);
            if ($value !== null) {
                $values[$property] = $value;
            }
        }

        return $this->decryptProperties($entity, $values);
    }

    /**
     * Encrypt the given plaintext values for an entity class, without changing any entity.
     * Values are always encrypted, whatever they look like. Null stays null and gives null blind indexes,
     * as CipherSweet would otherwise index it as an empty string.
     * @param array<string, mixed> $values Plaintext values keyed by property name
     * @return array{0: array<string, string|null>, 1: array<string, string|null>} Ciphertext and blind indexes, both keyed by property name
     * @throws CipherSweetException
     * @throws CryptoOperationException
     * @throws DoctrineCryptException
     * @throws SodiumException
     */
    public function encryptValues(string $entityClassName, array $values): array
    {
        $encrypted = [];
        $indexes = [];

        $present = array_filter($values, static fn(mixed $value): bool => $value !== null);
        if ($present) {
            try {
                [$encrypted, $indexes] = $this
                    ->getEncryptedRow($entityClassName, array_keys($present))
                    ->prepareRowForStorage($present);
            } catch (TypeError $e) {
                throw new DoctrineCryptException($e->getMessage(), 0, $e);
            }
        }

        $metadata = $this->getMetadata($entityClassName);
        $attributes = $this->getAttributes($metadata->getName());
        foreach (array_diff_key($values, $present) as $property => $value) {
            $encrypted[$property] = null;
            foreach ($attributes[$property]->getIndexes() as $index) {
                $indexes[$index->getProperty()] = null;
            }
        }

        return [$encrypted, $indexes];
    }

    /**
     * Encrypt only the entity's property values that are not already encrypted. For migrating existing data.
     * @throws CipherSweetException
     * @throws CryptoOperationException
     * @throws DoctrineCryptException
     * @throws SodiumException
     */
    public function encryptPlaintext(object $entity): object
    {
        $values = [];
        foreach ($this->getEncryptedProperties($entity::class) as $property) {
            $value = $this->getPropertyValue($entity, $property);
            if (!$this->isEncryptedValue($entity::class, $property, $value)) {
                $values[$property] = $this->toFieldType($entity::class, $property, $value);
            }
        }

        [$encrypted, $indexes] = $this->encryptValues($entity::class, $values);
        foreach ($encrypted + $indexes as $property => $value) {
            $this->setPropertyValue($entity, $property, $value);
        }

        return $entity;
    }

    /**
     * Decrypt only the entity's property values that are encrypted. For migrating existing data.
     * Values become strings as they are going back into a text column. Blind indexes describe encrypted
     * values, so they are cleared too, unless their column does not allow null.
     * @throws CipherSweetException
     * @throws CryptoOperationException
     * @throws DoctrineCryptException
     * @throws SodiumException
     */
    public function decryptEncrypted(object $entity): object
    {
        $values = [];
        foreach ($this->getEncryptedProperties($entity::class) as $property) {
            $value = $this->getPropertyValue($entity, $property);
            if ($this->isEncryptedValue($entity::class, $property, $value)) {
                $values[$property] = $value;
            }
        }

        $this->decryptProperties($entity, $values);

        $metadata = $this->getMetadata($entity::class);
        $attributes = $this->getAttributes($metadata->getName());
        foreach (array_keys($values) as $property) {
            $value = $this->getPropertyValue($entity, $property);
            $this->setPropertyValue($entity, $property, is_bool($value) ? ($value ? '1' : '0') : (string)$value);

            foreach ($attributes[$property]->getIndexes() as $index) {
                $indexProperty = $index->getProperty();
                if ($metadata->hasField($indexProperty) && $metadata->isNullable($indexProperty)) {
                    $this->setPropertyValue($entity, $indexProperty, null);
                }
            }
        }

        return $entity;
    }

    /**
     * Convert a stored or submitted string to the type declared by the property's EncryptedField attribute.
     * A value that cannot be converted is returned as it is, for CipherSweet to reject.
     * @throws DoctrineCryptException
     */
    private function toFieldType(string $entityClassName, string $property, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $attributes = $this->getAttributes($this->getMetadata($entityClassName)->getName());

        return match ($attributes[$property]->getType()) {
            Types::INTEGER => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value,
            Types::FLOAT => is_numeric($value) ? (float)$value : $value,
            Types::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value,
            default => $value,
        };
    }

    /**
     * Whether a stored value is ciphertext.
     *
     * A value with the ciphertext header that does not decrypt throws rather than counting as plaintext.
     * Otherwise running a migration with the wrong key would encrypt existing ciphertext a second time.
     *
     * @throws CryptoOperationException
     * @throws DoctrineCryptException
     */
    private function isEncryptedValue(string $entityClassName, string $property, mixed $value): bool
    {
        $header = $this->getEngine()->getBackend()->getPrefix();
        if (!is_string($value) || !str_starts_with($value, $header)) {
            return false;
        }

        try {
            $this->getEncryptedRow($entityClassName, [$property])->decryptRow([$property => $value]);
        } catch (CipherSweetException|SodiumException $e) {
            throw new DoctrineCryptException(
                sprintf(
                    '%s::$%s starts with the ciphertext header but could not be decrypted. '
                    . 'Check the encryption key, or whether the value is plaintext that starts with "%s".',
                    $entityClassName,
                    $property,
                    $header
                ),
                0,
                $e
            );
        }

        return true;
    }

    /**
     * @param array<string, string> $values Ciphertext keyed by property name
     * @throws CipherSweetException
     * @throws CryptoOperationException
     * @throws DoctrineCryptException
     * @throws SodiumException
     */
    private function decryptProperties(object $entity, array $values): object
    {
        if (!$values) {
            return $entity;
        }

        $decrypted = $this->getEncryptedRow($entity::class, array_keys($values))->decryptRow($values);
        foreach ($decrypted as $property => $value) {
            $this->setPropertyValue($entity, $property, $value);
        }

        return $entity;
    }

    /**
     * Get an EncryptedRow covering only the given properties.
     * EncryptedRow requires a value for every field it knows about, so each combination needs its own.
     * @param string[] $properties
     * @throws CryptoOperationException
     * @throws DoctrineCryptException
     */
    private function getEncryptedRow(string $entityClassName, array $properties): EncryptedRow
    {
        $metadata = $this->getMetadata($entityClassName);

        sort($properties);
        $cacheKey = $metadata->getName() . '|' . implode(',', $properties);
        if (array_key_exists($cacheKey, $this->encryptedRow)) {
            return $this->encryptedRow[$cacheKey];
        }

        $encryptedRow = new EncryptedRow($this->getEngine(), $metadata->getName());
        $attributes = $this->getAttributes($metadata->getName());
        foreach ($properties as $property) {
            $this->addFields($encryptedRow, $property, $metadata->fieldMappings[$property]['type'] ?? null, $attributes[$property]);
            $this->addIndexes($encryptedRow, $metadata->getName(), $property, $attributes);
        }

        return $this->encryptedRow[$cacheKey] = $encryptedRow;
    }

    private function getPropertyValue(object $entity, string $property): mixed
    {
        return $this->getMetadata($entity::class)->getFieldValue($entity, $property);
    }

    /**
     * @throws ReflectionException
     */
    private function setPropertyValue(object $entity, string $property, mixed $value): void
    {
        $metadata = $this->getMetadata($entity::class);
        if ($metadata->hasField($property)) {
            $metadata->setFieldValue($entity, $property, $value);
            return;
        }

        (new ReflectionProperty($metadata->getName(), $property))->setValue($entity, $value);
    }

    /**
     * Invalid attributes throw. Treating them as "no attributes" would store the entity unencrypted.
     * @throws DoctrineCryptException
     */
    protected function getAttributes(string $entityClassName): array
    {
        try {
            return $this->entityAttributes->getAttributes($entityClassName);
        } catch (ReflectionException $e) {
            throw new DoctrineCryptException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Add the EncryptedRow objects field for the specified property.
     * Ciphertext is text, so the column has to be a text type whatever the decrypted value is. Any other
     * column type throws, as it could not hold the value or Doctrine would corrupt it when reading.
     * @throws DoctrineCryptException
     */
    protected function addFields(
        EncryptedRow         $encryptedRow,
        string               $propertyName,
        ?string              $columnType,
        MappedEncryptedField $attribute
    ): EncryptedRow
    {
        if (!in_array($columnType, [Types::STRING, Types::ASCII_STRING, Types::TEXT], true)) {
            throw new DoctrineCryptException(sprintf(
                'Property %s has Doctrine type %s, which cannot hold ciphertext. Map the column as %s, %s or %s '
                . 'and set the type of the decrypted value on the EncryptedField attribute',
                $propertyName,
                $columnType ?? '(none)',
                Types::STRING,
                Types::ASCII_STRING,
                Types::TEXT
            ));
        }

        switch ($attribute->getType()) {
            case Types::TEXT:
                $encryptedRow->addTextField($propertyName);
                break;
            case Types::INTEGER:
                $encryptedRow->addIntegerField($propertyName);
                break;
            case Types::FLOAT:
                $encryptedRow->addFloatField($propertyName);
                break;
            case Types::BOOLEAN:
                $encryptedRow->addBooleanField($propertyName);
                break;
            default:
                throw new DoctrineCryptException(sprintf(
                    'Property %s has encrypted field type %s, expected one of %s, %s, %s or %s',
                    $propertyName,
                    $attribute->getType(),
                    Types::TEXT,
                    Types::INTEGER,
                    Types::FLOAT,
                    Types::BOOLEAN
                ));
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
     * Return the blind index for a value of the specified entity class and property, for use in a search.
     * Without an index name all of the property's indexes are returned, keyed by index name.
     * A property that is not encrypted throws, so that a search never sends the plaintext to the database.
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
        if (!in_array($propertyName, $this->getEncryptedProperties($entityClassName), true)) {
            throw new DoctrineCryptException(sprintf('%s::$%s is not an encrypted property', $entityClassName, $propertyName));
        }

        if ($value === null) {
            return null;
        }

        $encryptedRow = $this->getEncryptedRow($entityClassName, [$propertyName]);
        $row = [$propertyName => $this->toFieldType($entityClassName, $propertyName, $value)];

        try {
            return $indexName ? $encryptedRow->getBlindIndex($indexName, $row) : $encryptedRow->getAllBlindIndexes($row);
        } catch (TypeError $e) {
            throw new DoctrineCryptException($e->getMessage(), 0, $e);
        }
    }

    /**
     * An invalid class throws, as leaving it out would silently produce different blind indexes.
     * @param string[] $transformationClasses
     * @return TransformationInterface[]
     * @throws DoctrineCryptException
     */
    protected function instantiateTransformationClasses(array $transformationClasses): array
    {
        $transformationObjects = [];
        foreach ($transformationClasses as $transformationClass) {
            if (!is_a($transformationClass, TransformationInterface::class, true)) {
                throw new DoctrineCryptException(sprintf(
                    '%s is not a CipherSweet transformation class',
                    $transformationClass
                ));
            }

            $transformationObjects[] = new $transformationClass();
        }

        return $transformationObjects;
    }
}
