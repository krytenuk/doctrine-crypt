<?php

namespace FwsDoctrineCrypt\Mapping;

use Attribute;
use Doctrine\DBAL\Types\Types;

/**
 * Marks a property as stored encrypted.
 *
 * The column must be mapped as a string, ascii_string or text type, because ciphertext is text whatever
 * the value holds. Use $type to encrypt an integer, float or boolean. The property also has to accept
 * a string, as Doctrine assigns the ciphertext to it before it is decrypted:
 *
 *     #[ORM\Column(type: Types::TEXT)]
 *     #[EncryptedField(type: Types::INTEGER)]
 *     private int|string $age;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class EncryptedField
{
    protected array $indexes = [];

    /**
     * Type of the decrypted value: Types::TEXT, Types::INTEGER, Types::FLOAT or Types::BOOLEAN
     */
    protected string $type = Types::TEXT;

    /**
     * @param bool $indexable
     * @param string $type
     */
    public function __construct(
        protected bool $indexable = true,
        string $type = Types::TEXT,
    )
    {
        $this->type = $type;
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

    public function getType(): string
    {
        return $this->type;
    }
}
