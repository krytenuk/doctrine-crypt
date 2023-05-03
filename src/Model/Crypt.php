<?php

namespace FwsDoctrineCrypt\Model;

use Exception;
use Laminas\Crypt\PublicKey\Rsa;
use FwsDoctrineCrypt\Exception\DoctrineCryptException;
use Laminas\Stdlib\ArrayUtils;
use Traversable;

/**
 * Crypt class
 * Performs encryption/decryption of data
 *
 * @author Garry Childs <info@freedomwebservices.net>
 */
class Crypt
{
    private Rsa $rsa;

    /**
     *
     * @param iterable $config
     * @throws DoctrineCryptException
     */
    public function __construct(iterable $config)
    {
        if ($config instanceof Traversable) {
            $config = ArrayUtils::iteratorToArray($config);
        }

        $this->setConfig($config);
    }

    /**
     * Set config and setup RSA
     * @see https://docs.laminas.dev/laminas-crypt/public-key/#rsa
     *
     * @param array $config
     * @return void
     * @throws DoctrineCryptException
     */
    private function setConfig(array $config): void
    {
        if (!isset($config['doctrine-crypt']['rsaPublicKeyFile'])) {
            throw new DoctrineCryptException('rsaPublicKeyFile key not set in config');
        }
        if (!isset($config['doctrine-crypt']['rsaPrivateKeyFile'])) {
            throw new DoctrineCryptException('rsaPrivateKeyFile key not set in config');
        }
        if (!isset($config['doctrine-crypt']['rsaKeyPassphrase'])) {
            throw new DoctrineCryptException('rsaKeyPassphrase key not set in config');
        }

        /** Set and configure RSA encryption */
        $this->rsa = Rsa::factory([
                    'public_key' => $config['doctrine-crypt']['rsaPublicKeyFile'],
                    'private_key' => $config['doctrine-crypt']['rsaPrivateKeyFile'],
                    'pass_phrase' => $config['doctrine-crypt']['rsaKeyPassphrase'],
                    'binary_output' => false,
        ]);
    }

    /**
     * Encrypt and return value, null on failure
     * @param string $value
     * @return string|null
     */
    public function rsaEncrypt(string $value): ?string
    {
        try {
            return $this->rsa->encrypt($value);
        } catch (Exception) {
            return null;
        }
        
    }

    /**
     * Decrypt and return value, null on failure
     * @param string $value
     * @return string|null
     */
    public function rsaDecrypt(string $value): ?string
    {
        try {
            return $this->rsa->decrypt($value);
        } catch (Exception) {
            return null;
        }
        
    }
}
