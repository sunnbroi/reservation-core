<?php

declare(strict_types=1);

namespace Reservation\Tests\Integration;

use Reservation\Tests\Support\DatabaseTestCase;

final class ConcurrencyTest extends DatabaseTestCase
{
    private function race(array $requests): array
    {
        $children = [];
        try {
            foreach ($requests as $arguments) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__).'/Support/concurrent-request.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                stream_set_timeout($pipes[1], 10);
                $children[] = ['process' => $process, 'pipes' => $pipes];
                self::assertSame("ready\n", fgets($pipes[1]), 'Child failed before reaching the process barrier.');
            }
            // Release only after EVERY process established its own database connections.
            foreach ($children as $child) {
                fwrite($child['pipes'][0], "go\n");
                fflush($child['pipes'][0]);
            }
            $results = [];
            foreach ($children as $child) {
                $line = fgets($child['pipes'][1]);
                self::assertIsString($line, 'Concurrent request timed out.');
                $results[] = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($children as $child) {
                fclose($child['pipes'][0]);
                fclose($child['pipes'][1]);
                $errors = stream_get_contents($child['pipes'][2]);
                fclose($child['pipes'][2]);
                $status = proc_get_status($child['process']);
                if ($status['running']) {
                    proc_terminate($child['process']);
                }
                proc_close($child['process']);
                self::assertSame('', $errors, 'Child emitted an unexpected runtime error.');
            }
        }
    }

    public function testEightProcessesCompeteForOnePlace(): void
    {
        $slot = $this->slot();
        $requests = [];
        for ($i = 0; $i < 8; $i++) {
            $requests[] = ['reserve', $slot, 'customer-'.$i, 'reserve-000'.$i];
        }
        $results = $this->race($requests);
        self::assertCount(1, array_filter($results, fn ($r) => $r['ok']));
        self::assertCount(7, array_filter($results, fn ($r) => ($r['error'] ?? '') === 'slot_full'));
        self::assertSame(1, $this->countRows('bookings'));
        self::assertSame(1, $this->runtime->repository->countOccupied($slot));
    }

    public function testConcurrentRetriesCreateOneBooking(): void
    {
        $slot = $this->slot();
        $results = $this->race(array_fill(0, 6, ['reserve', $slot, 'same-customer', 'same-request-001']));
        self::assertCount(6, array_filter($results, fn ($r) => $r['ok']));
        self::assertCount(1, array_unique(array_column($results, 'value')));
        self::assertSame(1, $this->countRows('bookings'));
        self::assertSame(1, $this->eventCount('booking.held'));
    }

    public function testSameKeyForDifferentSlotsHasOneWinner(): void
    {
        $results = $this->race([
            ['reserve', $this->slot(), 'same-customer', 'same-request-001'],
            ['reserve', $this->slot(), 'same-customer', 'same-request-001'],
        ]);
        self::assertCount(1, array_filter($results, fn ($r) => $r['ok']));
        self::assertCount(1, array_filter($results, fn ($r) => ($r['error'] ?? '') === 'idempotency_conflict'));
        self::assertSame(1, $this->countRows('bookings'));
    }

    public function testConcurrentPaymentExecutionCreatesOneProviderCharge(): void
    {
        $booking = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        $results = $this->race(array_fill(0, 6, ['pay', $booking->id]));
        self::assertCount(6, array_filter($results, fn ($r) => $r['ok'] && $r['value'] === 'confirmed'));
        self::assertSame(1, $this->countRows('demo_provider_payments'));
        self::assertSame(1, $this->eventCount('booking.confirmed'));
    }

    public function testWorkerSkipsARowLockedByAnotherConnection(): void
    {
        $first = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $second = $this->runtime->bookings->reserve('customer-2', $this->slot(), 'reserve-0002');
        $firstEvent = $this->runtime->pdo->query("SELECT id FROM outbox WHERE aggregate_id = '".$first->id."'")->fetchColumn();
        $secondEvent = $this->runtime->pdo->query("SELECT id FROM outbox WHERE aggregate_id = '".$second->id."'")->fetchColumn();
        $this->runtime->pdo->beginTransaction();
        try {
            $statement = $this->runtime->pdo->prepare('SELECT id FROM outbox WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $firstEvent]);
            $results = $this->race([['claim']]);
            self::assertSame($secondEvent, $results[0]['value']);
        } finally {
            $this->runtime->pdo->rollBack();
        }
    }
}
