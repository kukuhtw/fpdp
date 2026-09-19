INSERT INTO payment_gateways (code, name, adapter_class, supports_refund, supports_qris, supports_va, supports_credit_card, supports_ewallet)
VALUES
    ('DUMMY', 'Dummy (development)', 'App\\Services\\Payment\\DummyPaymentGateway', 1, 0, 1, 0, 0),
    ('PAYWUZ', 'Paywuz', 'App\\Services\\Payment\\PaywuzGateway', 0, 1, 1, 0, 1),
    ('MIDTRANS', 'Midtrans', 'App\\Services\\Payment\\MidtransGateway', 1, 1, 1, 1, 1);
