<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\CommandEntity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;

/**
 * The commands must skip this, as it cannot be queried.
 */
#[ORM\MappedSuperclass]
abstract class AbstractBase
{
    #[ORM\Column(type: 'string', nullable: true)]
    #[EncryptedField(indexable: false)]
    protected ?string $inherited = null;
}
