-- Which languages visitors may switch the public pages to (comma list of
-- I18n::SUPPORTED codes, e.g. "id,en"). NULL means all supported ones.
-- nodes.default_locale (already present) is the language shown by default.
ALTER TABLE nodes ADD COLUMN enabled_locales VARCHAR(64) NULL AFTER default_locale;
