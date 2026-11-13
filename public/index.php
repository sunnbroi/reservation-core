<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Reservation\Http\{Api, WebhookSignature};
use Reservation\Support\{Runtime, SystemClock, Uuid};

header('Content-Type: application/json; charset=utf-8');
$requestId = Uuid::make();
header('X-Request-Id: '.$requestId);
try {
    $runtime = new Runtime(timeoutAfterCharge: getenv('DEMO_TIMEOUT_AFTER_CHARGE') === '1');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' && $path === '/health') {
        $runtime->pdo->query('SELECT 1');
        http_response_code(200);
        echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
        exit;
    }
    $token = getenv('API_TOKEN') ?: throw new RuntimeException('API_TOKEN is required.');
    $secret = getenv('WEBHOOK_SECRET') ?: throw new RuntimeException('WEBHOOK_SECRET is required.');
    $api = new Api($runtime->bookings, $runtime->payments, new WebhookSignature($secret, new SystemClock()), [$token => 'demo-customer']);
    $headers = getallheaders();
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    $response = $api->handle($method, $path, $headers, $raw === false ? '' : $raw);
    http_response_code($response->status);
    echo json_encode($response->body, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log(json_encode(['event' => 'http.error', 'request_id' => $requestId, 'error' => $error::class], JSON_THROW_ON_ERROR));
    http_response_code(503);
    echo json_encode(['error' => 'service_unavailable', 'request_id' => $requestId], JSON_THROW_ON_ERROR);
}
