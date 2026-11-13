<?php

declare(strict_types=1);

namespace Reservation\Contracts;

use Reservation\Domain\{Money, PaymentSnapshot};

interface PaymentGateway
{
    // The implementation MUST reuse the merchant reference as the provider idempotency key.
    public function create(string $merchantReference, Money $money): PaymentSnapshot;
    public function find(string $merchantReference): ?PaymentSnapshot;
}
