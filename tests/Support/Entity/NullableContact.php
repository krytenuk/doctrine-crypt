<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;
use FwsDoctrineCrypt\Mapping\IndexableField;

#[ORM\Entity]
#[ORM\Table(name: 'nullable_contact')]
class NullableContact
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[EncryptedField]
    #[IndexableField(name: 'notes', filterBits: 32, property: 'notesIndex')]
    public ?string $notes = null;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $notesIndex = null;
}
