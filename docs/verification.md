# Verification

The following checks were executed locally against PHP 8.4.27 and PostgreSQL 17.11. PostgreSQL was a real server, not an in-memory substitute.

| Check | Result |
|---|---|
| Composer manifest and lock validation | Passed |
| PSR-12 formatting with Laravel Pint | Passed |
| PHPStan level 6 over `src` | Passed without a baseline or ignored errors |
| PHPUnit unit and integration suites | 46 tests, 186 assertions passed |
| Independent-process concurrency | Eight requests for one place; six identical retries; same-key/different-slot conflict; six concurrent payment executions; worker skips a locked row |
| Migration replay | Initial migration applied twice without error |
| HTTP smoke flow | Health, reserve, identical retry, payment request, charge-then-timeout, signed callback, duplicate callback, read state, unauthorized request |
| External effects after retry and reconciliation | One synthetic payment; two accepted domain notifications; all three outbox messages processed |
| OpenAPI and Compose YAML syntax | Parsed successfully |
| Docker image build and Compose runtime | Not run: Docker was unavailable in the validation environment |
| GitHub Actions run | Not run: the new repository has not been published |

## What the tests establish

The concurrency suite uses `proc_open` to start independent PHP processes. Each process establishes separate PostgreSQL connections, signals readiness, then waits at a barrier. The parent releases all requests together. Capacity and provider-payment counts are checked in the database after completion.

The failure suite deliberately injects an outbox write failure, a provider timeout after a durable charge, expired message leases, a crash after notification acceptance and a crash on the last permitted attempt. It also checks payment mismatches, owner boundaries and webhook validation.

The HTTP smoke check exercised the real development server, CLI migrations and worker commands against synthetic data in an isolated schema. It established local behaviour; it is not a throughput benchmark or a production-readiness assessment.

## Reproduce

```sh
composer install
export TEST_DATABASE_DSN='pgsql:host=127.0.0.1;port=5432;dbname=reservation_test'
export DATABASE_USER=reservation
export DATABASE_PASSWORD=reservation-local-only
composer check
```

Or use the supplied Docker test service:

```sh
cp .env.example .env
docker compose --profile test run --rm test
```

The latter is a supplied execution path; it has not been reported as a completed Docker check. Integration tests create and remove isolated `test_*` schemas. They are skipped if no `TEST_DATABASE_DSN` is supplied, while an unreachable configured database fails the suite.
