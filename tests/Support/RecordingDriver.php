<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * Minimal in-memory DBAL driver that understands the simple SQL the ORM generates for the test entities.
 *
 * The tests care about the values Doctrine sends to the database, so this records every INSERT and UPDATE
 * and does not need a database server or the pdo_sqlite extension.
 */
final class RecordingDriver extends AbstractSQLiteDriver
{
    public function connect(array $params): Connection
    {
        return new RecordingConnection($params['driverOptions']['database']);
    }
}

final class RecordingConnection implements Connection
{
    public function __construct(private readonly InMemoryDatabase $database)
    {
    }

    public function prepare(string $sql): Statement
    {
        return new RecordingStatement($this->database, $sql);
    }

    public function query(string $sql): Result
    {
        return $this->prepare($sql)->execute();
    }

    public function quote($value, $type = ParameterType::STRING): string
    {
        return "'" . $value . "'";
    }

    public function exec(string $sql): int
    {
        return 0;
    }

    public function lastInsertId($name = null): int
    {
        return $this->database->lastId;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        return true;
    }

    public function getNativeConnection(): ?object
    {
        return null;
    }
}

final class RecordingStatement implements Statement
{
    /** @var array<int, mixed> */
    private array $parameters = [];

    public function __construct(private readonly InMemoryDatabase $database, private readonly string $sql)
    {
    }

    public function bindValue($param, $value, $type = ParameterType::STRING): bool
    {
        $this->parameters[$param] = $value;

        return true;
    }

    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
    {
        $this->parameters[$param] = $variable;

        return true;
    }

    public function execute($params = null): Result
    {
        foreach (array_values($params ?? []) as $index => $value) {
            $this->parameters[$index + 1] = $value;
        }
        ksort($this->parameters);
        $values = array_values($this->parameters);

        if (preg_match('/^INSERT INTO (\S+) \((.*?)\)/', $this->sql, $match)) {
            $this->failIfRequested();
            $row = array_combine(array_map('trim', explode(',', $match[2])), $values);
            $row['id'] = ++$this->database->lastId;
            $this->database->tables[$match[1]][$row['id']] = $row;
            $this->database->writes[] = ['INSERT', $match[1], $row];

            return new ArrayResult([]);
        }

        if (preg_match('/^UPDATE (\S+) SET (.*?) WHERE/', $this->sql, $match)) {
            $this->failIfRequested();
            preg_match_all('/(\w+) = \?/', $match[2], $columns);
            $id = end($values);
            $changed = [];
            foreach ($columns[1] as $index => $column) {
                $changed[$column] = $values[$index];
                $this->database->tables[$match[1]][$id][$column] = $values[$index];
            }
            $this->database->writes[] = ['UPDATE', $match[1], $changed];

            return new ArrayResult([]);
        }

        return $this->select($values);
    }

    private function failIfRequested(): void
    {
        if ($this->database->failNextWrite) {
            $this->database->failNextWrite = false;
            throw new SimulatedDatabaseFailure('Simulated database failure');
        }
    }

    /**
     * @param list<mixed> $values
     */
    private function select(array $values): Result
    {
        preg_match('/ FROM (\w+)/', $this->sql, $match);
        $rows = $this->database->tables[$match[1]] ?? [];

        if (preg_match('/COUNT\(.*?\) AS (\w+)/', $this->sql, $count)) {
            return new ArrayResult([[$count[1] => count($rows)]]);
        }

        if ($values && preg_match('/WHERE .*id = \?/', $this->sql)) {
            $rows = array_filter($rows, static fn (array $row): bool => $row['id'] == $values[0]);
        }

        preg_match_all('/\w+\.(\w+) AS (\w+)/', $this->sql, $aliases);
        $result = [];
        foreach ($rows as $row) {
            $aliased = [];
            foreach ($aliases[1] as $index => $column) {
                $aliased[$aliases[2][$index]] = $row[$column] ?? null;
            }
            $result[] = $aliased;
        }

        return new ArrayResult($result);
    }
}
