<?php

declare(strict_types=1);

namespace Reservation\Infrastructure\Demo;

use Reservation\Application\OutboxMessage;

final readonly class DemoNotificationSink
{
    public function __construct(private \PDO $externalConnection)
    {
    }

    public function accept(OutboxMessage $message): void
    {
        // The external recipient deduplicates by event ID, including after a worker crash.
        $statement = $this->externalConnection->prepare('INSERT INTO demo_notifications (event_id, event_type, booking_id) VALUES (:id, :type, :booking) ON CONFLICT (event_id) DO NOTHING');
        $statement->execute(['id' => $message->id, 'type' => $message->type, 'booking' => $message->aggregateId]);
    }
}
