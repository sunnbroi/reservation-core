<?php

declare(strict_types=1);

namespace Reservation\Infrastructure\Postgres;

use Reservation\Contracts\BookingRepository;
use Reservation\Domain\{Booking, BookingStatus, Money, Slot};

final readonly class PgBookings implements BookingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function lockRequest(string $customerId, string $key): void
    {
        $statement = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:value, 0))');
        $statement->execute(['value' => json_encode([$customerId, $key], JSON_THROW_ON_ERROR)]);
    }

    public function findByKey(string $customerId, string $key): ?Booking
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookings WHERE customer_id = :customer AND idempotency_key = :key');
        $statement->execute(['customer' => $customerId, 'key' => $key]);
        $row = $statement->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    public function lockSlot(string $id): ?Slot
    {
        $statement = $this->pdo->prepare('SELECT * FROM slots WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row === false ? null : new Slot($row['id'], (int) $row['capacity'], new Money((int) $row['price_minor'], $row['currency']), new \DateTimeImmutable($row['starts_at']));
    }

    public function countOccupied(string $slotId): int
    {
        $statement = $this->pdo->prepare("SELECT count(*) FROM bookings WHERE slot_id = :slot AND status IN ('held', 'payment_pending', 'confirmed', 'review_required')");
        $statement->execute(['slot' => $slotId]);
        return (int) $statement->fetchColumn();
    }

    public function expireHeld(string $slotId, \DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare("UPDATE bookings SET status = 'expired', updated_at = clock_timestamp() WHERE slot_id = :slot AND status = 'held' AND expires_at <= :now RETURNING *");
        $statement->execute(['slot' => $slotId, 'now' => $now->format('Y-m-d H:i:s.uP')]);
        return array_map(self::hydrate(...), $statement->fetchAll());
    }

    public function get(string $id, bool $lock = false): ?Booking
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookings WHERE id = :id'.($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    public function insert(Booking $booking): void
    {
        $statement = $this->pdo->prepare('INSERT INTO bookings (id, slot_id, customer_id, idempotency_key, status, price_minor, currency, expires_at, payment_id) VALUES (:id, :slot, :customer, :key, :status, :price, :currency, :expires, :payment)');
        $statement->execute([
            'id' => $booking->id, 'slot' => $booking->slotId, 'customer' => $booking->customerId,
            'key' => $booking->idempotencyKey, 'status' => $booking->status->value,
            'price' => $booking->price->minor, 'currency' => $booking->price->currency,
            'expires' => $booking->expiresAt->format('Y-m-d H:i:s.uP'), 'payment' => $booking->paymentId,
        ]);
    }

    public function save(Booking $booking): void
    {
        $statement = $this->pdo->prepare('UPDATE bookings SET status = :status, payment_id = :payment, updated_at = clock_timestamp() WHERE id = :id');
        $statement->execute(['id' => $booking->id, 'status' => $booking->status->value, 'payment' => $booking->paymentId]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Booking
    {
        return new Booking($row['id'], $row['slot_id'], $row['customer_id'], $row['idempotency_key'], BookingStatus::from($row['status']), new Money((int) $row['price_minor'], $row['currency']), new \DateTimeImmutable($row['expires_at']), $row['payment_id']);
    }
}
