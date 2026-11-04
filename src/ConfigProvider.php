<?php

namespace FwsDoctrineCrypt;

use FwsDoctrineCrypt\Command\DecryptCommand;
use FwsDoctrineCrypt\Command\EncryptCommand;
use FwsDoctrineCrypt\Command\GenerateKeyCommand;
use FwsDoctrineCrypt\Command\Service\MigrationCommandFactory;
use FwsDoctrineCrypt\Filter\CryptIndex;
use FwsDoctrineCrypt\Filter\Service\CryptIndexFactory;
use FwsDoctrineCrypt\Listener\DoctrineEntitySubscriber;
use FwsDoctrineCrypt\Listener\Service\DoctrineEntitySubscriberFactory;
use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\EntityAttributes;
use FwsDoctrineCrypt\Model\Service\CryptFactory;

/**
 * Configuration for Mezzio and other applications that use config providers.
 * Everything here uses factory class names rather than closures, so it survives config caching.
 */
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
            'laminas-cli' => $this->getCliConfig(),
            'doctrine' => $this->getDoctrineConfig(),
        ];
    }

    /**
     * Returns the container dependencies
     */
    public function getDependenciesConfig(): array
    {
        return [
            'invokables' => [
                EntityAttributes::class => EntityAttributes::class,
                GenerateKeyCommand::class => GenerateKeyCommand::class,
            ],
            'factories' => [
                Crypt::class => CryptFactory::class,
                DoctrineEntitySubscriber::class => DoctrineEntitySubscriberFactory::class,
                EncryptCommand::class => MigrationCommandFactory::class,
                DecryptCommand::class => MigrationCommandFactory::class,
            ],
        ];
    }

    public function getFilterConfig(): array
    {
        return [
            'factories' => [
                CryptIndex::class => CryptIndexFactory::class,
            ],
            'aliases' => [
                'cryptIndex' => CryptIndex::class,
            ],
        ];
    }

    public function getCliConfig(): array
    {
        return [
            'commands' => [
                'doctrine-crypt:encrypt' => EncryptCommand::class,
                'doctrine-crypt:decrypt' => DecryptCommand::class,
                'doctrine-crypt:generate-key' => GenerateKeyCommand::class,
            ],
        ];
    }

    /**
     * Attaches the subscriber to the default entity manager when using roave/psr-container-doctrine.
     * Any other way of building the entity manager has to attach it itself. See the README.
     */
    public function getDoctrineConfig(): array
    {
        return [
            'event_manager' => [
                'orm_default' => [
                    'subscribers' => [
                        DoctrineEntitySubscriber::class,
                    ],
                ],
            ],
        ];
    }
}
