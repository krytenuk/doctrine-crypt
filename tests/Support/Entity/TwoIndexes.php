<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;
use FwsDoctrineCrypt\Mapping\IndexableField;

#[ORM\Entity]
#[ORM\Table(name: 'two_indexes')]
class TwoIndexes
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[EncryptedField]
    #[IndexableField(name: 'short', filterBits: 16, property: 'shortIndex')]
    #[IndexableField(name: 'long', filterBits: 32, property: 'longIndex')]
    public string $secret = 'secret';

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $shortIndex = null;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $longIndex = null;
}
