<?php

declare(strict_types=1);

namespace FwsDoctrineCrypt\Test\Support;

/**
 * Tables, rows and the log of writes. Survives across entity managers, like a real database.
 */
final class InMemoryDatabase
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $writes = [];

    public int $lastId = 0;

    /** When true the next INSERT or UPDATE fails, to simulate a database error during flush. */
    public bool $failNextWrite = false;

    /**
     * @return list<array<string, mixed>> Values of the recorded writes to a table
     */
    public function writesTo(string $operation, string $table): array
    {
        $writes = [];
        foreach ($this->writes as [$writeOperation, $writeTable, $values]) {
            if ($writeOperation === $operation && $writeTable === $table) {
                $writes[] = $values;
            }
        }

        return $writes;
    }
}
