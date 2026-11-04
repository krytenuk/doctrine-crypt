<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\EmbeddedEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

#[ORM\Entity]
#[ORM\Table(name: 'plain_holder')]
class PlainHolder
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[EncryptedField]
    public string $secret = 'secret';

    #[ORM\Embedded(class: PlainAddress::class)]
    public PlainAddress $address;

    public function __construct()
    {
        $this->address = new PlainAddress();
    }
}
