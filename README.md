FwsDoctrineCrypt
================

Docs available [online](https://www.freedomwebservices.net/laminas/fws-doctrine-crypt).

Encrypts and decrypts Doctrine ORM entity fields automatically, using attributes and
[CipherSweet](https://ciphersweet.paragonie.com/). Your entities always hold plaintext. The database only ever holds
ciphertext, so your sensitive data stays secure if the database falls into the wrong hands.

Installation
------------

Copy `config/doctrine-crypt.local.php.dist` to your application's `config/autoload/doctrine-crypt.local.php` and set your
key. **Keep the key out of version control.** Data cannot be recovered without it.

Add `FwsDoctrineCrypt\ConfigProvider` to your config aggregator. With
[roave/psr-container-doctrine](https://github.com/Roave/psr-container-doctrine) and the `orm_default` entity manager there
is nothing else to do, as the provider attaches the subscriber through `doctrine.event_manager.orm_default.subscribers`.

The entity manager is looked up as `Doctrine\ORM\EntityManagerInterface`, so alias that name to your entity manager or
set `doctrine-crypt.entity_manager`.

**If you build the entity manager any other way, or use another entity manager name, you must attach
`FwsDoctrineCrypt\Listener\DoctrineEntitySubscriber` yourself.** Nothing fails if it is missing: entities are simply
stored unencrypted. Check that the first record you write is encrypted.

The commands are registered with `laminas-cli`. If you use a `bin/doctrine` script instead, get
`FwsDoctrineCrypt\Command\EncryptCommand` and `DecryptCommand` from the container and pass them to `ConsoleRunner::run()`.

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

- **Columns must be `string`, `ascii_string` or `text`.** Any other type throws an exception. Ciphertext adds about 85
  characters and is about a third longer than the value, so allow for that in the column length.
- **Null stays null** and is not encrypted, so a null value is visible in the database.
- **Integers, floats and booleans** are stored in a text column, with the type set on the attribute. The property must
  also accept a string, because Doctrine assigns it the ciphertext before it is decrypted:

  ```php
  #[ORM\Column(type: Types::TEXT)]
  #[FWS\EncryptedField(indexable: false, type: Types::INTEGER)]
  private int|string $age;
  ```

- **Decimals** should be mapped as plain text fields, so that they keep their exact value.
- **Do not rename encrypted entities or properties.** The encryption keys are derived from the class and property names, so
  renaming either one makes the existing data unreadable.

### Blind indexes

A blind index lets you search for an exact value without decrypting it. The index name used in searches is the `property`
value. Search with the `CryptIndex` filter, or `Crypt::getIndex()`.

Choose `filterBits` carefully. An untruncated index (the default is 256 bits) shows which records hold the same value. A
short index causes false positives that you need to check after decrypting, but reveals less. See the
[CipherSweet documentation](https://ciphersweet.paragonie.com/).

### Other listeners

Do not change an encrypted field in a `preUpdate` listener, or in an `onFlush` listener that runs after this one. **Doctrine
will write that value unencrypted.** During those events, the change set holds ciphertext. Changes made in `prePersist`
are safe.

Migrating existing data
-----------------------

Take a backup first. Both commands have a `--dry-run` option, are safe to run again and exit with a failure code if any
record could not be processed.

- `doctrine-crypt:encrypt` encrypts everything that is not already encrypted.
- `doctrine-crypt:decrypt` decrypts everything that is encrypted, and clears nullable blind index columns.

**Loading a record with an unencrypted value in an encrypted field throws an exception**, as plaintext could have been
substituted for the ciphertext. When you add `#[EncryptedField]` to an existing column, run `doctrine-crypt:encrypt` as part
of the same deployment. If a value that is really plaintext starts with `brng:` or `fips:`, the commands report it instead
of guessing.

Testing
-------

`vendor/bin/phpunit`. The tests need no database, as they use an in-memory driver that records what Doctrine writes.
