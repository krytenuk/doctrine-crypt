<?php

namespace FwsDoctrineCrypt;

use Doctrine\ORM\EntityManager;
use FwsDoctrineCrypt\Filter\CryptIndex;
use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\EntityAttributes;
use Laminas\Cache\Service\StorageAdapterFactoryInterface;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\ServiceManager\AbstractFactory\ConfigAbstractFactory;
use Psr\Container\ContainerInterface;

class ConfigProvider
{
    /**
     * Returns the configuration array
     *
     * To add a bit of a structure, each section is defined in a separate
     * method which returns an array with its configuration.
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependenciesConfig(),
            'filters' => $this->getFilterConfig(),
            ConfigAbstractFactory::class => $this->getConfigAbstractFactoryConfig(),
            'doctrine-crypt' => [
                'cache_dir' => '/tmp/cache',
            ],
        ];
    }

    /**
     * Returns the container dependencies
     */
    public function getDependenciesConfig(): array
    {
        return [
            'abstract_factories' => [
                ConfigAbstractFactory::class,
            ],
            'factories' => [
                EntityAttributes::class => ConfigAbstractFactory::class,
                Crypt::class => ConfigAbstractFactory::class,
                'crypt-cache' => function (ContainerInterface $container) {
                    /** @var StorageAdapterFactoryInterface $storageFactory */
                    $storageFactory = $container->get(StorageAdapterFactoryInterface::class);
                    $config = $container->get('config');
                    return $storageFactory->create(
                        Filesystem::class,
                        [
                            'key_pattern' => '/^[a-z0-9_\+\-\\\\]*$/Di',
                            'cache_dir' => $config['doctrine-crypt']['cache_dir'],
                        ]
                    );
                }
            ],
        ];
    }

    public function getConfigAbstractFactoryConfig(): array
    {
        return [
            EntityAttributes::class => [
                'crypt-cache',
            ],
            Crypt::class => [
                EntityManager::class,
                EntityAttributes::class,
                'config',
            ],
        ];
    }

    public function getFilterConfig(): array
    {
        return [
            'factories' => [
                CryptIndex::class => function (ContainerInterface $container, $requestedName, ?array $options = null) {
                    return new CryptIndex(
                        $container->get(Crypt::class),
                        $options,
                    );
                }
            ],
            'aliases' => [
                'cryptIndex' => CryptIndex::class,
            ],
        ];
    }
}