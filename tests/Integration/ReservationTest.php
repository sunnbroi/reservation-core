<?php

declare(strict_types=1);

namespace Reservation\Tests\Integration;

use Reservation\Contracts\EventStore;
use Reservation\Application\BookingService;
use Reservation\Domain\{Booking, BookingStatus, RuleViolation};
use Reservation\Tests\Support\DatabaseTestCase;

final class ReservationTest extends DatabaseTestCase
{
    public function testRetryReturnsTheSameBookingAndOneEvent(): void
    {
        $slot = $this->slot();
        $first = $this->runtime->bookings->reserve('customer-1', $slot, 'reserve-0001');
        $retry = $this->runtime->bookings->reserve('customer-1', $slot, 'reserve-0001');
        self::assertSame($first->id, $retry->id);
        self::assertSame(1, $this->countRows('bookings'));
        self::assertSame(1, $this->eventCount('booking.held'));
    }

    public function testKeyCannotBeReusedWithAnotherSlot(): void
    {
        $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $this->expectException(RuleViolation::class);
        $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
    }

    public function testIdempotencyKeysAreScopedToTheAuthenticatedCustomer(): void
    {
        $slot = $this->slot(2);
        $first = $this->runtime->bookings->reserve('customer-1', $slot, 'reserve-0001');
        $second = $this->runtime->bookings->reserve('customer-2', $slot, 'reserve-0001');
        self::assertNotSame($first->id, $second->id);
    }

    public function testExpiredUnpaidHoldReleasesCapacityOnNextReservation(): void
    {
        $slot = $this->slot();
        $first = $this->runtime->bookings->reserve('customer-1', $slot, 'reserve-0001');
        $this->clock->time = $this->clock->time->modify('+601 seconds');
        $second = $this->runtime->bookings->reserve('customer-2', $slot, 'reserve-0002');
        self::assertNotSame($first->id, $second->id);
        self::assertSame(BookingStatus::Expired, $this->runtime->repository->get($first->id)->status);
        self::assertSame(1, $this->eventCount('booking.expired'));
    }

    public function testPendingPaymentDoesNotExpireEvenAfterHoldDeadline(): void
    {
        $slot = $this->slot();
        $booking = $this->runtime->bookings->reserve('customer-1', $slot, 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        $this->clock->time = $this->clock->time->modify('+1 hour');
        self::assertSame(0, $this->runtime->bookings->expireSlot($slot));
        try {
            $this->runtime->bookings->reserve('customer-2', $slot, 'reserve-0002');
            self::fail('An uncertain payment must keep its place.');
        } catch (RuleViolation $error) {
            self::assertSame('slot_full', $error->reason);
        }
    }

    public function testRequestingPaymentTwiceQueuesOneCommand(): void
    {
        $booking = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        self::assertSame(1, $this->eventCount('payment.requested'));
    }

    public function testEventFailureRollsBackTheBusinessWrite(): void
    {
        $brokenEvents = new class () implements EventStore {
            public function append(string $type, Booking $booking): void
            {
                throw new \RuntimeException('Outbox storage failed.');
            }
        };
        $service = new BookingService($this->runtime->transaction, $this->runtime->repository, $brokenEvents, $this->clock);
        try {
            $service->reserve('customer-1', $this->slot(), 'reserve-0001');
            self::fail('Expected the simulated outbox failure.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->countRows('bookings'));
            self::assertSame(0, $this->countRows('outbox'));
        }
    }

    public function testCustomerCannotReadOrPayAnotherCustomersBooking(): void
    {
        $booking = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $this->expectException(RuleViolation::class);
        $this->runtime->bookings->requestPayment('customer-2', $booking->id);
    }
}
