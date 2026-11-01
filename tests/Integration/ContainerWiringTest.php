<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Integration;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use FwsDoctrineCrypt\Command\DecryptCommand;
use FwsDoctrineCrypt\Command\EncryptCommand;
use FwsDoctrineCrypt\ConfigProvider;
use FwsDoctrineCrypt\Filter\CryptIndex;
use FwsDoctrineCrypt\Filter\Service\CryptIndexFactory;
use FwsDoctrineCrypt\Test\Support\Entity\Person;
use FwsDoctrineCrypt\Test\Support\InMemoryDatabase;
use FwsDoctrineCrypt\Test\Support\RecordingDriver;
use FwsDoctrineCrypt\Test\Support\TestEnvironment;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use Roave\PsrContainerDoctrine\EntityManagerFactory;

/**
 * Builds the module the way a Mezzio application does: a real container, the ConfigProvider and the
 * roave/psr-container-doctrine factories, with the entity manager registered under its roave name.
 */
final class ContainerWiringTest extends TestCase
{
    private const ENTITY_MANAGER = 'doctrine.entity_manager.orm_default';

    private InMemoryDatabase $database;

    protected function setUp(): void
    {
        $this->database = new InMemoryDatabase();
    }

    private function container(): ServiceManager
    {
        $provider = (new ConfigProvider())();
        $config = array_replace_recursive($provider, [
            'doctrine' => [
                'connection' => [
                    'orm_default' => [
                        'driver_class' => RecordingDriver::class,
                        'params' => ['driverOptions' => ['database' => $this->database]],
                    ],
                ],
                'configuration' => [
                    'orm_default' => [
                        'proxy_dir' => sys_get_temp_dir() . '/fws-doctrine-crypt-container-proxies',
                        'auto_generate_proxy_classes' => true,
                    ],
                ],
                'driver' => [
                    'orm_default' => [
                        'class' => AttributeDriver::class,
                        'paths' => [__DIR__ . '/../Support/Entity'],
                    ],
                ],
            ],
            'doctrine-crypt' => [
                'cipherSweet' => ['encryptionKey' => TestEnvironment::KEY],
            ],
        ]);

        $dependencies = $provider['dependencies'];
        $dependencies['factories'][self::ENTITY_MANAGER] = EntityManagerFactory::class;
        $dependencies['aliases'][EntityManagerInterface::class] = self::ENTITY_MANAGER;
        $dependencies['services']['config'] = $config;

        return new ServiceManager($dependencies);
    }

    public function testEntityManagerFromContainerEncryptsAndDecrypts(): void
    {
        $entityManager = $this->container()->get(self::ENTITY_MANAGER);
        $person = new Person('person@example.com');
        $entityManager->persist($person);
        $entityManager->flush();

        self::assertStringStartsWith('brng:', $this->database->tables['person'][$person->getId()]['email']);

        $loaded = $this->container()->get(self::ENTITY_MANAGER)->find(Person::class, $person->getId());
        self::assertSame('person@example.com', $loaded->getEmail());
    }

    public function testCliCommandsResolveFromContainer(): void
    {
        $container = $this->container();
        $commands = (new ConfigProvider())()['laminas-cli']['commands'];

        self::assertInstanceOf(EncryptCommand::class, $container->get($commands['doctrine-crypt:encrypt']));
        self::assertInstanceOf(DecryptCommand::class, $container->get($commands['doctrine-crypt:decrypt']));
        self::assertSame('doctrine-crypt:encrypt', $container->get($commands['doctrine-crypt:encrypt'])->getName());
    }

    public function testFilterFactoryPassesOptions(): void
    {
        $filter = (new CryptIndexFactory())(
            $this->container(),
            CryptIndex::class,
            ['entity_class_name' => Person::class, 'property' => 'email', 'filter_name' => 'emailIndex']
        );

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $filter->filter('person@example.com'));
    }

    public function testConfigCanBeCached(): void
    {
        $config = (new ConfigProvider())();

        array_walk_recursive($config, static function (mixed $value): void {
            self::assertNotInstanceOf(Closure::class, $value);
        });
    }

    public function testConfiguredEntityManagerNameIsUsed(): void
    {
        $provider = (new ConfigProvider())();
        $config = [
            'doctrine-crypt' => [
                'entity_manager' => 'custom.entity_manager',
                'cipherSweet' => ['encryptionKey' => TestEnvironment::KEY],
            ],
        ];
        $environment = new TestEnvironment();
        $entityManager = $environment->entityManager();

        $dependencies = $provider['dependencies'];
        $dependencies['services']['config'] = $config;
        $dependencies['services']['custom.entity_manager'] = $entityManager;
        $command = (new ServiceManager($dependencies))->get(EncryptCommand::class);

        self::assertInstanceOf(EncryptCommand::class, $command);
        $environment->removeWorkingDirectory();
    }
}
