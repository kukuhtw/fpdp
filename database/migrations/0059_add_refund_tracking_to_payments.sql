-- Owner-initiated and webhook-reported refunds. `refunded_amount` accumulates
-- partial refunds; status becomes PARTIALLY_REFUNDED until it reaches
-- `amount`, then REFUNDED. `refunded_at` is the time of the latest refund.
ALTER TABLE payments
    ADD COLUMN refunded_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER fee,
    ADD COLUMN refunded_at TIMESTAMP NULL AFTER paid_at;
