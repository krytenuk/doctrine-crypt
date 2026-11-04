<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\EmbeddedEntity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class PlainAddress
{
    #[ORM\Column(type: 'string')]
    public string $city = 'Leeds';
}
