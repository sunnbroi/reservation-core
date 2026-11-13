# Architecture decisions

## 1. PostgreSQL owns capacity

Reserve in a short READ COMMITTED transaction: lock a customer-scoped request key; return an existing result or reject a changed slot; lock the slot row; expire unpaid holds; count occupying bookings; insert the new booking and its event; commit.

The next statement after acquiring a contended slot lock sees the previous transaction's committed booking. Every new reservation uses this path. The unique customer/key constraint is a second defence against duplicate requests. Advisory-lock hash collisions may unnecessarily serialize unrelated requests but cannot create extra capacity.

The row lock ends at commit. The persistent `held` record represents the ten-minute reservation; the database transaction does not remain open during that interval. Slot price is copied into the reservation. Amounts use integer minor units.

## 2. Unknown payment outcomes keep capacity occupied

Starting payment changes `held` to `payment_pending` and writes `payment.requested` atomically. From that point the hold TTL cannot release the place. A provider timeout is not a terminal failure. Successful provider lookup or an authoritative final failure resolves the state; missing information preserves the reservation.

This favours preventing overbooking over maximising immediate availability. It can leave capacity occupied indefinitely. A production integration needs bounded provider timeouts, monitoring, periodic reconciliation and a provider-supported cancellation policy. A local timer alone cannot safely cancel a remotely successful payment.

## 3. No provider I/O inside business transactions

The worker calls the provider after committing its message claim. The gateway uses the booking UUID as a stable merchant reference and idempotency key. Applying the result uses a new short transaction that locks the booking and inserts its status event.

The gateway contract requires stable reference lookup and idempotent creation throughout the recovery window. A real adapter must handle provider key-retention limits explicitly. A final failed/cancelled result must be terminal; a transport error or a missing response is never translated into failure. Contradictory terminal facts raise an investigation error rather than silently re-confirming released capacity.

The demo provider is a separate PDO connection backed by synthetic tables. Its commit survives a lost application response. It is not a live payment integration or a network latency model.

## 4. Transactional outbox with at-least-once delivery

Booking writes and event inserts share the same connection and transaction. Claims use `FOR UPDATE SKIP LOCKED`, then commit a lease before external work starts. Failed deliveries use bounded exponential backoff. Crashed work becomes reclaimable after its lease expires. A fresh lease token prevents an old worker from acknowledging or rescheduling a message now owned by another worker. A crash on the final attempt becomes `dead` when workers next poll.

Lease expiry does not stop a paused old worker from resuming external work. Therefore recipients must deduplicate effects. The demo recipient uses the message UUID as a unique key. The payment adapter uses the stable booking UUID. The design does not promise exactly-once network delivery, global FIFO ordering, or duplicate-free email delivery without recipient support.

The unique `(aggregate_id, type)` event key is specific to this state model, where each business event type occurs at most once. A model with repeatable transitions needs versioned event identities instead.

## 5. Webhooks trigger verification

The demo adapter checks an HMAC over `timestamp.raw_body` and permits a five-minute clock difference. It then fetches the provider result by merchant reference; it does not trust an amount or success flag in the webhook body. Duplicate and out-of-order callbacks converge through the locked state transition. There is no separate webhook inbox because no side effect depends on callback delivery identity.

A production provider adapter must implement that provider's actual signature format, replay policy, timeouts, credentials and event mapping. The demo protocol is not advertised as compatible with any payment service.

## 6. Small domain and explicit adapters

Domain and application code depend on contracts. PostgreSQL, HTTP, the clock and demo external systems are adapters. The demo composition root wires them together. No framework dependency or reference to another product is required. A host application supplies customer authentication, resource management and its operational policies.

No repository abstraction pretends that SQLite or an in-memory fake can prove PostgreSQL concurrency guarantees. Integration tests run against PostgreSQL and synchronize independent PHP processes at a barrier.

## 7. Retention and operational work remain explicit

Idempotency keys are retained with bookings in this initial version. Deleting history requires a deliberate retention policy that also preserves retry guarantees. Outbox payloads contain only reservation identifiers and state. Error storage records exception classes rather than arbitrary provider messages. Dead messages and `review_required` states need operator attention; the demo exposes inspection commands, not a complete alerting system.
