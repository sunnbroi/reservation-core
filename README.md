# Reservation Core

A standalone PHP module for reserving limited capacity and coordinating asynchronous payments. It demonstrates the difficult boundary between a local database transaction and an external service: preventing overbooking, recovering an unknown payment result, and delivering committed events after a worker crash.

The example domain is a fictional workshop with one available place. All fixtures, identities and provider data are synthetic. This repository is an independent reference implementation; it contains no client code or data and makes no claim of production deployment.

**PHP 8.4 · PostgreSQL 17 · PDO · PHPUnit 12 · PHPStan · Docker Compose · GitHub Actions**

[Объяснение на русском](docs/walkthrough.ru.md) · [Зачем отдельный модуль](docs/rationale.ru.md) · [Architecture decisions](docs/decisions.md) · [API contract](docs/openapi.yaml) · [Verification](docs/verification.md)

## The problem

Two customers submit the last available place at the same time. One retries because the response was lost. The payment provider accepts a charge but its response times out. A worker then crashes after an external recipient accepts an event.

The module must preserve capacity, avoid a second charge, and resume unfinished work. A database transaction alone cannot atomically commit a remote payment or notification.

## What is implemented

| Requirement | Implementation | Evidence |
|---|---|---|
| Prevent overbooking | Short transaction locks the slot before counting occupied places | Eight independent PHP processes compete for one place |
| Safe request retries | Customer-scoped idempotency key, request lock, unique constraint, payload conflict detection | Concurrent retries return one booking ID |
| Recover a lost payment response | Stable merchant reference, idempotent gateway contract, provider lookup | Simulated charge-then-timeout creates one provider payment |
| Commit events with state | Business write and outbox insert share one PDO transaction | Injected outbox failure rolls back the booking |
| Multiple workers | `FOR UPDATE SKIP LOCKED`, expiring leases, ownership tokens | Another process skips a locked message; stale owners cannot acknowledge |
| Bounded retries | Backoff, attempt limit, dead messages, recovery after final-attempt crashes | Outbox integration tests |
| Verify payment facts | Match merchant reference, amount, currency and payment ID | Wrong payment facts require review and retain capacity |
| Treat webhooks safely | HMAC with timestamp, then authoritative provider lookup | Modified or stale signatures fail; duplicate callbacks are harmless |
| Keep customer boundaries | Customer identity comes from authentication, price from the slot | HTTP adapter integration tests |

## Why build this module

This is application-specific coordination, rather than a replacement for a payment provider or a complete booking product. Its explicit requirements are: capacity and booking state live in the host database; reserving and queuing work must be atomic; an ambiguous payment must never release a place automatically; the module must be embeddable without importing an entire application.

A provider SDK addresses payment transport. A framework queue addresses background execution. Neither automatically enforces those rules across the host's booking records. They can be used behind this module's contracts.

A complete booking service is a better choice when its business rules fit and it can own the inventory and payment workflow. There is no claim that an existing solution cannot work. [The build-versus-buy rationale](docs/rationale.ru.md) explains the boundary and alternatives.

## State model

```mermaid
stateDiagram-v2
    [*] --> held
    held --> expired: Unpaid hold expires
    held --> payment_pending: Request payment
    payment_pending --> confirmed: Verified paid result
    payment_pending --> payment_failed: Verified terminal failure
    payment_pending --> review_required: Payment facts mismatch
    review_required --> confirmed: Verified paid result
    review_required --> payment_failed: Verified terminal failure
```

`held`, `payment_pending`, `confirmed` and `review_required` occupy capacity. Only `held` expires automatically. A timeout is an unknown outcome, so a pending payment keeps its place. Terminal results do not regress when older responses arrive.

## Run the local demo

Requirements: Docker with Compose. The supplied credentials are for this localhost demo only.

```sh
cp .env.example .env
docker compose up --build -d
curl http://127.0.0.1:8080/health
```

The application applies migrations and seeds one fictional workshop. PostgreSQL has no exposed host port. The worker runs separately. PHP's development server is used only for this local demo.

Reserve the place:

