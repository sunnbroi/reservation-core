<?php

declare(strict_types=1);

namespace Reservation\Contracts;

use Reservation\Domain\{Booking, Slot};

interface BookingRepository
{
    public function lockRequest(string $customerId, string $key): void;
    public function findByKey(string $customerId, string $key): ?Booking;
    public function lockSlot(string $id): ?Slot;
    public function countOccupied(string $slotId): int;
    /** @return list<Booking> */
    public function expireHeld(string $slotId, \DateTimeImmutable $now): array;
    public function get(string $id, bool $lock = false): ?Booking;
    public function insert(Booking $booking): void;
    public function save(Booking $booking): void;
}
