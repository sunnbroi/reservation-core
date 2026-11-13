<?php

declare(strict_types=1);

namespace Reservation\Application;

final readonly class OutboxMessage
{
    public function __construct(public string $id, public string $aggregateId, public string $type, public string $leaseToken, public int $attempt)
    {
    }
}
