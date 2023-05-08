<?php

namespace FwsDoctrineCrypt\View\Helper\Service;

use FwsDoctrineCrypt\Model\Crypt;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use FwsDoctrineCrypt\View\Helper\Decrypt;
use Psr\Container\NotFoundExceptionInterface;

class DecryptFactory implements FactoryInterface
{

    /**
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     * @return Decrypt
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Decrypt
    {
        return new Decrypt($container->get(Crypt::class));
    }
}