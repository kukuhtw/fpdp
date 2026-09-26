<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * The single place that decides which dashboard user role may do what.
 *
 * OWNER may do everything. ADMIN is a helper who can run the node's content,
 * shop, and everyday federation, but cannot touch money, credentials, buyer
 * personal data, or which remote servers the node trusts — the actions that
 * are costly or irreversible when misused. AuthService::authenticate()
 * rejects any role not listed here, and AuthService::authorize() checks the
 * permission for the sensitive endpoints.
 */
final class AccessPolicy
{
    /** Confirm, cancel, refund, reconcile payments; view payments with buyer details. */
    public const PAYMENTS_MANAGE = 'payments.manage';
    /** Store gateway credentials and pick the active gateway. */
    public const PAYMENTS_CONFIGURE = 'payments.configure';
    /** Store the LLM provider API key and model. */
    public const LLM_CONFIGURE = 'llm.configure';
    /** Credit a visitor's wallet for free. */
    public const WALLET_GRANT = 'wallet.grant';
    /** Trust or block remote nodes/actors and change advertised capabilities. */
    public const FEDERATION_TRUST = 'federation.trust';

    private const ROLE_PERMISSIONS = [
        'OWNER' => [
            self::PAYMENTS_MANAGE,
            self::PAYMENTS_CONFIGURE,
            self::LLM_CONFIGURE,
            self::WALLET_GRANT,
            self::FEDERATION_TRUST,
        ],
        'ADMIN' => [],
    ];

    public static function isKnownRole(string $role): bool
    {
        return array_key_exists(strtoupper($role), self::ROLE_PERMISSIONS);
    }

    public static function allows(string $role, string $permission): bool
    {
        return in_array($permission, self::ROLE_PERMISSIONS[strtoupper($role)] ?? [], true);
    }
}
