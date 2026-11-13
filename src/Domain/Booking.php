<?php

declare(strict_types=1);

namespace Reservation\Domain;

final readonly class Booking
{
    public function __construct(
        public string $id,
        public string $slotId,
        public string $customerId,
        public string $idempotencyKey,
        public BookingStatus $status,
        public Money $price,
        public \DateTimeImmutable $expiresAt,
        public ?string $paymentId = null,
    ) {
    }

    public function requestPayment(\DateTimeImmutable $now): self
    {
        if (in_array($this->status, [BookingStatus::PaymentPending, BookingStatus::Confirmed, BookingStatus::ReviewRequired], true)) {
            return $this;
        }
        if ($this->status !== BookingStatus::Held || $this->expiresAt <= $now) {
            throw new RuleViolation('reservation_unavailable', 'The reservation is no longer payable.');
        }

        return $this->withStatus(BookingStatus::PaymentPending);
    }

    public function applyPayment(PaymentSnapshot $payment): self
    {
        if (!in_array($this->status, [BookingStatus::PaymentPending, BookingStatus::ReviewRequired, BookingStatus::Confirmed, BookingStatus::PaymentFailed], true)) {
            throw new RuleViolation('invalid_payment_state', 'Payment was not requested for this reservation.');
        }

        $matches = $payment->merchantReference === $this->id
            && $payment->money->equals($this->price)
            && ($this->paymentId === null || $this->paymentId === $payment->id);

        // Terminal provider results must not regress when older responses arrive late.
        if (in_array($this->status, [BookingStatus::Confirmed, BookingStatus::PaymentFailed], true)) {
            if (!$matches || ($this->status === BookingStatus::PaymentFailed && $payment->status === PaymentStatus::Paid)) {
                throw new RuleViolation('terminal_payment_conflict', 'A terminal payment result requires operator investigation.');
            }

            return $this;
        }
        if (!$matches) {
            return $this->withStatus(BookingStatus::ReviewRequired);
        }

        $next = match ($payment->status) {
            PaymentStatus::Paid => BookingStatus::Confirmed,
            PaymentStatus::Failed => BookingStatus::PaymentFailed,
            PaymentStatus::Pending => $this->status,
        };

        return $this->withStatus($next, $payment->id);
    }

    public function withStatus(BookingStatus $status, ?string $paymentId = null): self
    {
        return new self($this->id, $this->slotId, $this->customerId, $this->idempotencyKey, $status, $this->price, $this->expiresAt, $paymentId ?? $this->paymentId);
    }
}
