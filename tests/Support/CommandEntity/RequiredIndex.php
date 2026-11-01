<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\CommandEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

use FwsDoctrineCrypt\Mapping\IndexableField;

#[ORM\Entity]
#[ORM\Table(name: 'required_index')]
class RequiredIndex
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[EncryptedField]
    #[IndexableField(name: 'secret', filterBits: 32, property: 'secretIndex')]
    public string $secret = '';

    #[ORM\Column(type: 'string', nullable: false)]
    public string $secretIndex = '';
}
