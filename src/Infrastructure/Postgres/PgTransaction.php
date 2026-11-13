<?php

declare(strict_types=1);

namespace Reservation\Infrastructure\Postgres;

use Reservation\Contracts\Transaction;

final readonly class PgTransaction implements Transaction
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Nested transactions require explicit savepoints.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("SET LOCAL lock_timeout = '5s'");
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->rollbackIfActive();
            throw $error;
        }
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
