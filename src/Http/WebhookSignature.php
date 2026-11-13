<?php

declare(strict_types=1);

namespace Reservation\Http;

use Reservation\Contracts\Clock;
use Reservation\Domain\RuleViolation;

final readonly class WebhookSignature
{
    public function __construct(private string $secret, private Clock $clock)
    {
        if (strlen($secret) < 24) {
            throw new \InvalidArgumentException('Webhook secret must contain at least 24 characters.');
        }
    }

    public function verify(string $rawBody, string $timestamp, string $signature): void
    {
        if (!ctype_digit($timestamp) || strlen($timestamp) > 12 || abs($this->clock->now()->getTimestamp() - (int) $timestamp) > 300) {
            throw new RuleViolation('invalid_signature', 'Invalid webhook signature or timestamp.', 401);
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuleViolation('invalid_signature', 'Invalid webhook signature or timestamp.', 401);
        }
    }
}
