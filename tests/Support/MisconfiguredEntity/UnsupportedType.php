<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\MisconfiguredEntity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

#[ORM\Entity]
#[ORM\Table(name: 'unsupported_type')]
class UnsupportedType
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[EncryptedField(indexable: false)]
    public $secret;

    public function __construct()
    {
        $this->secret = new DateTimeImmutable('2000-01-01');
    }
}
