<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\CommandEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

use Doctrine\DBAL\Types\Types;

#[ORM\Entity]
#[ORM\Table(name: 'typed_row')]
class TypedRow
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'text')]
    #[EncryptedField(indexable: false, type: Types::INTEGER)]
    public int|string $age = 0;

    #[ORM\Column(type: 'text')]
    #[EncryptedField(indexable: false, type: Types::FLOAT)]
    public float|string $score = 0.0;

    #[ORM\Column(type: 'text')]
    #[EncryptedField(indexable: false, type: Types::BOOLEAN)]
    public bool|string $active = false;
}
