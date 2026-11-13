<?php

declare(strict_types=1);

namespace Reservation\Infrastructure\Postgres;

use Reservation\Contracts\{EventStore, OutboxQueue};
use Reservation\Application\OutboxMessage;
use Reservation\Domain\Booking;
use Reservation\Support\Uuid;

final readonly class PgOutbox implements EventStore, OutboxQueue
{
    public function __construct(private \PDO $pdo, private PgTransaction $transaction, private int $maxAttempts = 5, private int $leaseSeconds = 30)
    {
        if ($maxAttempts < 1 || $leaseSeconds < 1) {
            throw new \InvalidArgumentException('Retry and lease limits must be positive.');
        }
    }

    public function append(string $type, Booking $booking): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Domain events must be written in the business transaction.');
        }
        $statement = $this->pdo->prepare('INSERT INTO outbox (id, aggregate_id, type, payload) VALUES (:id, :aggregate, :type, :payload) ON CONFLICT (aggregate_id, type) DO NOTHING');
        $statement->execute([
            'id' => Uuid::make(), 'aggregate' => $booking->id, 'type' => $type,
            'payload' => json_encode(['booking_id' => $booking->id, 'status' => $booking->status->value], JSON_THROW_ON_ERROR),
        ]);
    }

    public function claim(): ?OutboxMessage
    {
        return $this->transaction->run(function (): ?OutboxMessage {
            // A worker can crash on its final attempt, without entering fail().
            $cleanup = $this->pdo->prepare("UPDATE outbox SET status = 'dead', lease_token = NULL, locked_until = NULL, last_error = 'Lease expired on final attempt' WHERE status = 'processing' AND locked_until <= clock_timestamp() AND attempts >= :max");
            $cleanup->execute(['max' => $this->maxAttempts]);
            $statement = $this->pdo->prepare("SELECT id, aggregate_id, type, attempts FROM outbox WHERE attempts < :max AND ((status = 'pending' AND available_at <= clock_timestamp()) OR (status = 'processing' AND locked_until <= clock_timestamp())) ORDER BY available_at, created_at, id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $statement->execute(['max' => $this->maxAttempts]);
            $row = $statement->fetch();
            if ($row === false) {
                return null;
            }
            $token = Uuid::make();
            $update = $this->pdo->prepare("UPDATE outbox SET status = 'processing', attempts = attempts + 1, lease_token = :token, locked_until = clock_timestamp() + make_interval(secs => CAST(:lease AS integer)) WHERE id = :id");
            $update->execute(['token' => $token, 'lease' => $this->leaseSeconds, 'id' => $row['id']]);
            return new OutboxMessage($row['id'], $row['aggregate_id'], $row['type'], $token, (int) $row['attempts'] + 1);
        });
    }

    public function complete(OutboxMessage $message): bool
    {
        $statement = $this->pdo->prepare("UPDATE outbox SET status = 'processed', lease_token = NULL, locked_until = NULL, last_error = NULL WHERE id = :id AND status = 'processing' AND lease_token = :token");
        $statement->execute(['id' => $message->id, 'token' => $message->leaseToken]);
        return $statement->rowCount() === 1;
    }

    public function fail(OutboxMessage $message, \Throwable $error): bool
    {
        $statement = $this->pdo->prepare("UPDATE outbox SET status = :status, lease_token = NULL, locked_until = NULL, available_at = clock_timestamp() + make_interval(secs => CAST(:delay AS integer)), last_error = :error WHERE id = :id AND status = 'processing' AND lease_token = :token");
        $statement->execute([
            'status' => $message->attempt >= $this->maxAttempts ? 'dead' : 'pending',
            'delay' => min(60, 2 ** min($message->attempt, 6)),
            // Do not persist arbitrary upstream messages: they can contain credentials or personal data.
            'error' => substr($error::class, 0, 200), 'id' => $message->id, 'token' => $message->leaseToken,
        ]);
        return $statement->rowCount() === 1;
    }
}
