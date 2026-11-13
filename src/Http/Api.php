<?php

declare(strict_types=1);

namespace Reservation\Http;

use Reservation\Application\{BookingService, PaymentService};
use Reservation\Domain\{Booking, RuleViolation};

final readonly class Api
{
    // Token => customer mapping stands in for the host application's authentication.
    /** @param array<string, string> $identities */
    public function __construct(private BookingService $bookings, private PaymentService $payments, private WebhookSignature $signatures, private array $identities)
    {
    }

    /** @param array<string, string> $headers */
    public function handle(string $method, string $path, array $headers, string $rawBody = ''): Response
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        try {
            if (strlen($rawBody) > 16384) {
                throw new RuleViolation('body_too_large', 'Request body exceeds 16 KiB.', 413);
            }
            if ($method === 'POST' && $path === '/webhooks/payments') {
                $this->signatures->verify($rawBody, $headers['x-webhook-timestamp'] ?? '', $headers['x-webhook-signature'] ?? '');
                $body = $this->decode($rawBody);
                // A webhook is only a hint. Fetch amount, reference and status from the provider.
                $booking = $this->payments->reconcile($this->string($body, 'booking_id'));
                return new Response(200, self::present($booking));
            }
            $authorization = $headers['authorization'] ?? '';
            $customer = str_starts_with($authorization, 'Bearer ') ? ($this->identities[substr($authorization, 7)] ?? null) : null;
            if ($customer === null) {
                throw new RuleViolation('unauthorized', 'A valid bearer token is required.', 401);
            }
            if ($method === 'POST' && $path === '/bookings') {
                $body = $this->decode($rawBody);
                return new Response(200, self::present($this->bookings->reserve($customer, $this->string($body, 'slot_id'), $headers['idempotency-key'] ?? '')));
            }
            if (preg_match('~^/bookings/([a-z0-9-]+)(/payment)?$~D', $path, $match)) {
                if ($method === 'GET' && !isset($match[2])) {
                    return new Response(200, self::present($this->bookings->owned($customer, $match[1])));
                }
                if ($method === 'POST' && ($match[2] ?? '') === '/payment') {
                    return new Response(202, self::present($this->bookings->requestPayment($customer, $match[1])));
                }
            }
            throw new RuleViolation('route_not_found', 'Route not found.', 404);
        } catch (RuleViolation $error) {
            return new Response($error->httpStatus, ['error' => $error->reason, 'message' => $error->getMessage()]);
        } catch (\JsonException) {
            return new Response(422, ['error' => 'invalid_json', 'message' => 'A JSON object is required.']);
        }
    }

    /** @return array<string, mixed> */
    public static function present(Booking $booking): array
    {
        return [
            'id' => $booking->id, 'slot_id' => $booking->slotId, 'status' => $booking->status->value,
            'price_minor' => $booking->price->minor, 'currency' => $booking->price->currency,
            'hold_expires_at' => $booking->expiresAt->format(DATE_ATOM), 'payment_id' => $booking->paymentId,
        ];
    }

    /** @return array<string, mixed> */
    private function decode(string $rawBody): array
    {
        $object = json_decode($rawBody, false, 16, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) {
            throw new RuleViolation('invalid_json', 'A JSON object is required.', 422);
        }
        return get_object_vars($object);
    }

    /** @param array<string, mixed> $body */
    private function string(array $body, string $key): string
    {
        if (!isset($body[$key]) || !is_string($body[$key])) {
            throw new RuleViolation('invalid_request', 'Missing or invalid '.$key.'.', 422);
        }
        return $body[$key];
    }
}
