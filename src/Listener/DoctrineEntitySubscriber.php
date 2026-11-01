<?php

namespace FwsDoctrineCrypt\Listener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Exception;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use FwsDoctrineCrypt\Model\Crypt;
use ParagonIE\CipherSweet\Exception\CipherSweetException;
use ParagonIE\CipherSweet\Exception\CryptoOperationException;
use ReflectionException;
use SodiumException;

/**
 * Keeps entities in plaintext and the database in ciphertext.
 *
 * Entity objects only ever hold plaintext. Ciphertext exists solely in the database and in the
 * unit of work's change set, so application code never sees it, even if a flush fails part way.
 *
 * Other listeners must not change encrypted fields during preUpdate, or after this subscriber
 * has handled onFlush. Doctrine would write those changes without them being encrypted.
 */
class DoctrineEntitySubscriber implements EventSubscriber
{
    public function __construct(private readonly Crypt $crypt)
    {
    }

    /**
     * Get events used in this subscriber
     * @return string[]
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::postLoad,
            Events::onFlush,
        ];
    }

    /**
     * Decrypt an entity read from the database.
     *
     * Doctrine has recorded the ciphertext as the entity's original values. They are replaced with
     * the plaintext, otherwise every loaded entity would appear changed on the next flush.
     *
     * @throws DoctrineCryptException
     */
    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();
        $entityManager = $event->getObjectManager();
        $entityClassName = $entity::class;

        try {
            $this->crypt->decrypt($entity);
            $properties = $this->crypt->getEncryptedProperties($entityClassName);
        } catch (DoctrineCryptException|CryptoOperationException|CipherSweetException|ReflectionException|SodiumException $e) {
            throw $this->failure('decrypt', $entityClassName, $e);
        }

        $metadata = $entityManager->getClassMetadata($entityClassName);
        $unitOfWork = $entityManager->getUnitOfWork();
        foreach ($properties as $property) {
            $unitOfWork->setOriginalEntityProperty(
                spl_object_id($entity),
                $property,
                $metadata->getFieldValue($entity, $property)
            );
        }
    }

    /**
     * Encrypt the values about to be written. Runs before Doctrine opens its transaction, so a
     * failure here writes nothing.
     *
     * @throws DoctrineCryptException
     */
    public function onFlush(OnFlushEventArgs $event): void
    {
        $entityManager = $event->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->encryptChangeSet($entity, $entityManager);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $this->encryptChangeSet($entity, $entityManager);
        }
    }

    /**
     * Replace plaintext in the entity's change set with ciphertext, leaving the entity untouched.
     *
     * Only changed fields are in the change set, so unchanged ciphertext in the database is left alone.
     *
     * @throws DoctrineCryptException
     */
    private function encryptChangeSet(object $entity, EntityManagerInterface $entityManager): void
    {
        $entityClassName = $entity::class;
        $unitOfWork = $entityManager->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($entity);

        try {
            $plaintext = [];
            foreach ($this->crypt->getEncryptedProperties($entityClassName) as $property) {
                if (array_key_exists($property, $changeSet)) {
                    $plaintext[$property] = $changeSet[$property][1];
                }
            }

            [$encrypted, $indexes] = $this->crypt->encryptValues($entityClassName, $plaintext);
        } catch (DoctrineCryptException|CryptoOperationException|CipherSweetException|ReflectionException|SodiumException $e) {
            throw $this->failure('encrypt', $entityClassName, $e);
        }

        foreach ($encrypted as $property => $ciphertext) {
            $unitOfWork->propertyChanged($entity, $property, $changeSet[$property][0], $ciphertext);
        }

        /**
         * Blind indexes are not secret, so unlike ciphertext they are kept on the entity. The original
         * value is updated as well, otherwise the next flush would see the index as changed again.
         */
        $metadata = $entityManager->getClassMetadata($entityClassName);
        foreach ($indexes as $property => $index) {
            $current = $metadata->getFieldValue($entity, $property);
            if ($current === $index) {
                continue;
            }

            $metadata->setFieldValue($entity, $property, $index);
            $unitOfWork->propertyChanged($entity, $property, $current, $index);
            $unitOfWork->setOriginalEntityProperty(spl_object_id($entity), $property, $index);
        }
    }

    private function failure(string $action, string $entityClassName, Exception $previous): DoctrineCryptException
    {
        return new DoctrineCryptException(
            sprintf('Could not %s %s: %s', $action, $entityClassName, $previous->getMessage()),
            (int)$previous->getCode(),
            $previous
        );
    }
}
