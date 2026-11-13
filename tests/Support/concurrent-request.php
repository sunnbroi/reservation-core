<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Reservation\Domain\RuleViolation;
use Reservation\Support\Runtime;

$runtime = new Runtime();
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
fgets(STDIN);
try {
    $value = match ($argv[1]) {
        'reserve' => $runtime->bookings->reserve($argv[3], $argv[2], $argv[4])->id,
        'pay' => (function () use ($runtime, $argv): string {
            $runtime->payments->process($argv[2]);
            return $runtime->repository->get($argv[2])->status->value;
        })(),
        'claim' => $runtime->outbox->claim()?->id,
    };
    fwrite(STDOUT, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR)."\n");
} catch (RuleViolation $error) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $error->reason], JSON_THROW_ON_ERROR)."\n");
}
