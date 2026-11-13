<?php

declare(strict_types=1);

namespace Reservation\Contracts;

use Reservation\Application\OutboxMessage;

interface OutboxQueue
{
    public function claim(): ?OutboxMessage;
    public function complete(OutboxMessage $message): bool;
    public function fail(OutboxMessage $message, \Throwable $error): bool;
}
