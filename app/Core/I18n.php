<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\Request;

/**
 * Translations for the public (visitor-facing) pages.
 *
 * The node owner picks a default language and which languages visitors may
 * switch to (nodes.default_locale / nodes.enabled_locales). For each request
 * the language is, in order: `?lang=xx` (remembered in a cookie), that
 * cookie, the browser's Accept-Language, then the node default — always
 * limited to the enabled languages.
 *
 * Strings live in app/Lang/{locale}/{area}.php files, each returning a flat
 * ['key' => 'text'] array; all files of a locale are merged. Text may hold
 * `:name` placeholders filled from t()'s $params. A key missing in the
 * current language falls back to the node default, then to any supported
 * language, then to the key itself — a missing translation never breaks a
 * page. Keys starting with `js.` are also handed to the page's JavaScript
 * (see View::i18nScript()).
 *
 * Owner-written content (bio, posts, product text) is never translated.
 */
final class I18n
{
    public const SUPPORTED = ['id', 'en'];
    public const LABELS = ['id' => 'Bahasa Indonesia', 'en' => 'English'];
    public const COOKIE = 'fpdp_lang';
    private const FALLBACK = 'id';

    private static ?string $locale = null;
    /** @var array{default: string, enabled: array<int, string>}|null */
    private static ?array $settings = null;
    /** @var (\Closure(): array{default?: mixed, enabled?: mixed})|null */
    private static ?\Closure $settingsProvider = null;
    private static ?Request $request = null;
    private static ?string $cookieToSet = null;
    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    /**
     * Front-controller hook: the request the language is taken from, and a
     * lazy provider of the node's language settings (only called when a
     * page actually needs a translation, so JSON APIs never touch the DB).
     *
     * @param \Closure(): array{default?: mixed, enabled?: mixed} $settingsProvider
     */
    public static function useRequest(Request $request, ?\Closure $settingsProvider = null): void
    {
        self::$request = $request;
        self::$settingsProvider = $settingsProvider;
        self::$locale = null;
        self::$settings = null;
        self::$cookieToSet = null;
    }

    /** Test/CLI seam: force a language and settings. */
    public static function setLocale(string $locale, ?array $enabled = null): void
    {
        self::$settings = self::normalizeSettings(['default' => $locale, 'enabled' => $enabled ?? self::SUPPORTED]);
        self::$locale = in_array($locale, self::$settings['enabled'], true) ? $locale : self::$settings['default'];
    }

    public static function reset(): void
    {
        self::$locale = null;
        self::$settings = null;
        self::$settingsProvider = null;
        self::$request = null;
        self::$cookieToSet = null;
    }

    public static function locale(): string
    {
        return self::$locale ??= self::resolve();
    }

    /** @return array<int, string> the languages visitors may switch between */
    public static function enabledLocales(): array
    {
        return self::settings()['enabled'];
    }

    public static function defaultLocale(): string
    {
        return self::settings()['default'];
    }

