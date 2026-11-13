<?php

declare(strict_types=1);

namespace Reservation\Domain;

final readonly class Slot
{
    public function __construct(
        public string $id,
        public int $capacity,
        public Money $price,
        public \DateTimeImmutable $startsAt,
    ) {
    }
}
