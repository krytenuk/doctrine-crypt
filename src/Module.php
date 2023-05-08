<?php

namespace FwsDoctrineCrypt;

use Doctrine\ORM\Tools\Console\ConsoleRunner;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Listener\DoctrineEntitySubscriber;
use FwsDoctrineCrypt\Model\Crypt;
use Laminas\EventManager\EventInterface;
use Laminas\ModuleManager\Feature\BootstrapListenerInterface;
use Laminas\ModuleManager\ModuleManagerInterface;
use FwsDoctrineCrypt\Command;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Console\Application as ConsoleApplication;

class Module implements BootstrapListenerInterface
{

    /**
     *
     * @param EventInterface $e
     * @throws DoctrineCryptException
     */
    public function onBootstrap(EventInterface $e): void
    {
        $serviceManager = $e->getApplication()->getServiceManager();

        /** Add doctrine subscriber if not cli command */
        if (!($_SERVER["argv"] ?? null)) {
            $serviceManager
                ->get(EntityManager::class)
                ->getEventManager()
                ->addEventSubscriber(new DoctrineEntitySubscriber(
                    $serviceManager->get(Crypt::class)
                ));
        }
    }

    /**
     * 
     * @return array
     */
    public function getConfig(): array
    {
        return include __DIR__ . '/../config/module.config.php';
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
            $crypt = $event->getParam('ServiceManager')->get(Crypt::class);
            ConsoleRunner::addCommands($cli);
            $cli->addCommands([
                new Command\EncryptCommand($entityManager, $crypt),
                new Command\DecryptCommand($entityManager, $crypt),
            ]);
        });
    }

}
