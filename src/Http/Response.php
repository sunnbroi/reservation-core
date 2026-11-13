<?php

declare(strict_types=1);

namespace Reservation\Http;

final readonly class Response
{
    /** @param array<string, mixed> $body */
    public function __construct(public int $status, public array $body)
    {
    }
}
