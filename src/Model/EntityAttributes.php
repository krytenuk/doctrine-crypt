<?php

namespace FwsDoctrineCrypt\Model;

use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Mapping\EncryptedField;
use FwsDoctrineCrypt\Mapping\IndexableField;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

class EntityAttributes
{
    /**
     * Entity property attributes
     */
    private array $attributes = [];

    /**
     * Return the encrypted field attributes of an entity class, keyed by property name
     * @param string $entityClassName
     * @return EncryptedField[]
     * @throws DoctrineCryptException
     * @throws ReflectionException
     */
    public function getAttributes(string $entityClassName): array
    {
        if (array_key_exists($entityClassName, $this->attributes)) {
            return $this->attributes[$entityClassName];
        }

        $reflectionEntity = new ReflectionClass($entityClassName);
        $attributes = [];
        foreach ($this->getProperties($reflectionEntity) as $property) {
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

            if (!$this->acceptsString($property->getType())) {
                throw new DoctrineCryptException(sprintf(
                    'Entity property %s has an encrypted field attribute but its type does not accept a string, '
                    . 'which Doctrine needs to assign the ciphertext to it',
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

        return $this->attributes[$entityClassName] = $attributes;
    }

    /**
     * All properties of a class including the private properties of its parents,
     * which ReflectionClass::getProperties() leaves out.
     * @return array<string, ReflectionProperty>
     */
    protected function getProperties(ReflectionClass $class): array
    {
        $properties = [];
        do {
            foreach ($class->getProperties() as $property) {
                $properties[$property->getName()] ??= $property;
            }
        } while ($class = $class->getParentClass());

        return $properties;
    }

    protected function acceptsString(?ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            return in_array($type->getName(), ['string', 'mixed'], true);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($this->acceptsString($unionType)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param IndexableField $indexableFieldInstance
     * @param ReflectionClass $entityReflection
     * @return void
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

        if (!array_key_exists($indexProperty, $this->getProperties($entityReflection))) {
            throw new DoctrineCryptException(
                sprintf(
                    'Indexable field %s has property value %s but no property exists in %s',
                    $indexableFieldInstance->getName(),
                    $indexProperty,
                    $entityReflection->getName()));
        }
    }
}
