<?php

namespace FwsDoctrineCrypt\Filter;

use Exception;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Model\Crypt;
use Laminas\Filter\FilterInterface;
use ParagonIE\CipherSweet\Exception\CipherSweetException;
use ParagonIE\CipherSweet\Exception\CryptoOperationException;

final class CryptIndex implements FilterInterface
{
    protected ?string $property;
    private ?string $entityClassName;
    private ?string $filterName;

    /**
     * @throws DoctrineCryptException
     */
    public function __construct(
        private readonly Crypt $crypt,
        ?array $options = null,
    )
    {
        if ($options === null) {
            throw new DoctrineCryptException('Options must be set');
        }

        $this->entityClassName = $options['entity_class_name'] ?? null;
        if ($this->entityClassName === null) {
            throw new DoctrineCryptException('Entity class name must be set');
        }
        if (!class_exists($this->entityClassName)) {
            throw new DoctrineCryptException('Entity class "' . $this->entityClassName . '" does not exist');
        }

        $this->property = $options['property'] ?? null;
        if ($this->property === null) {
            throw new DoctrineCryptException('Entity property name must be set');
        }

        $this->filterName = $options['filter_name'] ?? null;

    }

    /**
     * @inheritDoc
     */
    public function filter($value): ?string
    {
        try {
            $index = $this->crypt->getIndex($this->entityClassName, $this->property, $value, $this->filterName);
        } catch (Exception $exception) {
            return null;
        }

        if (is_string($index)) {
            return $index;
        }

        if (is_array($index)) {
            return reset($index);
        }

        return null;
    }
}