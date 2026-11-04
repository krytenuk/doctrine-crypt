<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\EmbeddedEntity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nested_holder')]
class NestedHolder
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Embedded(class: Contact::class)]
    public Contact $contact;

    public function __construct()
    {
        $this->contact = new Contact();
    }
}
