<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inherited_secret')]
class InheritedSecret extends SecretBase
{
}
