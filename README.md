FwsDoctrineCrypt
================

The [online docs](https://www.freedomwebservices.net/laminas/fws-doctrine-crypt) currently describe version 1. For version 2,
use this file.

Encrypts and decrypts Doctrine ORM entity fields automatically, using attributes and
[CipherSweet](https://ciphersweet.paragonie.com/). Your entities always hold plaintext and the database holds ciphertext
for the fields you mark, so that data stays secure if the database falls into the wrong hands. See
[What is not protected](#what-is-not-protected) for the exceptions.

Requirements
------------

- PHP 8.2 or later with the `sodium` extension
- Doctrine ORM 2.20 or a later 2.x version
- An application that uses config providers, such as Mezzio. Laminas MVC is no longer supported.

Version 2 cannot read data encrypted by version 1. Decrypt it using version 1 before you upgrade.

Installation
------------

1. Install the package:

   ```bash
   composer require krytenuk/doctrine-crypt
   ```

2. Add `FwsDoctrineCrypt\ConfigProvider` to your config aggregator.

3. Copy `config/doctrine-crypt.local.php.dist` to your application's `config/autoload/doctrine-crypt.local.php` and set
   `encryptionKey`. See [Generating a key](#generating-a-key). **Keep the key out of version control, and keep a secure
   copy of it.** Data cannot be recovered without it.

4. Tell the module which entity manager to use. It is looked up as `Doctrine\ORM\EntityManagerInterface`, so alias that
   name to your entity manager or set `doctrine-crypt.entity_manager`.

5. Attach the subscriber, if necessary. With [roave/psr-container-doctrine](https://github.com/Roave/psr-container-doctrine)
   and the `orm_default` entity manager, the provider already does this through
   `doctrine.event_manager.orm_default.subscribers`.

   **If you build the entity manager any other way, or use another entity manager name, you must attach the subscriber
   yourself:**

   ```php
   $entityManager->getEventManager()->addEventSubscriber($container->get(DoctrineEntitySubscriber::class));
   ```

   Nothing fails if it is missing: entities are simply stored unencrypted. Check that the first record you write is
   encrypted.

### Configuration

| Key                                       | Description                                                                                                         |
|-------------------------------------------|---------------------------------------------------------------------------------------------------------------------|
| `doctrine-crypt.cipherSweet.encryptionKey` | Required. 32 raw bytes, 64 hex characters or 44 base64url characters.                                               |
| `doctrine-crypt.cipherSweet.engine`        | `ParagonIE\CipherSweet\Backend\BoringCrypto` (the default) or `FIPSCrypto`. See [Changing the key or engine](#changing-the-key-or-engine). |
| `doctrine-crypt.entity_manager`            | The container name of the entity manager, if not `Doctrine\ORM\EntityManagerInterface`.                             |

Commands
--------

| Command                       | Description                                                                 |
|-------------------------------|-----------------------------------------------------------------------------|
| `doctrine-crypt:generate-key` | Outputs a random encryption key                                             |
| `doctrine-crypt:encrypt`      | Encrypts everything that is not already encrypted. See [Migrating existing data](#migrating-existing-data). |
| `doctrine-crypt:decrypt`      | Decrypts everything that is encrypted. See [Migrating existing data](#migrating-existing-data). |

The commands are registered with [laminas-cli](https://github.com/laminas/laminas-cli), which you need to install if your
application does not already have it, and are run with `vendor/bin/laminas`.

If you use a `bin/doctrine` script instead, get `FwsDoctrineCrypt\Command\EncryptCommand`, `DecryptCommand` and
`GenerateKeyCommand` from the container and pass them to `ConsoleRunner::run()` as its `$commands` argument.

### Generating a key

```bash
vendor/bin/laminas doctrine-crypt:generate-key
```

This outputs a random key as 64 hex characters, ready to use as `encryptionKey`. It only prints the key and does not save
it. It needs no existing key or database connection, but `FwsDoctrineCrypt\ConfigProvider` must be in your config aggregator.

**Do not replace the key of an application that already has encrypted data**, as that data cannot be read with a new key.
See [Changing the key or engine](#changing-the-key-or-engine).

Mapping entities
----------------

```php
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use FwsDoctrineCrypt\Mapping as FWS;

#[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
#[FWS\EncryptedField]
#[FWS\IndexableField(name: 'email', filterBits: 32, property: 'emailIndex')]
private ?string $email = null;

#[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
private ?string $emailIndex = null;
```

- **Columns must be `string`, `ascii_string` or `text`.** Any other type throws an exception. With the default engine,
  ciphertext is about 80 characters plus four thirds of the value's length in bytes. A 100 byte value needs 213 characters.
  `FIPSCrypto` needs about 55 characters more.
- **Embeddables cannot have encrypted fields.** Saving or loading an entity that embeds one throws an exception, and the
  commands report it. Map those fields on the entity instead.
- **Null stays null** and is not encrypted, so a null value is visible in the database.
- **Integers, floats and booleans** are stored in a text column, with the type set on the attribute. The property must
  also accept a string, because Doctrine assigns it the ciphertext before it is decrypted:

  ```php
  #[ORM\Column(type: Types::TEXT)]
  #[FWS\EncryptedField(indexable: false, type: Types::INTEGER)]
  private int|string $age;
  ```

  The type can be `Types::TEXT` (the default), `Types::INTEGER`, `Types::FLOAT` or `Types::BOOLEAN`.

- **Decimals** should be mapped as plain text fields, so that they keep their exact value.
- **Do not rename encrypted entities or properties, or move the entities to another namespace.** The encryption keys are
  derived from the full class name and the property name, so changing either one makes the existing data unreadable.

### Blind indexes

A blind index lets you search for an exact value without decrypting it. Each `IndexableField` needs its own mapped column,
named by `property`, and a property can have more than one index. The index name used in searches is the `property` value.

Search using `Crypt::getIndex()`:

```php
$index = $crypt->getIndex(User::class, 'email', 'someone@example.com', 'emailIndex');
$users = $entityManager->getRepository(User::class)->findBy(['emailIndex' => $index]);
```

Or the `CryptIndex` filter, which is also registered as `cryptIndex`. Without `filter_name`, it uses the property's first
index:

```php
$filter = $filterPluginManager->get('cryptIndex', [
    'entity_class_name' => User::class,
    'property' => 'email',
    'filter_name' => 'emailIndex',
]);
$index = $filter->filter('someone@example.com');
```

`IndexableField` takes these arguments:

| Argument                | Description                                                                                             |
|-------------------------|---------------------------------------------------------------------------------------------------------|
| `property`              | Required. The property that holds the index, which is also the index name.                              |
| `filterBits`            | The size of the index, 256 bits by default. The value is hex, so 32 bits needs 8 characters and 256 needs 64. |
| `transformationClasses` | CipherSweet transformations to apply first, such as `ParagonIE\CipherSweet\Transformation\Lowercase` for case-insensitive searches. |
| `fastHash`              | Use a fast hash instead of the default slow one. Writes and searches are quicker, but the index is easier to attack by guessing values. |
| `name`                  | A label used in error messages.                                                                         |

The `autoRefresh` and `fastIndexing` arguments currently have no effect.

Choose `filterBits` carefully. An untruncated index shows which records hold the same value. A short index causes false
positives that you need to check after decrypting, but reveals less. See the
[CipherSweet documentation](https://ciphersweet.paragonie.com/).

Existing indexes are not updated if you change `property`, `filterBits` or `transformationClasses`, so searches stop
finding those records. To rebuild them, run `doctrine-crypt:decrypt` and then `doctrine-crypt:encrypt` with the new mapping
in place, taking the application offline as described in [Changing the key or engine](#changing-the-key-or-engine).

### What is not protected

Encryption only happens when Doctrine flushes an entity, and decryption only when it loads one. The following are **not**
covered:

- **Properties that are not mapped columns.** `#[EncryptedField]` on them is ignored.
- **DQL `UPDATE` queries and native SQL.** Values are written exactly as given, so they will not be encrypted.
- **Array and scalar results.** For example, `getArrayResult()` returns the ciphertext.

An encrypted column also cannot be used for `WHERE`, `ORDER BY` or `LIKE`, as each encryption of a value is different. Use a
blind index to search for an exact value.

Blind indexes and null values are stored in the database as they are. The lengths of the ciphertext also show the
approximate lengths of the values.

### Other listeners

Do not change an encrypted field in a `preUpdate` listener, or in an `onFlush` listener that runs after this one. **Doctrine
will write that value unencrypted.** During those events, the change set holds ciphertext. Changes made in `prePersist`
are safe.

Migrating existing data
-----------------------

Take a backup first. `doctrine-crypt:encrypt` and `doctrine-crypt:decrypt` process every mapped entity with encrypted fields
in the configured entity manager, and have these options:

| Option                   | Description                                                                           |
|--------------------------|---------------------------------------------------------------------------------------|
| `--dry-run`              | Processes the records without saving anything, reporting any that would fail.         |
| `--no-interaction`, `-n` | Runs without asking for confirmation. Use this in deployment scripts.                 |

Both commands are safe to run again and exit with a failure code if any record could not be processed. Records are saved
in batches of 20 and a record that fails is skipped, so a run that fails may have changed other records. Fix the problem
and run the command again.

**Loading a record with an unencrypted value in an encrypted field throws an exception**, as plaintext could have been
substituted for the ciphertext. Running a command with the wrong key or engine is reported as a failure and leaves the data alone.

If a value that is really plaintext starts with a ciphertext header (`brng:`, `fips:` or `nacl:`), the commands report it
instead of guessing, so you need to deal with those records yourself.

### Encrypting existing columns

1. Make sure the columns are a text type and long enough to hold the ciphertext.
2. Add `#[EncryptedField]` to the properties.
3. Run `doctrine-crypt:encrypt` as part of the same deployment.

### Removing encryption

1. Run `doctrine-crypt:decrypt` while the attributes are still in place. This also clears nullable blind index columns.
   Columns that do not allow null keep their values.
2. Remove the attributes in the same deployment.

### Changing the key or engine

1. Take the application offline, as the data will be in plaintext until you finish.
2. Run `doctrine-crypt:decrypt` with the current key and engine.
3. Change the key or engine in your config.
4. Run `doctrine-crypt:encrypt`.

If you change the engine without decrypting first, the commands report each encrypted value as a failure and leave it alone.
Change the config back to the original engine and follow the steps above.

Testing
-------

`vendor/bin/phpunit`. The tests need no database, as they use an in-memory driver that records what Doctrine writes.
