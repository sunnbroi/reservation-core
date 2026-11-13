<?php

declare(strict_types=1);

namespace Reservation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reservation\Domain\RuleViolation;
use Reservation\Http\WebhookSignature;
use Reservation\Tests\Support\FrozenClock;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'synthetic-webhook-secret-123456';

    public function testAuthenticPayloadPasses(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2030-01-01T12:00:00Z'));
        $timestamp = (string) $clock->now()->getTimestamp();
        $signature = new WebhookSignature(self::SECRET, $clock);
        $signature->verify('{}', $timestamp, hash_hmac('sha256', $timestamp.'.{}', self::SECRET));
        $this->addToAssertionCount(1);
    }

    public function testChangedPayloadIsRejected(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2030-01-01T12:00:00Z'));
        $timestamp = (string) $clock->now()->getTimestamp();
        $this->expectException(RuleViolation::class);
        (new WebhookSignature(self::SECRET, $clock))->verify('{"changed":true}', $timestamp, hash_hmac('sha256', $timestamp.'.{}', self::SECRET));
    }

    public function testOldSignedPayloadIsRejected(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2030-01-01T12:00:00Z'));
        $timestamp = (string) ($clock->now()->getTimestamp() - 301);
        $this->expectException(RuleViolation::class);
        (new WebhookSignature(self::SECRET, $clock))->verify('{}', $timestamp, hash_hmac('sha256', $timestamp.'.{}', self::SECRET));
    }
}
