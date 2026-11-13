<?php

declare(strict_types=1);

namespace Reservation\Domain;

final class RuleViolation extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
