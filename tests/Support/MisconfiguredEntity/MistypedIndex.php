<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\MisconfiguredEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;
use FwsDoctrineCrypt\Mapping\IndexableField;

#[ORM\Entity]
#[ORM\Table(name: 'mistyped_index')]
class MistypedIndex
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[EncryptedField]
    #[IndexableField(name: 'secret_index', property: 'noSuchProperty')]
    public string $secret = 'TOP-SECRET';
}
