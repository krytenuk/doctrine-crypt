<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;
use FwsDoctrineCrypt\Mapping\IndexableField;

use Doctrine\DBAL\Types\Types;

#[ORM\Entity]
#[ORM\Table(name: 'typed_row')]
class TypedValues
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'text')]
        #[EncryptedField(indexable: false, type: Types::INTEGER)]
        public int|string $age,
        #[ORM\Column(type: 'text')]
        #[EncryptedField(indexable: false, type: Types::FLOAT)]
        public float|string $score,
        #[ORM\Column(type: 'text')]
        #[EncryptedField(indexable: false, type: Types::BOOLEAN)]
        public bool|string $active,
    ) {
    }
}
