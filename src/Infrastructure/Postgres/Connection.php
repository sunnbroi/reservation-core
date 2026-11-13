<?php

declare(strict_types=1);

namespace Reservation\Infrastructure\Postgres;

final class Connection
{
    public static function open(?string $dsn = null, ?string $schema = null): \PDO
    {
        $pdo = new \PDO(
            $dsn ?? (getenv('DATABASE_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=reservation'),
            getenv('DATABASE_USER') ?: 'reservation',
            getenv('DATABASE_PASSWORD') ?: 'reservation-local-only',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
        );
        $schema ??= getenv('DATABASE_SCHEMA') ?: 'public';
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new \InvalidArgumentException('Invalid database schema.');
        }
        $pdo->exec('SET search_path TO "'.$schema.'"');
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec("SET default_transaction_isolation TO 'read committed'");
        return $pdo;
    }
}
