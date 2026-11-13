<?php

declare(strict_types=1);

namespace Reservation\Tests\Integration;

use Reservation\Http\{Api, WebhookSignature};
use Reservation\Tests\Support\DatabaseTestCase;

final class ApiTest extends DatabaseTestCase
{
    private const SECRET = 'synthetic-webhook-secret-123456';

    private function api(): Api
    {
        return new Api($this->runtime->bookings, $this->runtime->payments, new WebhookSignature(self::SECRET, $this->clock), ['customer-1-token' => 'customer-1', 'customer-2-token' => 'customer-2']);
    }

    public function testAuthorizationIsRequired(): void
    {
        self::assertSame(401, $this->api()->handle('POST', '/bookings', [], '{}')->status);
    }

    public function testIdentityAndPriceComeFromTheServer(): void
    {
        $response = $this->api()->handle('POST', '/bookings', ['Authorization' => 'Bearer customer-1-token', 'Idempotency-Key' => 'reserve-0001'], json_encode(['slot_id' => $this->slot(), 'customer_id' => 'customer-2', 'price_minor' => 1]));
        self::assertSame(200, $response->status);
        self::assertSame(250000, $response->body['price_minor']);
        self::assertSame('customer-1', $this->runtime->repository->get($response->body['id'])->customerId);
    }

    public function testAnotherCustomersBookingIsNotExposed(): void
    {
        $booking = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        self::assertSame(404, $this->api()->handle('GET', '/bookings/'.$booking->id, ['Authorization' => 'Bearer customer-2-token'])->status);
    }

    public function testMalformedJsonIsRejected(): void
    {
        self::assertSame(422, $this->api()->handle('POST', '/bookings', ['Authorization' => 'Bearer customer-1-token'], '{')->status);
    }

    public function testOversizedPayloadIsRejected(): void
    {
        self::assertSame(413, $this->api()->handle('POST', '/bookings', [], str_repeat('x', 16385))->status);
    }

    public function testSignedWebhookRechecksTheProviderAndDuplicateIsHarmless(): void
    {
        $booking = $this->runtime->bookings->reserve('customer-1', $this->slot(), 'reserve-0001');
        $this->runtime->bookings->requestPayment('customer-1', $booking->id);
        $gateway = new \Reservation\Infrastructure\Demo\DemoPaymentGateway(\Reservation\Infrastructure\Postgres\Connection::open());
        $gateway->create($booking->id, $booking->price);
        $body = json_encode(['booking_id' => $booking->id, 'amount' => 1]);
        $timestamp = (string) $this->clock->now()->getTimestamp();
        $headers = ['X-Webhook-Timestamp' => $timestamp, 'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)];
        $first = $this->api()->handle('POST', '/webhooks/payments', $headers, $body);
        $second = $this->api()->handle('POST', '/webhooks/payments', $headers, $body);
        self::assertSame(200, $first->status);
        self::assertSame('confirmed', $first->body['status']);
        self::assertSame($first->body, $second->body);
        self::assertSame(1, $this->eventCount('booking.confirmed'));
    }
}