    /**
     * @param array<string, string|int|float> $params replaces `:name` placeholders
     */
    public static function t(string $key, array $params = []): string
    {
        $text = self::lookup($key);
        foreach ($params as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * The current language's `js.*` strings, keys without the prefix.
     *
     * @return array<string, string>
     */
    public static function jsCatalog(): array
    {
        $result = [];
        foreach (array_keys(self::catalog(self::FALLBACK) + self::catalog(self::locale())) as $key) {
            if (str_starts_with($key, 'js.')) {
                $result[substr($key, 3)] = self::lookup($key);
            }
        }

        return $result;
    }

    /** A Set-Cookie value to send, when the visitor just chose a language with ?lang=. */
    public static function pendingCookie(): ?string
    {
        return self::$cookieToSet;
    }

    /**
     * Validates a language setting from the owner: every locale supported,
     * at least one enabled, the default among the enabled ones.
     *
     * @param array<int, mixed> $enabled
     * @return array<int, array{field: string, reason: string}> validation errors
     */
    public static function validateSettings(string $default, array $enabled): array
    {
        $errors = [];
        if ($enabled === [] || array_diff($enabled, self::SUPPORTED) !== []) {
            $errors[] = ['field' => 'enabled_locales', 'reason' => 'invalid_value'];
        }
        if (!in_array($default, self::SUPPORTED, true) || !in_array($default, $enabled, true)) {
            $errors[] = ['field' => 'default_locale', 'reason' => 'must_be_enabled'];
        }

        return $errors;
    }

    /**
     * @param array{default?: mixed, enabled?: mixed} $raw
     * @return array{default: string, enabled: array<int, string>}
     */
    public static function normalizeSettings(array $raw): array
    {
        $enabled = $raw['enabled'] ?? null;
        if (is_string($enabled)) {
            $enabled = explode(',', $enabled);
        }
        $enabled = is_array($enabled) ? array_values(array_intersect(self::SUPPORTED, array_map('trim', $enabled))) : [];
        if ($enabled === []) {
            $enabled = self::SUPPORTED;
        }
        $default = is_string($raw['default'] ?? null) ? $raw['default'] : '';
        if (!in_array($default, $enabled, true)) {
            $default = in_array(self::FALLBACK, $enabled, true) ? self::FALLBACK : $enabled[0];
        }

        return ['default' => $default, 'enabled' => $enabled];
    }

    /** @return array{default: string, enabled: array<int, string>} */
    private static function settings(): array
    {
        if (self::$settings === null) {
            $raw = [];
            if (self::$settingsProvider !== null) {
                try {
                    $raw = (self::$settingsProvider)();
                } catch (\Throwable) {
                    // No database yet (fresh install) — use the built-in defaults.
                }
            }
            self::$settings = self::normalizeSettings($raw);
        }

        return self::$settings;
    }

    private static function resolve(): string
    {
        $enabled = self::settings()['enabled'];
        $request = self::$request;
        if ($request === null) {
            return self::settings()['default'];
        }

        $asked = is_string($request->query['lang'] ?? null) ? strtolower($request->query['lang']) : null;
        if ($asked !== null && in_array($asked, $enabled, true)) {
            self::$cookieToSet = sprintf('%s=%s; Path=/; Max-Age=31536000; SameSite=Lax%s', self::COOKIE, $asked, $request->secure ? '; Secure' : '');

            return $asked;
        }

        $cookie = self::cookieValue($request);
        if ($cookie !== null && in_array($cookie, $enabled, true)) {
            return $cookie;
        }

        foreach (self::acceptedLanguages((string) ($request->headers['accept-language'] ?? '')) as $language) {
            if (in_array($language, $enabled, true)) {
                return $language;
            }
        }

        return self::settings()['default'];
    }

    private static function cookieValue(Request $request): ?string
    {
        foreach (explode(';', (string) ($request->headers['cookie'] ?? '')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name === self::COOKIE) {
                return strtolower(trim($value));
            }
        }

        return null;
    }

    /**
     * Primary language subtags from an Accept-Language header, best first.
     *
     * @return array<int, string>
     */
    private static function acceptedLanguages(string $header): array
    {
        $weighted = [];
        foreach (explode(',', $header) as $position => $part) {
            $pieces = explode(';', trim($part));
            $language = strtolower(explode('-', trim($pieces[0]))[0]);
            if ($language === '' || $language === '*') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $piece) {
                if (str_starts_with(trim($piece), 'q=')) {
                    $quality = (float) substr(trim($piece), 2);
                }
            }
            // Earlier entries win ties.
            $weighted[$language] = max($weighted[$language] ?? 0.0, $quality - $position / 1000);
        }
        arsort($weighted);

        return array_keys(array_filter($weighted, static fn (float $q): bool => $q > 0));
    }

    private static function lookup(string $key): string
    {
        foreach (array_unique([self::locale(), self::settings()['default'], ...self::SUPPORTED]) as $locale) {
            $catalog = self::catalog($locale);
            if (isset($catalog[$key])) {
                return $catalog[$key];
            }
        }

        return $key;
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        if (!isset(self::$catalogs[$locale])) {
            $merged = [];
            foreach (glob(__DIR__ . '/../Lang/' . $locale . '/*.php') ?: [] as $file) {
                // One broken catalog file must not take every page in that
                // language down: skip it, log it, and let lookup() fall back.
                try {
                    $strings = require $file;
                } catch (\Throwable $e) {
                    error_log('[I18n] skipping ' . basename(dirname($file)) . '/' . basename($file) . ': ' . $e->getMessage());
                    continue;
                }
                if (is_array($strings)) {
                    $merged = array_merge($merged, $strings);
                }
            }
            self::$catalogs[$locale] = $merged;
        }

        return self::$catalogs[$locale];
    }
}
