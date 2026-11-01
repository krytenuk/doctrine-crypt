<?php

namespace FwsDoctrineCrypt\Command\Service;

use Doctrine\ORM\EntityManagerInterface;
use FwsDoctrineCrypt\Command\AbstractCommand;
use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\EntityAttributes;
use Psr\Container\ContainerInterface;

/**
 * Creates the encrypt and decrypt commands, which share a constructor
 */
class MigrationCommandFactory
{
    /**
     * @param class-string<AbstractCommand> $requestedName
     */
    public function __invoke(ContainerInterface $container, string $requestedName): AbstractCommand
    {
        $config = $container->get('config');

        return new $requestedName(
            $container->get($config['doctrine-crypt']['entity_manager'] ?? EntityManagerInterface::class),
            $container->get(EntityAttributes::class),
            $container->get(Crypt::class)
        );
    }
}
