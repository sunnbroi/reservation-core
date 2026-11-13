<?php

declare(strict_types=1);

namespace Reservation\Application;

use Reservation\Contracts\OutboxQueue;

final readonly class OutboxWorker
{
    /** @param \Closure(OutboxMessage): void $handler */
    public function __construct(private OutboxQueue $outbox, private \Closure $handler)
    {
    }

    public function runOne(): bool
    {
        $message = $this->outbox->claim();
        if ($message === null) {
            return false;
        }
        try {
            ($this->handler)($message);
            $this->outbox->complete($message);
        } catch (\Throwable $error) {
            $this->outbox->fail($message, $error);
            error_log(json_encode(['event' => 'outbox.retry', 'message_id' => $message->id, 'attempt' => $message->attempt, 'error' => $error::class], JSON_THROW_ON_ERROR));
        }
        return true;
    }
}
