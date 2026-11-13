<?php

declare(strict_types=1);

namespace Reservation\Support;

use Reservation\Application\{BookingService, OutboxMessage, OutboxWorker, PaymentService};
use Reservation\Contracts\Clock;
use Reservation\Infrastructure\Demo\{DemoNotificationSink, DemoPaymentGateway};
use Reservation\Infrastructure\Postgres\{Connection, PgBookings, PgOutbox, PgTransaction};

// Composition root for the demo adapters. Host applications can wire the contracts themselves.
final readonly class Runtime
{
    public \PDO $pdo;
    public PgBookings $repository;
    public PgTransaction $transaction;
    public PgOutbox $outbox;
    public BookingService $bookings;
    public PaymentService $payments;
    public OutboxWorker $worker;

    public function __construct(?\PDO $pdo = null, ?\PDO $external = null, ?Clock $clock = null, bool $timeoutAfterCharge = false)
    {
        $this->pdo = $pdo ?? Connection::open();
        $external ??= Connection::open();
        $this->transaction = new PgTransaction($this->pdo);
        $this->repository = new PgBookings($this->pdo);
        $this->outbox = new PgOutbox($this->pdo, $this->transaction);
        $this->bookings = new BookingService($this->transaction, $this->repository, $this->outbox, $clock ?? new SystemClock());
        $this->payments = new PaymentService($this->transaction, $this->repository, $this->outbox, new DemoPaymentGateway($external, $timeoutAfterCharge));
        $sink = new DemoNotificationSink($external);
        $payments = $this->payments;
        $this->worker = new OutboxWorker($this->outbox, static function (OutboxMessage $message) use ($payments, $sink): void {
            if ($message->type === 'payment.requested') {
                $payments->process($message->aggregateId);
            } elseif (str_starts_with($message->type, 'booking.')) {
                $sink->accept($message);
            } else {
                throw new \RuntimeException('Unsupported event type.');
            }
        });
    }
}
