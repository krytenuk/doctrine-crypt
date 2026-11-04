<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\EmbeddedEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Has no encrypted fields of its own, so only the embeddable shows that it needs encrypting.
 */
#[ORM\Entity]
#[ORM\Table(name: 'holder')]
class Holder
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Embedded(class: EncryptedAddress::class)]
    public EncryptedAddress $address;

    public function __construct()
    {
        $this->address = new EncryptedAddress();
    }
}
