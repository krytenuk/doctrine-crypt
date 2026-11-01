<?php

namespace FwsDoctrineCrypt\Filter\Service;

use FwsDoctrineCrypt\Filter\CryptIndex;
use FwsDoctrineCrypt\Model\Crypt;
use Psr\Container\ContainerInterface;

class CryptIndexFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName = '', ?array $options = null): CryptIndex
    {
        return new CryptIndex($container->get(Crypt::class), $options);
    }
}
