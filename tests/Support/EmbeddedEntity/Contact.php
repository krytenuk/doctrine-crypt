<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\EmbeddedEntity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Contact
{
    #[ORM\Embedded(class: EncryptedAddress::class)]
    public EncryptedAddress $address;

    public function __construct()
    {
        $this->address = new EncryptedAddress();
    }
}
