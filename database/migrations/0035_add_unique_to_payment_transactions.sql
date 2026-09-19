ALTER TABLE payment_transactions ADD UNIQUE KEY unique_payment_transaction_event (provider, external_id);
