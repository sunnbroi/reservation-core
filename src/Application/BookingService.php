<?php

declare(strict_types=1);

namespace Reservation\Application;

use Reservation\Contracts\{BookingRepository, Clock, EventStore, Transaction};
use Reservation\Domain\{Booking, BookingStatus, RuleViolation};
use Reservation\Support\Uuid;

final readonly class BookingService
{
    public function __construct(
        private Transaction $transaction,
        private BookingRepository $bookings,
        private EventStore $events,
        private Clock $clock,
        private int $holdSeconds = 600,
    ) {
        if ($holdSeconds < 1 || $holdSeconds > 86400) {
            throw new \InvalidArgumentException('Hold duration must be between 1 and 86400 seconds.');
        }
    }

    public function reserve(string $customerId, string $slotId, string $key): Booking
    {
        if ($customerId === '' || strlen($customerId) > 100 || !Uuid::valid($slotId) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key)) {
            throw new RuleViolation('invalid_request', 'A valid slot UUID and an 8–128 character idempotency key are required.', 422);
        }

        return $this->transaction->run(function () use ($customerId, $slotId, $key): Booking {
            // Same customer + key is serialized even when the requests target different slots.
            $this->bookings->lockRequest($customerId, $key);
            $existing = $this->bookings->findByKey($customerId, $key);
            if ($existing !== null) {
                if ($existing->slotId !== $slotId) {
                    throw new RuleViolation('idempotency_conflict', 'This key was already used with a different slot.');
                }
                return $existing;
            }

            $slot = $this->bookings->lockSlot($slotId)
                ?? throw new RuleViolation('slot_not_found', 'Slot not found.', 404);
            // Read time AFTER acquiring the lock: a waiting request must not use stale time.
            $now = $this->clock->now();
            if ($slot->startsAt <= $now) {
                throw new RuleViolation('slot_started', 'The slot has already started.');
            }
            foreach ($this->bookings->expireHeld($slotId, $now) as $expired) {
                $this->events->append('booking.expired', $expired);
            }
            if ($this->bookings->countOccupied($slotId) >= $slot->capacity) {
                throw new RuleViolation('slot_full', 'No places remain in this slot.');
            }

            $booking = new Booking(Uuid::make(), $slotId, $customerId, $key, BookingStatus::Held, $slot->price, $now->modify('+'.$this->holdSeconds.' seconds'));
            $this->bookings->insert($booking);
            $this->events->append('booking.held', $booking);
            return $booking;
        });
    }

    public function requestPayment(string $customerId, string $bookingId): Booking
    {
        return $this->transaction->run(function () use ($customerId, $bookingId): Booking {
            $current = $this->owned($customerId, $bookingId, true);
            $next = $current->requestPayment($this->clock->now());
            if ($next->status !== $current->status) {
                $this->bookings->save($next);
                $this->events->append('payment.requested', $next);
            }
            return $next;
        });
    }

    public function owned(string $customerId, string $bookingId, bool $lock = false): Booking
    {
        if (!Uuid::valid($bookingId)) {
            throw new RuleViolation('booking_not_found', 'Reservation not found.', 404);
        }
        $booking = $this->bookings->get($bookingId, $lock);
        if ($booking === null || $booking->customerId !== $customerId) {
            throw new RuleViolation('booking_not_found', 'Reservation not found.', 404);
        }
        return $booking;
    }

    public function expireSlot(string $slotId): int
    {
        return $this->transaction->run(function () use ($slotId): int {
            if ($this->bookings->lockSlot($slotId) === null) {
                return 0;
            }
            $expired = $this->bookings->expireHeld($slotId, $this->clock->now());
            foreach ($expired as $booking) {
                $this->events->append('booking.expired', $booking);
            }
            return count($expired);
        });
    }
}
