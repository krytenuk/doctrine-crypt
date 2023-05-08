<?php

namespace FwsDoctrineCrypt\Model\Service;

use Doctrine\ORM\EntityManager;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use FwsDoctrineCrypt\Model\Crypt;
use Psr\Container\NotFoundExceptionInterface;

class CryptFactory implements FactoryInterface
{

    /**
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     * @return Crypt
     * @throws DoctrineCryptException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Crypt
    {
        return new Crypt(
            $container->get(EntityManager::class),
            $container->get('config')
        );
    }
}