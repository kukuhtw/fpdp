<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Repositories\PaymentGatewayConfigRepository;

/**
 * Discovers installed payment gateway plugins under /gateways and syncs
 * them into the `payment_gateways` table so the existing config/encryption/
 * activation machinery (built for DUMMY/PAYWUZ/MIDTRANS/PAYPAL) picks them
 * up automatically. A plugin is "installed" simply by placing its folder
 * under /gateways on the server — there is no upload/extract step, so no
 * remote code can land here without filesystem access the owner already
 * has (same trust model as the /themes plugin system — see
 * documentation/PAYMENT-GATEWAY-PLUGIN-GUIDE.id.md).
 *
 * Unlike themes (which only override HTML rendering), a gateway plugin's
 * adapter class runs with full PHP execution rights and can see every
 * other gateway's decrypted credentials in the same process — only install
 * gateway plugins from sources you fully trust.
 */
final class PaymentGatewayPluginService
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,31}$/';
    private const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    public function __construct(
        private readonly PaymentGatewayConfigRepository $gateways,
        private readonly string $pluginsPath,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAvailable(): array
    {
        $plugins = [];

        foreach (is_dir($this->pluginsPath) ? (scandir($this->pluginsPath) ?: []) : [] as $entry) {
            if ($entry === '.' || $entry === '..' || preg_match(self::SLUG_PATTERN, $entry) !== 1) {
                continue;
            }

            $dir = $this->pluginsPath . '/' . $entry;
            $manifestPath = $dir . '/gateway.json';
            $classPath = $dir . '/Gateway.php';
            if (!is_dir($dir) || !is_file($manifestPath) || !is_file($classPath)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                continue;
            }

            $code = strtoupper(trim((string) ($manifest['code'] ?? '')));
            if (preg_match(self::CODE_PATTERN, $code) !== 1 || in_array($code, PaymentGatewayFactory::SUPPORTED_CODES, true)) {
                continue; // invalid, or trying to shadow a built-in gateway code
            }

            $class = (string) ($manifest['class'] ?? '');
            if (preg_match(self::CLASS_PATTERN, $class) !== 1 || str_starts_with($class, 'App\\')) {
                continue; // invalid, or trying to shadow a core namespace
            }

            $configKeys = is_array($manifest['config_keys'] ?? null)
                ? array_values(array_filter($manifest['config_keys'], 'is_string'))
                : [];
            $capabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];

            $plugins[] = [
                'slug' => $entry,
                'code' => $code,
                'name' => (string) ($manifest['name'] ?? $entry),
                'description' => (string) ($manifest['description'] ?? ''),
                'author' => (string) ($manifest['author'] ?? ''),
                'version' => (string) ($manifest['version'] ?? '1.0.0'),
                'class' => $class,
                'class_path' => $classPath,
                'config_keys' => $configKeys,
                'capabilities' => [
                    'supports_refund' => (bool) ($capabilities['supports_refund'] ?? false),
                    'supports_recurring' => (bool) ($capabilities['supports_recurring'] ?? false),
                    'supports_qris' => (bool) ($capabilities['supports_qris'] ?? false),
                    'supports_va' => (bool) ($capabilities['supports_va'] ?? false),
                    'supports_credit_card' => (bool) ($capabilities['supports_credit_card'] ?? false),
                    'supports_ewallet' => (bool) ($capabilities['supports_ewallet'] ?? false),
                ],
            ];
        }

        usort($plugins, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $plugins;
    }

    /**
     * Upserts every discovered plugin's metadata into `payment_gateways`,
     * so it appears in the owner's Settings > Payments gateway list exactly
     * like a built-in gateway. Safe to call on every settings-page load —
     * mirrors ThemeService::listAvailable() re-scanning on every request.
     */
    public function syncInstalled(): void
    {
        foreach ($this->listAvailable() as $plugin) {
            $this->gateways->upsertPluginGateway(
                $plugin['code'],
                $plugin['name'],
                $plugin['description'],
                $plugin['class'],
                $plugin['config_keys'],
                $plugin['capabilities'],
            );
        }
    }

    /**
     * Loads (if not already loaded this request) and returns the adapter
     * class for an installed plugin gateway, or null if the code is
     * unknown, no longer installed, or its class fails validation.
     */
    public function resolveAdapterClass(string $code): ?string
    {
        $normalized = strtoupper(trim($code));

        foreach ($this->listAvailable() as $plugin) {
            if ($plugin['code'] !== $normalized) {
                continue;
            }

            if (!class_exists($plugin['class'])) {
                require_once $plugin['class_path'];
            }

            if (!class_exists($plugin['class']) || !is_a($plugin['class'], PaymentGatewayInterface::class, true)) {
                return null;
            }

            return $plugin['class'];
        }

        return null;
    }
}
