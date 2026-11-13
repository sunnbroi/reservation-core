<?php

declare(strict_types=1);

namespace Reservation\Tests\Integration;

use Reservation\Domain\{BookingStatus, Money, PaymentSnapshot, PaymentStatus, RuleViolation};
use Reservation\Infrastructure\Postgres\Connection;
use Reservation\Support\{Runtime};
use Reservation\Tests\Support\DatabaseTestCase;

final class PaymentTest extends DatabaseTestCase
{
    public function testTimeoutAfterChargeCanBeRetriedWithoutChargingTwice(): void
    {
        $runtime = new Runtime(Connection::open(), Connection::open(), $this->clock, timeoutAfterCharge: true);
        $booking = $runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $runtime->bookings->requestPayment('customer-1', $booking->id);
        try {
            $runtime->payments->process($booking->id);
            self::fail('The first response must be lost after charging.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('AFTER', $error->getMessage());
        }
        self::assertSame(BookingStatus::PaymentPending, $runtime->repository->get($booking->id)->status);
        self::assertSame(1, $this->countRows('demo_provider_payments'));
        $runtime->payments->process($booking->id);
        $runtime->payments->process($booking->id);
        self::assertSame(BookingStatus::Confirmed, $runtime->repository->get($booking->id)->status);
        self::assertSame(1, $this->countRows('demo_provider_payments'));
        self::assertSame(1, $this->eventCount('booking.confirmed'));
    }

    public function testReconciliationRecoversAChargeWithNoResponse(): void
    {
        $runtime = new Runtime(Connection::open(), Connection::open(), $this->clock, timeoutAfterCharge: true);
        $booking = $runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $runtime->bookings->requestPayment('customer-1', $booking->id);
        try {
            $runtime->payments->process($booking->id);
        } catch (\RuntimeException) {
        }
        self::assertSame(BookingStatus::Confirmed, $runtime->payments->reconcile($booking->id)->status);
    }

    public function testProviderLookupDoesNotInventAFailedPayment(): void
    {
        $booking = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        try {
            $this->runtime->payments->reconcile($booking->id);
            self::fail('An unknown payment must remain unresolved.');
        } catch (RuleViolation $error) {
            self::assertSame('payment_unknown', $error->reason);
            self::assertSame(BookingStatus::PaymentPending, $this->runtime->repository->get($booking->id)->status);
        }
    }

    public function testWrongAmountRequiresReviewAndStillOccupiesCapacity(): void
    {
        $slot = $this->slot();
        $booking = $this->runtime->bookings->reserve('customer-1', $slot, 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        $result = $this->runtime->payments->apply($booking->id, new PaymentSnapshot('provider-1', $booking->id, new Money(1, 'RUB'), PaymentStatus::Paid));
        self::assertSame(BookingStatus::ReviewRequired, $result->status);
        self::assertSame(1, $this->runtime->repository->countOccupied($slot));
        self::assertSame(1, $this->eventCount('booking.review_required'));
        self::assertSame(0, $this->eventCount('booking.confirmed'));
    }

    public function testAuthoritativeFailureReleasesCapacity(): void
    {
        $slot = $this->slot();
        $booking = $this->runtime->bookings->reserve('customer-1', $slot, 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        $failed = $this->runtime->payments->apply($booking->id, new PaymentSnapshot('provider-1', $booking->id, $booking->price, PaymentStatus::Failed));
        self::assertSame(BookingStatus::PaymentFailed, $failed->status);
        self::assertSame(BookingStatus::Held, $this->runtime->bookings->reserve('customer-2', $slot, 'reserve-0002')->status);
    }

    public function testDuplicateSuccessCreatesOneConfirmationEvent(): void
    {
        $booking = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        $snapshot = new PaymentSnapshot('provider-1', $booking->id, $booking->price, PaymentStatus::Paid);
        $this->runtime->payments->apply($booking->id, $snapshot);
        $this->runtime->payments->apply($booking->id, $snapshot);
        self::assertSame(1, $this->eventCount('booking.confirmed'));
    }

    public function testNetworkIoIsNotInsideTheApplicationTransaction(): void
    {
        $booking = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        $gateway = new class ($this->runtime->pdo) implements \Reservation\Contracts\PaymentGateway {
            public function __construct(private \PDO $applicationConnection)
            {
            }
            public function create(string $merchantReference, Money $money): PaymentSnapshot
            {
                if ($this->applicationConnection->inTransaction()) {
                    throw new \LogicException('Network I/O inside a transaction.');
                }
                return new PaymentSnapshot('provider-1', $merchantReference, $money, PaymentStatus::Paid);
            }
            public function find(string $merchantReference): ?PaymentSnapshot
            {
                return null;
            }
        };
        $payments = new \Reservation\Application\PaymentService($this->runtime->transaction, $this->runtime->repository, $this->runtime->outbox, $gateway);
        $payments->process($booking->id);
        self::assertSame(BookingStatus::Confirmed, $this->runtime->repository->get($booking->id)->status);
    }
}
