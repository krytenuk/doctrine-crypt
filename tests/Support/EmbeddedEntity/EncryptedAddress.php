<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\EmbeddedEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

#[ORM\Embeddable]
class EncryptedAddress
{
    #[ORM\Column(type: 'string')]
    #[EncryptedField]
    public string $city = 'Leeds';
}
