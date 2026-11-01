<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\MisconfiguredEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

#[ORM\Entity]
#[ORM\Table(name: 'integer_column')]
class IntegerColumn
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'integer')]
    #[EncryptedField(indexable: false)]
    public $secret = 42;
}
