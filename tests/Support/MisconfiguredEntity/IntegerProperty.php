<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\MisconfiguredEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

#[ORM\Entity]
#[ORM\Table(name: 'integer_property')]
class IntegerProperty
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[EncryptedField(indexable: false, type: 'integer')]
    public int $secret = 42;
}
