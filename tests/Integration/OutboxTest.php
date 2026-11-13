<?php

declare(strict_types=1);

namespace Reservation\Tests\Integration;

use Reservation\Application\OutboxWorker;
use Reservation\Infrastructure\Demo\DemoNotificationSink;
use Reservation\Infrastructure\Postgres\{Connection, PgOutbox, PgTransaction};
use Reservation\Tests\Support\DatabaseTestCase;

final class OutboxTest extends DatabaseTestCase
{
    private function enqueue(): void
    {
        $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
    }

    public function testClaimedMessageIsInvisibleToAnotherWorkerUntilItsLeaseExpires(): void
    {
        $this->enqueue();
        $first = $this->runtime->outbox->claim();
        self::assertNotNull($first);
        $otherConnection = Connection::open();
        $other = new PgOutbox($otherConnection, new PgTransaction($otherConnection));
        self::assertNull($other->claim());
        $this->runtime->pdo->exec("UPDATE outbox SET locked_until = clock_timestamp() - interval '1 second'");
        $reclaimed = $other->claim();
        self::assertSame($first->id, $reclaimed->id);
        self::assertNotSame($first->leaseToken, $reclaimed->leaseToken);
        self::assertFalse($this->runtime->outbox->complete($first));
        self::assertTrue($other->complete($reclaimed));
    }

    public function testCrashAfterExternalAcceptanceDoesNotDuplicateNotification(): void
    {
        $this->enqueue();
        $sink = new DemoNotificationSink(Connection::open());
        $first = $this->runtime->outbox->claim();
        $sink->accept($first);
        // Simulate termination before acknowledge. No fail() callback is executed.
        $this->runtime->pdo->exec("UPDATE outbox SET locked_until = clock_timestamp() - interval '1 second'");
        $retry = $this->runtime->outbox->claim();
        $sink->accept($retry);
        $this->runtime->outbox->complete($retry);
        self::assertSame(1, $this->countRows('demo_notifications'));
    }

    public function testFailuresHaveBackoffAndEventuallyBecomeDead(): void
    {
        $this->enqueue();
        $queue = new PgOutbox($this->runtime->pdo, $this->runtime->transaction, maxAttempts: 2);
        $first = $queue->claim();
        $queue->fail($first, new \RuntimeException('secret-upstream-value'));
        self::assertNull($queue->claim());
        self::assertSame(\RuntimeException::class, $this->runtime->pdo->query('SELECT last_error FROM outbox')->fetchColumn());
        $this->runtime->pdo->exec("UPDATE outbox SET available_at = clock_timestamp() - interval '1 second'");
        $second = $queue->claim();
        $queue->fail($second, new \RuntimeException());
        self::assertSame('dead', $this->runtime->pdo->query('SELECT status FROM outbox')->fetchColumn());
        self::assertNull($queue->claim());
    }

    public function testCrashOnTheLastAttemptIsNotStrandedForever(): void
    {
        $this->enqueue();
        $queue = new PgOutbox($this->runtime->pdo, $this->runtime->transaction, maxAttempts: 1);
        self::assertNotNull($queue->claim());
        $this->runtime->pdo->exec("UPDATE outbox SET locked_until = clock_timestamp() - interval '1 second'");
        self::assertNull($queue->claim());
        self::assertSame('dead', $this->runtime->pdo->query('SELECT status FROM outbox')->fetchColumn());
    }

    public function testWorkerCompletesARealMessage(): void
    {
        $this->enqueue();
        self::assertTrue($this->runtime->worker->runOne());
        self::assertFalse($this->runtime->worker->runOne());
        self::assertSame('processed', $this->runtime->pdo->query('SELECT status FROM outbox')->fetchColumn());
        self::assertSame(1, $this->countRows('demo_notifications'));
    }

    public function testWorkerSchedulesRetryAfterHandlerFailure(): void
    {
        $this->enqueue();
        $worker = new OutboxWorker($this->runtime->outbox, static function (): void {
            throw new \RuntimeException('Synthetic failure.');
        });
        self::assertTrue($worker->runOne());
        self::assertSame('pending', $this->runtime->pdo->query('SELECT status FROM outbox')->fetchColumn());
        self::assertSame(1, (int) $this->runtime->pdo->query('SELECT attempts FROM outbox')->fetchColumn());
    }
}
