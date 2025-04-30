<?php

namespace FwsDoctrineCrypt\Listener;

use Doctrine\Common\EventSubscriber;
use Doctrine\Laminas\Hydrator\DoctrineObject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Model\Crypt;
use ParagonIE\CipherSweet\Exception\CipherSweetException;
use ParagonIE\CipherSweet\Exception\CryptoOperationException;
use ReflectionException;
use SodiumException;

class DoctrineEntitySubscriber implements EventSubscriber
{
    private ?DoctrineObject $hydrator = null;

    public function __construct(
        private readonly Crypt $crypt,
        EntityManagerInterface $entityManager
    )
    {
        $this->setHydrator($entityManager);
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
        $entity = $event->getObject();
        try {
            $this->crypt->encrypt($entity);
        } catch (DoctrineCryptException|CryptoOperationException|CipherSweetException|ReflectionException|SodiumException $e) {
            throw new DoctrineCryptException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws DoctrineCryptException
     */
    public function postPersist(LifecycleEventArgs $event): void
    {
        $this->postLoad($event);
    }

    /**
     * Called when an existing entity is saved (UPDATE)
     * @param LifecycleEventArgs $event
     * @return void
     * @throws DoctrineCryptException
     */
    public function preUpdate(LifecycleEventArgs $event): void
    {
        $entity = $event->getObject();
        try {
            $this->crypt->encrypt($entity);
        } catch (DoctrineCryptException|CryptoOperationException|CipherSweetException|ReflectionException|SodiumException $e) {
            throw new DoctrineCryptException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws DoctrineCryptException
     */
    public function postUpdate(LifecycleEventArgs $event): void
    {
        $this->postLoad($event);
    }

    /**
     * Called after a new is read (SELECT)
     * @param LifecycleEventArgs $event
     * @return void
     * @throws DoctrineCryptException
     */
    public function postLoad(LifecycleEventArgs $event): void
    {
        $entity = $event->getObject();
        try {
            $this->crypt->decrypt($entity);
        } catch (DoctrineCryptException|CryptoOperationException|CipherSweetException|ReflectionException|SodiumException $e) {
            throw new DoctrineCryptException($e->getMessage(), $e->getCode(), $e);
        }
    }

}