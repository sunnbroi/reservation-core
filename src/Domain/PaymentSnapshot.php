<?php

declare(strict_types=1);

namespace Reservation\Domain;

final readonly class PaymentSnapshot
{
    public function __construct(
        public string $id,
        public string $merchantReference,
        public Money $money,
        public PaymentStatus $status,
    ) {
    }
}
