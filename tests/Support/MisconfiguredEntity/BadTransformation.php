<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\MisconfiguredEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

use FwsDoctrineCrypt\Mapping\IndexableField;

#[ORM\Entity]
#[ORM\Table(name: 'bad_transformation')]
class BadTransformation
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[EncryptedField]
    #[IndexableField(name: 'secret', property: 'secretIndex', transformationClasses: [\stdClass::class])]
    public string $secret = 'secret';

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $secretIndex = null;
}
