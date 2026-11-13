<?php

declare(strict_types=1);

namespace Reservation\Infrastructure\Demo;

use Reservation\Contracts\PaymentGateway;
use Reservation\Domain\{Money, PaymentSnapshot, PaymentStatus};
use Reservation\Support\Uuid;

// An executable provider simulator, not an integration with a real payment service.
final readonly class DemoPaymentGateway implements PaymentGateway
{
    public function __construct(private \PDO $providerConnection, private bool $timeoutAfterFirstCharge = false)
    {
    }

    public function create(string $merchantReference, Money $money): PaymentSnapshot
    {
        if ($this->providerConnection->inTransaction()) {
            throw new \LogicException('Provider I/O must not share an application transaction.');
        }
        $statement = $this->providerConnection->prepare("INSERT INTO demo_provider_payments (id, merchant_reference, price_minor, currency, status) VALUES (:id, :reference, :price, :currency, 'paid') ON CONFLICT (merchant_reference) DO NOTHING");
        $statement->execute(['id' => Uuid::make(), 'reference' => $merchantReference, 'price' => $money->minor, 'currency' => $money->currency]);
        $created = $statement->rowCount() === 1;
        $snapshot = $this->find($merchantReference) ?? throw new \RuntimeException('Provider result unavailable.');
        if (!$snapshot->money->equals($money)) {
            throw new \RuntimeException('Provider idempotency key was reused with a different amount.');
        }
        if ($created && $this->timeoutAfterFirstCharge) {
            throw new \RuntimeException('Simulated connection loss AFTER the provider committed the charge.');
        }
        return $snapshot;
    }

    public function find(string $merchantReference): ?PaymentSnapshot
    {
        $statement = $this->providerConnection->prepare('SELECT * FROM demo_provider_payments WHERE merchant_reference = :reference');
        $statement->execute(['reference' => $merchantReference]);
        $row = $statement->fetch();
        return $row === false ? null : new PaymentSnapshot($row['id'], $row['merchant_reference'], new Money((int) $row['price_minor'], $row['currency']), PaymentStatus::from($row['status']));
    }
}