```sh
curl -s http://127.0.0.1:8080/bookings \
  -H 'Authorization: Bearer local-demo-token-do-not-deploy' \
  -H 'Idempotency-Key: example-request-001' \
  -H 'Content-Type: application/json' \
  -d '{"slot_id":"11111111-1111-4111-8111-111111111111"}'
```

The response contains a booking `id`. Repeating this command returns the same booking. For a second attempt with a different payload, use a new key.

Replace `<booking-id>` in these commands with the returned ID:

```sh
curl -s -X POST http://127.0.0.1:8080/bookings/<booking-id>/payment \
  -H 'Authorization: Bearer local-demo-token-do-not-deploy'

curl -s http://127.0.0.1:8080/bookings/<booking-id> \
  -H 'Authorization: Bearer local-demo-token-do-not-deploy'

docker compose exec app php bin/console status
```

By default `DEMO_TIMEOUT_AFTER_CHARGE=1`: the simulator commits the first charge, then throws an error before returning. The worker retries with the same merchant reference and confirms the existing payment. Backoff starts at two seconds. You can also reconcile explicitly:

```sh
docker compose exec app php bin/console reconcile <booking-id>
docker compose logs worker
```

Inspect accepted demo events in `demo_notifications`; no email or real notification is sent. Run `php bin/console expire` periodically to clean up unpaid holds proactively; a new reservation also expires old unpaid holds under the same slot lock.

Stop the services with `docker compose down`. To reset the synthetic demo data, use `docker compose down -v`; this removes the demo database volume.

## Tests and static analysis

```sh
docker compose --profile test run --rm test
```

Integration tests create and drop randomly named `test_*` schemas. They do not truncate the application's tables. Use a dedicated database for development outside the demo.

Native setup requires PHP 8.4+, Composer, PostgreSQL and the extensions listed in `composer.json`, plus PHPUnit's DOM/XML/mbstring dependencies:

```sh
composer install
composer test:unit
export TEST_DATABASE_DSN='pgsql:host=127.0.0.1;port=5432;dbname=reservation_test'
export DATABASE_USER=reservation
export DATABASE_PASSWORD=reservation-local-only
composer check
```

Integration tests are skipped when `TEST_DATABASE_DSN` is absent. CI supplies it. The test command fails if a configured database cannot be reached. See [verification details](docs/verification.md) for the actual validated environment and unverified checks.

## Structure and embedding

- `src/Domain`: money, reservation states and payment transitions; no framework or PDO dependencies.
- `src/Application`: reservation, payment and worker use cases.
- `src/Contracts`: transaction, storage, clock, payment and queue ports.
- `src/Infrastructure/Postgres`: durable storage, locks, transaction and outbox adapters.
- `src/Infrastructure/Demo`: synthetic external payment ledger and idempotent notification recipient.
- `src/Http`: small JSON adapter and webhook signature verification.
- `src/Support/Runtime.php`: composition root for the demo.
- `database`, `tests`, `docs`: migration, executable failure cases and design rationale.

Host applications wire the ports to their container and call `BookingService` / `PaymentService`. Customer identity must come from their own authentication. This project does not depend on or reference any other application.

## Deliberate limits

- No live payment integration, checkout UI, refunds, recurring billing or customer authentication system. The demo token maps to one synthetic customer.
- No exactly-once network delivery. The outbox is at-least-once; the demo recipient deduplicates by event ID. A real recipient must supply equivalent idempotency or accept possible duplicates.
- No automatic release of uncertain payments. Reconciliation, authoritative cancellation and operational alerts are required before production use.
- Provider adapters must preserve idempotency through the recovery window and distinguish a final failure from an unknown result. Do not retry creates beyond a provider's idempotency retention window without a safe recovery policy.
- Capacity must be changed through controlled transactions; arbitrary direct SQL writes can bypass application invariants. This is a single PostgreSQL primary design, not a distributed inventory service.
- The webhook signing format is a demo contract. Real providers require adapters for their own verification rules.

## References

- [PostgreSQL row locking](https://www.postgresql.org/docs/17/explicit-locking.html)
- [PostgreSQL SELECT and SKIP LOCKED](https://www.postgresql.org/docs/17/sql-select.html)
- [PDO transactions](https://www.php.net/manual/en/pdo.transactions.php)

## License

MIT. See [LICENSE](LICENSE).
