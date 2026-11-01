<?php

namespace FwsDoctrineCrypt\Listener\Service;

use FwsDoctrineCrypt\Listener\DoctrineEntitySubscriber;
use FwsDoctrineCrypt\Model\Crypt;
use Psr\Container\ContainerInterface;

class DoctrineEntitySubscriberFactory
{
    public function __invoke(ContainerInterface $container): DoctrineEntitySubscriber
    {
        return new DoctrineEntitySubscriber($container->get(Crypt::class));
    }
}
