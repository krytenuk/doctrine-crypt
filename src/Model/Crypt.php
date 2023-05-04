<?php

namespace FwsDoctrineCrypt\Model;

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
    const CRYPT_BLOCKCIPHER = 'blockcipher';
    const CRYPT_RSA = 'rsa';

    static array $allowedCrypts = [
        self::CRYPT_BLOCKCIPHER,
        self::CRYPT_RSA,
    ];

    static array $cryptNames = [
        self::CRYPT_BLOCKCIPHER => 'Block Cipher',
        self::CRYPT_RSA => 'RSA Key Encryption',
    ];

    private BlockCipher|Rsa $crypt;
    private string $encryptionMethod;

    /**
     *
     * @param array $config
     * @throws DoctrineCryptException
     */
    public function __construct(array $config)
    {
        $this->encryptionMethod = (string) $config['doctrineCrypt']['encryptionMethod'] ?? null;
        if (!$this->encryptionMethod) {
            throw new DoctrineCryptException('encryptionType is not set in config');
        }

        if (!in_array($this->encryptionMethod, self::$allowedCrypts)) {
            throw new DoctrineCryptException(sprintf(
                'encryptionType %f is not a supported encryption method, expected one of %s',
                $this->encryptionMethod,
                implode(', ', self::$allowedCrypts)
            ));
        }

        switch ($this->encryptionMethod) {
            case self::CRYPT_BLOCKCIPHER:
                $this->setBlockCipher($config);
                break;
            case self::CRYPT_RSA:
                $this->setRsa($config);
        }
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
     * @param $value
     * @return bool
     */
    public function isEncrypted($value): bool
    {
        $decrypted = $this->decrypt((string) $value) ?? $value;
        return $decrypted !== $value;
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
