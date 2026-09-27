-- A federated post that is a product from another FPDP node carries its
-- structured `fpdp:product` block here (price, currency, product type,
-- checkout URL), validated on receipt. NULL for ordinary posts.
ALTER TABLE federated_posts ADD COLUMN product_data JSON NULL AFTER attachments;
