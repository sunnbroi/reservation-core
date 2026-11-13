<?php

declare(strict_types=1);

namespace Reservation\Contracts;

use Reservation\Domain\Booking;

interface EventStore
{
    public function append(string $type, Booking $booking): void;
}
