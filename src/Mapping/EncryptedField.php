<?php

namespace FwsDoctrineCrypt\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class EncryptedField
{
    protected array $indexes = [];

    /**
     * @param bool $indexable
     */
    public function __construct(
        protected bool $indexable = true,
    )
    {
    }

    public function addIndex(IndexableField $index): void
    {
        $this->indexes[] = $index;
    }

    /**
     * @return IndexableField[]
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    public function isIndexable(): bool
    {
        return $this->indexable;
    }
}