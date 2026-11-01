<?php

namespace FwsDoctrineCrypt\Model\Service;

use Doctrine\ORM\EntityManagerInterface;
use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\EntityAttributes;
use Psr\Container\ContainerInterface;

class CryptFactory
{
    public function __invoke(ContainerInterface $container): Crypt
    {
        $config = $container->get('config');
        $entityManagerName = $config['doctrine-crypt']['entity_manager'] ?? EntityManagerInterface::class;

        return new Crypt(
            static fn(): EntityManagerInterface => $container->get($entityManagerName),
            $container->get(EntityAttributes::class),
            $config
        );
    }
}
