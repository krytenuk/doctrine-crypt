<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use FwsDoctrineCrypt\Listener\DoctrineEntitySubscriber;
use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\EntityAttributes;

/**
 * Builds real entity managers and Crypt models that share one in-memory database.
 */
final class TestEnvironment
{
    public const KEY = 'abababababababababababababababababababababababababababababababab';
    public const OTHER_KEY = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    public readonly InMemoryDatabase $database;

    private readonly string $workingDirectory;

    public function __construct()
    {
        $this->database = new InMemoryDatabase();
        $this->workingDirectory = sys_get_temp_dir() . '/fws-doctrine-crypt-tests-' . bin2hex(random_bytes(6));
        mkdir($this->workingDirectory . '/proxies', 0700, true);
    }

    public function entityManager(string $entityDirectory = __DIR__ . '/Entity'): EntityManager
    {
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([$entityDirectory]));
        $configuration->setProxyDir($this->workingDirectory . '/proxies');
        $configuration->setProxyNamespace('FwsDoctrineCryptTestProxies');
        $configuration->setAutoGenerateProxyClasses(true);

        return new EntityManager(
            DriverManager::getConnection(
                ['driverClass' => RecordingDriver::class, 'driverOptions' => ['database' => $this->database]],
                $configuration
            ),
            $configuration
        );
    }

    public function attributes(): EntityAttributes
    {
        return new EntityAttributes();
    }

    public function crypt(EntityManager $entityManager, string $key = self::KEY, ?string $engine = null): Crypt
    {
        return new Crypt(
            static fn(): EntityManager => $entityManager,
            $this->attributes(),
            ['doctrine-crypt' => ['cipherSweet' => ['encryptionKey' => $key, 'engine' => $engine]]]
        );
    }

    /**
     * An entity manager with the subscriber attached, as an application would use it.
     */
    public function listeningEntityManager(
        string $key = self::KEY,
        string $entityDirectory = __DIR__ . '/Entity'
    ): EntityManager {
        $entityManager = $this->entityManager($entityDirectory);
        $entityManager->getEventManager()->addEventSubscriber(
            new DoctrineEntitySubscriber($this->crypt($entityManager, $key))
        );

        return $entityManager;
    }

    public function removeWorkingDirectory(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workingDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->workingDirectory);
    }
}
