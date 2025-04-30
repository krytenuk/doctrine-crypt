<?php

namespace FwsDoctrineCrypt;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Listener\DoctrineEntitySubscriber;
use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\EntityAttributes;
use Laminas\EventManager\EventInterface;
use Laminas\ModuleManager\Feature\BootstrapListenerInterface;
use Laminas\ModuleManager\ModuleManagerInterface;
use Laminas\ServiceManager\AbstractFactory\ConfigAbstractFactory;
use Symfony\Component\Console\Application as ConsoleApplication;

class Module implements BootstrapListenerInterface
{
    protected ConfigProvider $configProvider;
    public function __construct()
    {
        $this->configProvider = new ConfigProvider();
    }


    /**
     *
     * @param EventInterface $e
     * @throws DoctrineCryptException
     */
    public function onBootstrap(EventInterface $e): void
    {
        $serviceManager = $e->getApplication()->getServiceManager();
        $entityManager = $serviceManager->get(EntityManager::class);

        /** Add doctrine subscriber if not cli command */
        if (!($_SERVER["argv"] ?? null)) {
            $entityManager
                ->getEventManager()
                ->addEventSubscriber(new DoctrineEntitySubscriber(
                    $serviceManager->get(Crypt::class),
                    $entityManager
                ));
        }
    }

    public function getConfig(): array
    {
        return [
            'service_manager' => $this->configProvider->getDependenciesConfig(),
            'filters' => $this->configProvider->getFilterConfig(),
            ConfigAbstractFactory::class => $this->configProvider->getConfigAbstractFactoryConfig(),
        ];
    }

    /**
     * Add doctrine cli commands
     * @param ModuleManagerInterface $moduleManager
     */
    public function init(ModuleManagerInterface $moduleManager): void
    {
        $events = $moduleManager->getEventManager()->getSharedManager();
        // Attach to helper set event and load the entity manager helper.
        $events->attach('doctrine', 'loadCli.post', function (EventInterface $event) {
            /* @var $cli ConsoleApplication */
            $cli = $event->getTarget();
            /* @var $entityManager EntityManager */
            $entityManager = $cli->getHelperSet()->get('em')->getEntityManager();
            $entityAttributes = $event->getParam('ServiceManager')->get(EntityAttributes::class);
            $crypt = $event->getParam('ServiceManager')->get(Crypt::class);
            $config = $event->getParam('ServiceManager')->get('config');
            ConsoleRunner::addCommands($cli);
            $cli->addCommands([
                new Command\EncryptCommand($entityManager, $entityAttributes, $crypt),
                new Command\DecryptCommand($entityManager, $entityAttributes, $crypt),
                new Command\ReEncryptCommand($entityManager, $entityAttributes, $crypt, $config),
            ]);
        });
    }
}
