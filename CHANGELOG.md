
1.0.0 - 13/05/2023 

Initial release

1.1.0 - 14/05/2023

Added re-encrypt command to allow database decryption and re-encryption using a different encryption method

1.1.1 20/05/2023

Changed DoctrineObject hydrator to use reflection hydration

2.0.0 - unreleased

Changed encryption to CipherSweet, configured with attributes on entities (not compatible with data from 1.x)
Added Mezzio support through ConfigProvider and removed Laminas MVC support, including Module and the decrypt view helper
Entities now always hold plaintext, ciphertext is only written through the Doctrine change set
Loading a record that holds plaintext in an encrypted field, invalid attributes and unsupported column types now throw exceptions
Encrypted columns must now be text types, use the type argument of EncryptedField for integers, floats and booleans
Added support for null values and multiple blind indexes on a property
Encrypt and decrypt commands now exit with a failure code if any record fails, and decrypt clears nullable blind indexes
Removed the attribute cache and the cache_dir config
Fixed the engine config being ignored
