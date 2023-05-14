<?php

namespace FwsDoctrineCrypt\Model;


use Doctrine\ORM\EntityManager;
use Exception;
use Laminas\Crypt\BlockCipher;
use Laminas\Crypt\PublicKey\Rsa;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;

/**
 * Crypt class
 * Performs encryption/decryption of data
 *
 * @author Garry Childs <info@freedomwebservices.net>
 */
class Crypt
{
    const CRYPT_BLOCK_CIPHER = 'block-cipher';
    const CRYPT_RSA = 'rsa';

    static array $allowedCrypts = [
        self::CRYPT_BLOCK_CIPHER,
        self::CRYPT_RSA,
    ];

    static array $cryptNames = [
        self::CRYPT_BLOCK_CIPHER => 'Block Cipher',
        self::CRYPT_RSA => 'RSA Key Encryption',
    ];

    protected BlockCipher|Rsa $crypt;
    protected ?string $encryptionMethod;
    protected array $properties = [];
    protected array $entities = [];

    /**
     *
     * @param EntityManager $entityManager
     * @param array $config
     * @throws DoctrineCryptException
     */
    public function __construct(
        protected EntityManager $entityManager,
        protected array $config
    )
    {
        /** Check if encryption method is set in config */
        $this->encryptionMethod = $config['doctrineCrypt']['encryptionMethod'] ?? null;
        if (!$this->encryptionMethod) {
            throw new DoctrineCryptException('encryptionMethod is not set in config');
        }

        /** Check if entities set in config */
        $entitiesConfig = $config['doctrineCrypt']['entities'] ?? null;
        if ($entitiesConfig === null) {
            throw new DoctrineCryptException('Doctrine crypt entities config not set');
        }

        switch ($this->encryptionMethod) {
            case self::CRYPT_BLOCK_CIPHER:
                $this->setBlockCipher($config);
                break;
            case self::CRYPT_RSA:
                $this->setRsa($config);
                break;
            default: // invalid encryption method
                throw new DoctrineCryptException(sprintf(
                    'encryptionType %f is not a supported encryption method, expected one of %s',
                    $this->encryptionMethod,
                    implode(', ', self::$allowedCrypts)
                ));
        }

        /** Store entities properties */
        foreach ($entitiesConfig as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            if (!(array_key_exists('class', $entity) && array_key_exists('properties', $entity))) {
                continue;
            }

            $properties = array_merge(($this->entities[$entity['class']] ?? []), $entity['properties']);
            $this->entities[$entity['class']] = $properties;
            $this->properties = array_merge($this->properties, $entity['properties']);
        }
        $this->properties = array_unique($this->properties);
    }

    /**
     * Set setup Block Cipher encryption
     * @see https://docs.laminas.dev/laminas-crypt/public-key/#rsa
     * @param array $config
     * @return void
     * @throws DoctrineCryptException
     */
    private function setBlockCipher(array $config): void
    {
        $key = $config['doctrineCrypt']['encryptionKey'] ?? null;
        if (!$key) {
            throw new DoctrineCryptException('encryptionKey key not set in config');
        }

        /** Set and configure BlockCipher encryption */
        $this->crypt = BlockCipher::factory('openssl', ['algo' => 'aes']);
        $this->crypt->setKey($key);
    }

    /**
     * Set setup RSA encryption
     * @see https://docs.laminas.dev/laminas-crypt/public-key/#rsa
     * @param array $config
     * @return void
     * @throws DoctrineCryptException
     */
    private function setRsa(array $config): void
    {
        $publicKeyFile = (string) $config['doctrineCrypt']['rsaPublicKeyFile'] ?? null;
        if (!$publicKeyFile) {
            throw new DoctrineCryptException('rsaPublicKeyFile key not set in config');
        }

        $privateKeyFile = (string) $config['doctrineCrypt']['rsaPrivateKeyFile'] ?? null;
        if (!$privateKeyFile) {
            throw new DoctrineCryptException('rsaPrivateKeyFile key not set in config');
        }

        $passphrase = (string) $config['doctrineCrypt']['rsaKeyPassphrase'] ?? null;
        if (!$passphrase) {
            throw new DoctrineCryptException('rsaKeyPassphrase key not set in config');
        }

        /** Set and configure RSA encryption */
        $this->crypt = Rsa::factory([
            'public_key' => $publicKeyFile,
            'private_key' => $privateKeyFile,
            'pass_phrase' => $passphrase,
            'binary_output' => false,
        ]);
    }

    /**
     * Get entities and their properties to process
     * @return array
     */
    public function getEntityPropertiesFromConfig(): array
    {
        return $this->entities;
    }

    /**
     * Encrypt and return value, null on failure
     * @param string $value
     * @return string|null
     */
    public function encrypt(string $value): ?string
    {
        try {
            return $this->crypt->encrypt($value);
        } catch (Exception) {
            return $value;
        }
    }

    /**
     * Decrypt and return value, null on failure
     * @param string $value
     * @return string|null
     */
    public function decrypt(string $value): ?string
    {
        try {
            return $this->crypt->decrypt($value) ?: null;
        } catch (Exception) {
            return null;
        }
        
    }

    /**
     * Check if value is encrypted
     * @param string $value
     * @return bool
     */
    public function isEncrypted(string $value): bool
    {
        $decrypted = $this->decrypt($value) ?? $value;
        return $decrypted !== $value;
    }

    /**
     * Decrypt an array returned from Doctrine with AbstractQuery::HYDRATE_ARRAY hydration
     * @param array $array
     * @return array
     */
    public function decryptArray(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->decryptArray($value);
                continue;
            }

            if (!is_scalar($value) || is_bool($value) || !$value) {
                continue;
            }

            if (!in_array($key, $this->properties)) {
                continue;
            }

            $array[$key] = $this->decrypt((string) $value) ?? $value;
        }
        return $array;
    }

    /**
     * Returns the encryption method being used
     * @see self::$allowedCrypts
     * @return string
     */
    public function getEncryptionMethod(): string
    {
        return $this->encryptionMethod;
    }
}
