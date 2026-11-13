<?php

declare(strict_types=1);

namespace Reservation\Application;

use Reservation\Contracts\{BookingRepository, EventStore, PaymentGateway, Transaction};
use Reservation\Domain\{Booking, BookingStatus, PaymentSnapshot, PaymentStatus, RuleViolation};
use Reservation\Support\Uuid;

final readonly class PaymentService
{
    public function __construct(
        private Transaction $transaction,
        private BookingRepository $bookings,
        private EventStore $events,
        private PaymentGateway $gateway,
    ) {
    }

    public function process(string $bookingId): void
    {
        $booking = $this->get($bookingId);
        if (in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::PaymentFailed], true)) {
            return;
        }
        if ($booking->status !== BookingStatus::PaymentPending) {
            throw new RuleViolation('payment_not_pending', 'Payment requires reconciliation or manual review.');
        }
        // Provider I/O happens outside a database transaction. A timeout leaves the seat occupied.
        $snapshot = $this->gateway->create($booking->id, $booking->price);
        $this->apply($bookingId, $snapshot);
        if ($snapshot->status === PaymentStatus::Pending) {
            throw new \RuntimeException('Provider result is pending; retry or reconcile later.');
        }
    }

    public function reconcile(string $bookingId): Booking
    {
        $this->get($bookingId);
        $snapshot = $this->gateway->find($bookingId);
        if ($snapshot === null) {
            throw new RuleViolation('payment_unknown', 'Provider has no authoritative result; reservation remains occupied.');
        }
        return $this->apply($bookingId, $snapshot);
    }

    public function apply(string $bookingId, PaymentSnapshot $snapshot): Booking
    {
        return $this->transaction->run(function () use ($bookingId, $snapshot): Booking {
            $current = $this->bookings->get($bookingId, true)
                ?? throw new RuleViolation('booking_not_found', 'Reservation not found.', 404);
            $next = $current->applyPayment($snapshot);
            $this->bookings->save($next);
            if ($current->status !== $next->status) {
                $this->events->append('booking.'.$next->status->value, $next);
            }
            return $next;
        });
    }

    private function get(string $id): Booking
    {
        if (!Uuid::valid($id)) {
            throw new RuleViolation('booking_not_found', 'Reservation not found.', 404);
        }
        return $this->bookings->get($id)
            ?? throw new RuleViolation('booking_not_found', 'Reservation not found.', 404);
    }
}
