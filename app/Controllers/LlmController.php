<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Llm\LLMConfigService;
use App\Services\Security\AuditService;

final class LlmController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly LLMConfigService $llmConfig,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * GET /api/v1/me/llm-config
     *
     * Owner-only. Never returns the full API key — only whether one is
     * configured and a masked hint (e.g. "sk-...ab12").
     */
    public function getSettings(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->llmConfig->getSettings((int) $context['node']['id']));
    }

    /**
     * PATCH /api/v1/me/llm-config
     *
     * Owner-only: store (encrypted) the provider/model/API key and whether
     * the model can read images.
     */
    public function updateSettings(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        $input = $request->json() ?? [];
        $result = $this->llmConfig->updateSettings(
            (int) $context['node']['id'],
            (string) ($input['provider_code'] ?? ''),
            (string) ($input['model'] ?? ''),
            (string) ($input['api_key'] ?? ''),
            (bool) ($input['supports_vision'] ?? false),
        );
        // Provider and model only — never the API key.
        $this->audit?->record($context, 'llm.configured', 'llm_config', null, [
            'provider_code' => $result['provider_code'] ?? null,
            'model' => $result['model'] ?? null,
        ]);

        return JsonEnvelope::success($result);
    }

    /**
     * POST /api/v1/me/llm-config/describe-image
     *
     * Owner-only, manually triggered (not automatic on upload): generates a
     * product description from an already-uploaded photo's storage key,
     * using the node's configured vision-capable LLM.
     */
    public function describeImage(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        $input = $request->json() ?? [];
        $description = $this->llmConfig->describeImage(
            (int) $context['node']['id'],
            (string) ($input['storage_key'] ?? ''),
        );

        return JsonEnvelope::success(['description' => $description]);
    }
}
