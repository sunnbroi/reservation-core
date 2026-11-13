<?php

declare(strict_types=1);

namespace Reservation\Domain;

final readonly class Money
{
    public function __construct(public int $minor, public string $currency)
    {
        if ($minor <= 0 || $minor > 1_000_000_000 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Use a positive integer amount in minor units and a three-letter currency.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }
}
