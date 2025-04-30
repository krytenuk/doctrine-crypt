<?php

namespace FwsDoctrineCrypt\Model;

use FwsDoctrineCrypt\Cache\AttributeCachedItem;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Mapping\EncryptedField;
use FwsDoctrineCrypt\Mapping\IndexableField;
use Laminas\Cache\Exception\ExceptionInterface;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Validator\File\Hash;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

class EntityAttributes
{
    /**
     * Entity property attributes
     */
    private array $attributes = [];

    public function __construct(protected Filesystem $cache)
    {
    }


    /**
     * Return an array of properties and values that are to be encrypted/Decrypted
     * @param string $entityClassName
     * @return array|null
     * @throws DoctrineCryptException
     * @throws ReflectionException
     */
    public function getAttributes(string $entityClassName): ?array
    {
        if (array_key_exists($entityClassName, $this->attributes)) {
            return $this->attributes[$entityClassName];
        }

        $attributes = $this->readFromCache($entityClassName);
        if ($attributes !== null) {
            $this->attributes[$entityClassName] = $attributes;
            return $attributes;
        }

        $reflectionEntity = new ReflectionClass($entityClassName);
        $attributes = [];
        $properties = $reflectionEntity->getProperties();
        foreach ($properties as $property) {
            $encryptedFields = $property->getAttributes(EncryptedField::class);
            if (empty($encryptedFields)) {
                continue;
            }

            if (count($encryptedFields) > 1) {
                throw new DoctrineCryptException(sprintf(
                    'Entity property %s has more than one encrypted field attribute',
                    $entityClassName . '::' . $property->getName()
                ));
            }

            /** @var EncryptedField $encryptedField */
            $encryptedField = array_pop($encryptedFields)?->newInstance();
            foreach ($property->getAttributes(IndexableField::class) as $indexableField) {
                $indexableFieldInstance = $indexableField->newInstance();
                $this->validateIndexAttributeValues($indexableFieldInstance, $reflectionEntity);
                $encryptedField->addIndex($indexableFieldInstance);
            }

            $attributes[$property->getName()] = $encryptedField;
        }

        $this->cacheAttributes($entityClassName, $reflectionEntity->getFileName(), $attributes);

        return $attributes;
    }

    /**
     * @param IndexableField $indexableFieldInstance
     * @param ReflectionClass $entityReflection
     * @return void
     * @throws ReflectionException
     * @throws DoctrineCryptException
     */
    protected function validateIndexAttributeValues(
        IndexableField $indexableFieldInstance,
        ReflectionClass $entityReflection
    ): void
    {
        $indexProperty = $indexableFieldInstance->getProperty();
        if (!$indexProperty) {
            throw new DoctrineCryptException(
                sprintf(
                    'Indexable field %s has no property value',
                    $indexableFieldInstance->getName()
                )
            );
        }

        if (!$entityReflection->hasProperty($indexProperty)) {
            throw new DoctrineCryptException(
                sprintf(
                    'Indexable field %s has property value %s but no property exists in %s',
                    $indexableFieldInstance->getName(),
                    $indexProperty,
                    $entityReflection->getProperty($indexProperty)->getName()));
        }
    }

    protected function readFromCache(string $className): ?array
    {
        try {
            if (!$this->cache->hasItem($className)) {
                return null;
            }

            /** @var AttributeCachedItem $cacheItem */
            $cacheItem = unserialize($this->cache->getItem($className));
            if (!$cacheItem) {
                $this->cache->removeItem($className);
                return null;
            }
            $fileName = $cacheItem->getFilepath();
            if (!is_readable($fileName)) {
                $this->cache->removeItem($className);
                return null;
            }

            $validator = new Hash([
                'hash' => $cacheItem->getHash(),
                'algorithm' => 'sha256',
            ]);

            if ($validator->isValid($fileName)) {
                return $cacheItem->getAttributes();
            }

            $this->cache->removeItem($className);
        } catch (ExceptionInterface) {
        }

        return null;
    }

    protected function cacheAttributes(string $className, string $filepath, array $attributes): void
    {
        $cacheItem = new AttributeCachedItem();
        $cacheItem
            ->setClassName($className)
            ->setFilepath($filepath)
            ->setHash(hash_file('sha256', $filepath))
            ->setAttributes($attributes);
        try {
            $this->cache->addItem($className, serialize($cacheItem));
        } catch (ExceptionInterface) {
        }
    }
}