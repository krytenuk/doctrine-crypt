<?php

namespace FwsDoctrineCrypt\Listener;

use Doctrine\Common\EventSubscriber;
use Doctrine\Laminas\Hydrator\DoctrineObject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Model\Crypt;

class DoctrineEntitySubscriber implements EventSubscriber
{
    private array $entities;
    private ?DoctrineObject $hydrator = null;

    /**
     * @param Crypt $cryptModel
     */
    public function __construct(private Crypt $cryptModel)
    {
        $this->entities = $this->cryptModel->getEntityPropertiesFromConfig();
    }

    /**
     * Get events used in this subscriber
     * @return array|string[]
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist,
            Events::preUpdate,
            Events::postLoad,
        ];
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @return void
     */
    public function setHydrator(EntityManagerInterface $entityManager): void
    {
        if (!$this->hydrator) {
           $this->hydrator = new DoctrineObject($entityManager, false);
        }
    }

    /**
     * Called when a new entity is saved (INSERT)
     * @param LifecycleEventArgs $event
     * @return void
     * @throws DoctrineCryptException
     */
    public function prePersist(LifecycleEventArgs $event): void
    {
        $this->setHydrator($event->getObjectManager());
        $entity = $event->getObject();
        $this->encrypt($entity);
    }

    /**
     * Called when an existing entity is saved (UPDATE)
     * @param LifecycleEventArgs $event
     * @return void
     * @throws DoctrineCryptException
     */
    public function preUpdate(LifecycleEventArgs $event): void
    {
        $this->setHydrator($event->getObjectManager());
        $entity = $event->getObject();
        $this->encrypt($entity);
    }

    /**
     * Called after a new is read (SELECT)
     * @param LifecycleEventArgs $event
     * @return void
     * @throws DoctrineCryptException
     */
    public function postLoad(LifecycleEventArgs $event): void
    {
        $this->setHydrator($event->getObjectManager());
        $entity = $event->getObject();
        $this->deCrypt($entity);
    }

    /**
     * Find an entities configuration property array
     * @param object $entity
     * @return array|null
     */
    private function findEntityConfigProperties(object $entity): ?array
    {
        return $this->entities[$entity::class] ?? null;
    }

    /**
     * Return an array of properties and values that are to be encrypted/Decrypted
     * @param object $entity
     * @return array|null
     */
    private function getProperties(object $entity): ?array
    {
        $returnArray = [];
        $properties = $this->findEntityConfigProperties($entity);
        if ($properties === null) {
            return null;
        }

        foreach ($this->hydrator->extract($entity) as $name => $value) {
            if (in_array($name, $properties)) {
                $returnArray[$name] = $value;
            }
        }

        return $returnArray;
    }

    /**
     * Decrypt entity
     * @param object $entity
     * @return void
     * @throws DoctrineCryptException
     */
    private function deCrypt(object $entity): void
    {
        $this->crypt('decrypt', $entity);
    }

    /**
     * Encrypt entity
     * @param object $entity
     * @return void
     * @throws DoctrineCryptException
     */
    private function encrypt(object $entity): void
    {
        $this->crypt('encrypt', $entity);
    }

    /**
     * Performs the actual encryption/decryption
     * @param string $method
     * @param object $entity
     * @return void
     * @throws DoctrineCryptException
     */
    private function crypt(string $method, object $entity): void
    {
        $crypt = $this->cryptModel;
        /** I don't really need this check, but I prefer the belt and braces approach to coding ;) */
        if (!method_exists($crypt, $method)) {
            throw new DoctrineCryptException(sprintf(_('Method %s not found in %s'), $method, $crypt::class));
        }

        $properties = $this->getProperties($entity);
        if (!$properties) {
            return;
        }

        foreach ($properties as $name => $value) {
            if (!$value) {
                continue;
            }
            $properties[$name] = $crypt->$method($value) ?? $value;
        }

        $this->hydrator->hydrate($properties, $entity);
    }
}