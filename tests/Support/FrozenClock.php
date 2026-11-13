<?php

declare(strict_types=1);

namespace Reservation\Tests\Support;

use Reservation\Contracts\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public \DateTimeImmutable $time)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }
}
