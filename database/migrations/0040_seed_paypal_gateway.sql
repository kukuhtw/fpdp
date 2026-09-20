INSERT INTO payment_gateways (code, name, adapter_class, supports_refund, supports_qris, supports_va, supports_credit_card, supports_ewallet)
VALUES
    ('PAYPAL', 'PayPal', 'App\\Services\\Payment\\PayPalGateway', 1, 0, 0, 0, 1);
