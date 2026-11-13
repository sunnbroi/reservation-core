<?php

declare(strict_types=1);

namespace Reservation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reservation\Domain\{Booking, BookingStatus, Money, PaymentSnapshot, PaymentStatus, RuleViolation};
use Reservation\Support\Uuid;

final class BookingTest extends TestCase
{
    private function held(): Booking
    {
        return new Booking(Uuid::make(), Uuid::make(), 'customer-1', 'request-123', BookingStatus::Held, new Money(250000, 'RUB'), new \DateTimeImmutable('2030-01-01T12:10:00Z'));
    }

    public function testPaymentStartsBeforeTheDeadline(): void
    {
        self::assertSame(BookingStatus::PaymentPending, $this->held()->requestPayment(new \DateTimeImmutable('2030-01-01T12:09:59Z'))->status);
    }

    public function testPaymentCannotStartAtTheDeadline(): void
    {
        $this->expectException(RuleViolation::class);
        $this->held()->requestPayment(new \DateTimeImmutable('2030-01-01T12:10:00Z'));
    }

    public function testPaymentCannotBeAppliedBeforeItWasRequested(): void
    {
        $booking = $this->held();
        $this->expectException(RuleViolation::class);
        $booking->applyPayment(new PaymentSnapshot('provider-1', $booking->id, $booking->price, PaymentStatus::Paid));
    }

    public function testAuthoritativePaidResultConfirmsReservation(): void
    {
        $booking = $this->held()->withStatus(BookingStatus::PaymentPending);
        $result = $booking->applyPayment(new PaymentSnapshot('provider-1', $booking->id, $booking->price, PaymentStatus::Paid));
        self::assertSame(BookingStatus::Confirmed, $result->status);
        self::assertSame('provider-1', $result->paymentId);
    }

    public function testWrongAmountRequiresReview(): void
    {
        $booking = $this->held()->withStatus(BookingStatus::PaymentPending);
        self::assertSame(BookingStatus::ReviewRequired, $booking->applyPayment(new PaymentSnapshot('provider-1', $booking->id, new Money(1, 'RUB'), PaymentStatus::Paid))->status);
    }

    public function testWrongCurrencyRequiresReview(): void
    {
        $booking = $this->held()->withStatus(BookingStatus::PaymentPending);
        self::assertSame(BookingStatus::ReviewRequired, $booking->applyPayment(new PaymentSnapshot('provider-1', $booking->id, new Money(250000, 'USD'), PaymentStatus::Paid))->status);
    }

    public function testWrongMerchantReferenceRequiresReview(): void
    {
        $booking = $this->held()->withStatus(BookingStatus::PaymentPending);
        self::assertSame(BookingStatus::ReviewRequired, $booking->applyPayment(new PaymentSnapshot('provider-1', Uuid::make(), $booking->price, PaymentStatus::Paid))->status);
    }

    public function testStalePendingResultCannotUndoConfirmation(): void
    {
        $booking = $this->held()->withStatus(BookingStatus::Confirmed, 'provider-1');
        self::assertSame($booking, $booking->applyPayment(new PaymentSnapshot('provider-1', $booking->id, $booking->price, PaymentStatus::Pending)));
    }

    public function testFailedResultIsTerminalAndCannotLaterConfirm(): void
    {
        $booking = $this->held()->withStatus(BookingStatus::PaymentFailed, 'provider-1');
        $this->expectException(RuleViolation::class);
        $booking->applyPayment(new PaymentSnapshot('provider-1', $booking->id, $booking->price, PaymentStatus::Paid));
    }

    public function testMoneyRejectsNonPositiveAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(0, 'RUB');
    }

    public function testUuidGeneratorProducesVersionFourIds(): void
    {
        self::assertTrue(Uuid::valid(Uuid::make()));
        self::assertFalse(Uuid::valid('not-a-uuid'));
    }
}
