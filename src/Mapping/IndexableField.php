<?php

namespace FwsDoctrineCrypt\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class IndexableField
{

    /**
     * @param string|null $name
     * @param int $filterBits
     * @param string|null $property
     * @param bool $autoRefresh
     * @param array $transformationClasses
     * @param bool $fastIndexing
     * @param bool $fastHash
     */
    public function __construct(
        protected ?string $name = null,
        protected int     $filterBits = 256,
        protected ?string $property = null,
        protected bool    $autoRefresh = true,
        protected array   $transformationClasses = [],
        protected bool    $fastIndexing = true,
        protected bool    $fastHash = false
    )
    {
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getProperty(): ?string
    {
        return $this->property;
    }

    public function getFilterBits(): int
    {
        return $this->filterBits;
    }

    public function isAutoRefresh(): bool
    {
        return $this->autoRefresh;
    }

    public function getTransformationClasses(): array
    {
        return $this->transformationClasses;
    }

    public function useFastIndexing(): bool
    {
        return $this->fastIndexing;
    }

    public function useFastHash(): bool
    {
        return $this->fastHash;
    }



}