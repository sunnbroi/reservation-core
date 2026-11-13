CREATE TABLE slots (
    id uuid PRIMARY KEY,
    title varchar(120) NOT NULL,
    capacity integer NOT NULL CHECK (capacity > 0),
    price_minor bigint NOT NULL CHECK (price_minor > 0 AND price_minor <= 1000000000),
    currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    starts_at timestamptz NOT NULL
);

CREATE TABLE bookings (
    id uuid PRIMARY KEY,
    slot_id uuid NOT NULL REFERENCES slots(id),
    customer_id varchar(100) NOT NULL,
    idempotency_key varchar(128) NOT NULL,
    status varchar(32) NOT NULL CHECK (status IN ('held', 'payment_pending', 'confirmed', 'expired', 'payment_failed', 'review_required')),
    price_minor bigint NOT NULL CHECK (price_minor > 0 AND price_minor <= 1000000000),
    currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    expires_at timestamptz NOT NULL,
    payment_id varchar(100) UNIQUE,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (customer_id, idempotency_key)
);
CREATE INDEX bookings_occupied_slot ON bookings (slot_id)
    WHERE status IN ('held', 'payment_pending', 'confirmed', 'review_required');
CREATE INDEX bookings_expirable ON bookings (slot_id, expires_at) WHERE status = 'held';

CREATE TABLE outbox (
    id uuid PRIMARY KEY,
    aggregate_id uuid NOT NULL REFERENCES bookings(id),
    type varchar(80) NOT NULL,
    payload jsonb NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'processed', 'dead')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    locked_until timestamptz,
    lease_token uuid,
    last_error varchar(200),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (aggregate_id, type)
);
CREATE INDEX outbox_pending ON outbox (available_at, created_at) WHERE status = 'pending';
CREATE INDEX outbox_leases ON outbox (locked_until) WHERE status = 'processing';

-- Demo-only external systems. They are not authoritative application tables.
-- Demo adapters use a separate connection, so their commits survive application rollbacks.
CREATE TABLE demo_provider_payments (
    id uuid PRIMARY KEY,
    merchant_reference uuid NOT NULL UNIQUE,
    price_minor bigint NOT NULL,
    currency char(3) NOT NULL,
    status varchar(20) NOT NULL CHECK (status IN ('pending', 'paid', 'failed')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE demo_notifications (
    event_id uuid PRIMARY KEY,
    event_type varchar(80) NOT NULL,
    booking_id uuid NOT NULL,
    accepted_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
