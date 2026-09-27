<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\I18n;
use App\Repositories\NodeRepository;
use App\Services\Auth\AuthService;
use App\Services\Security\AuditService;

/**
 * The owner's language settings for the public pages: which language they
 * open in, and which ones visitors may switch to (see I18n).
 */
final class LocaleSettingsController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly NodeRepository $nodes,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * GET /api/v1/me/locale-settings
     */
    public function show(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->present($context['node']));
    }

    /**
     * PATCH /api/v1/me/locale-settings — {"default_locale": "id", "enabled_locales": ["id", "en"]}
     */
    public function update(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        $default = (string) ($input['default_locale'] ?? '');
        $enabled = $input['enabled_locales'] ?? null;
        if (!is_array($enabled) || array_filter($enabled, 'is_string') !== $enabled) {
            throw new ValidationException([['field' => 'enabled_locales', 'reason' => 'invalid_value']]);
        }
        $enabled = array_values(array_unique($enabled));
        $errors = I18n::validateSettings($default, $enabled);
        if ($errors !== []) {
            throw new ValidationException($errors, 'Pick at least one language, and make the default one of them.');
        }
        // Stored in the supported order (id, en), whatever order was sent.
        $enabled = array_values(array_intersect(I18n::SUPPORTED, $enabled));

        $nodeId = (int) $context['node']['id'];
        $this->nodes->updateLocaleSettings($nodeId, $default, $enabled);
        $this->audit?->record($context, 'node.locale_updated', 'node', null, ['default_locale' => $default, 'enabled_locales' => $enabled]);

        return JsonEnvelope::success($this->present($this->nodes->findById($nodeId) ?? $context['node']));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function present(array $node): array
    {
        $settings = I18n::normalizeSettings(['default' => $node['default_locale'] ?? null, 'enabled' => $node['enabled_locales'] ?? null]);

        return [
            'default_locale' => $settings['default'],
            'enabled_locales' => $settings['enabled'],
            'supported_locales' => array_map(static fn (string $code): array => ['code' => $code, 'label' => I18n::LABELS[$code]], I18n::SUPPORTED),
        ];
    }
}
