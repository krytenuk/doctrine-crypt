<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping\EncryptedField;
use FwsDoctrineCrypt\Mapping\IndexableField;

/**
 * Properties are private on purpose: a lazy proxy subclass cannot see them through reflection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'person')]
class Person
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[EncryptedField]
    #[IndexableField(name: 'email_index', filterBits: 32, property: 'emailIndex')]
    private string $email;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $emailIndex = null;

    #[ORM\Column(type: 'string')]
    #[EncryptedField(indexable: false)]
    private string $phone;

    #[ORM\Column(type: 'string')]
    private string $name = 'name';

    public function __construct(string $email = 'person@example.com', string $phone = '01234 567890')
    {
        $this->email = $email;
        $this->phone = $phone;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getEmailIndex(): ?string
    {
        return $this->emailIndex;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): void
    {
        $this->phone = $phone;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
