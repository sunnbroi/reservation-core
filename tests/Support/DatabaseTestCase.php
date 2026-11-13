<?php

declare(strict_types=1);

namespace Reservation\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reservation\Infrastructure\Postgres\Connection;
use Reservation\Support\{Runtime, Uuid};

abstract class DatabaseTestCase extends TestCase
{
    protected Runtime $runtime;
    protected FrozenClock $clock;
    protected string $schema;
    protected string $dsn;
    private \PDO $admin;
    private string|false $previousSchema;
    private string|false $previousDsn;

    protected function setUp(): void
    {
        $this->dsn = getenv('TEST_DATABASE_DSN') ?: '';
        if ($this->dsn === '') {
            $this->markTestSkipped('Set TEST_DATABASE_DSN to run the real PostgreSQL integration suite.');
        }
        $this->previousSchema = getenv('DATABASE_SCHEMA');
        $this->previousDsn = getenv('DATABASE_DSN');
        $this->schema = 'test_'.str_replace('-', '', Uuid::make());
        $this->admin = Connection::open($this->dsn, 'public');
        $this->admin->exec('CREATE SCHEMA "'.$this->schema.'"');
        putenv('DATABASE_SCHEMA='.$this->schema);
        putenv('DATABASE_DSN='.$this->dsn);
        $pdo = Connection::open();
        $pdo->exec(file_get_contents(dirname(__DIR__, 2).'/database/001_initial.sql'));
        $this->clock = new FrozenClock(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->runtime = new Runtime($pdo, Connection::open(), $this->clock);
    }

    protected function tearDown(): void
    {
        if (!isset($this->admin)) {
            return;
        }
        if ($this->runtime->pdo->inTransaction()) {
            $this->runtime->pdo->rollBack();
        }
        $this->admin->exec('DROP SCHEMA "'.$this->schema.'" CASCADE');
        putenv($this->previousSchema === false ? 'DATABASE_SCHEMA' : 'DATABASE_SCHEMA='.$this->previousSchema);
        putenv($this->previousDsn === false ? 'DATABASE_DSN' : 'DATABASE_DSN='.$this->previousDsn);
    }

    protected function slot(int $capacity = 1): string
    {
        $id = Uuid::make();
        $statement = $this->runtime->pdo->prepare("INSERT INTO slots (id, title, capacity, price_minor, currency, starts_at) VALUES (:id, 'Synthetic capacity test', :capacity, 250000, 'RUB', :starts)");
        $statement->execute(['id' => $id, 'capacity' => $capacity, 'starts' => $this->clock->now()->modify('+1 day')->format(DATE_ATOM)]);
        return $id;
    }

    protected function countRows(string $table): int
    {
        if (!in_array($table, ['bookings', 'outbox', 'demo_provider_payments', 'demo_notifications'], true)) {
            throw new \InvalidArgumentException('Unknown test table.');
        }
        return (int) $this->runtime->pdo->query('SELECT count(*) FROM '.$table)->fetchColumn();
    }

    protected function eventCount(string $type): int
    {
        $statement = $this->runtime->pdo->prepare('SELECT count(*) FROM outbox WHERE type = :type');
        $statement->execute(['type' => $type]);
        return (int) $statement->fetchColumn();
    }
}
