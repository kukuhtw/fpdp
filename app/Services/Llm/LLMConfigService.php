<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\ValidationException;
use App\Repositories\LlmConfigRepository;
use App\Services\Content\MediaUploadService;
use App\Services\Security\RateLimiter;
use RuntimeException;

/**
 * Owner-facing LLM provider settings (see documentation/AI-MONETIZATION-STRATEGY.id.md
 * §4): configure a provider/model/API key, and — once a vision-capable
 * model is configured — generate a product description from an already-
 * uploaded photo. Mirrors PaymentService's gateway-settings half; there is
 * no visitor-facing surface here yet (no chatbot).
 */
final class LLMConfigService
{
    private const MAX_MODEL_LENGTH = 128;
    private const MAX_API_KEY_LENGTH = 512;
    private const DESCRIBE_PROMPT = 'Describe this product for an e-commerce listing in 2-3 sentences, in Bahasa Indonesia. Only return the description text, no preamble.';

    public function __construct(
        private readonly LlmConfigRepository $configs,
        private readonly MediaUploadService $media,
        private readonly ?RateLimiter $rateLimiter = null,
    ) {
    }

    /**
     * @return array{provider_code: string|null, model: string|null, key_configured: bool, key_hint: string|null, supports_vision: bool}
     */
    public function getSettings(int $nodeId): array
    {
        $config = $this->configs->getActiveConfig($nodeId);
        if ($config === null) {
            return ['provider_code' => null, 'model' => null, 'key_configured' => false, 'key_hint' => null, 'supports_vision' => false];
        }

        return [
            'provider_code' => $config['provider_code'],
            'model' => $config['model'],
            'key_configured' => true,
            'key_hint' => self::maskKey($config['api_key']),
            'supports_vision' => $config['supports_vision'],
        ];
    }

    /**
     * @return array{provider_code: string, model: string, key_configured: true, key_hint: string, supports_vision: bool}
     */
    public function updateSettings(int $nodeId, string $providerCode, string $model, string $apiKey, bool $supportsVision): array
    {
        $errors = [];

        $normalizedCode = strtoupper(trim($providerCode));
        if (!in_array($normalizedCode, LLMProviderFactory::SUPPORTED_CODES, true)) {
            $errors[] = ['field' => 'provider_code', 'reason' => 'unsupported_provider'];
        }

        $model = trim($model);
        if ($model === '' || mb_strlen($model) > self::MAX_MODEL_LENGTH) {
            $errors[] = ['field' => 'model', 'reason' => 'invalid_length'];
        }

        if ($apiKey === '' || strlen($apiKey) > self::MAX_API_KEY_LENGTH) {
            $errors[] = ['field' => 'api_key', 'reason' => 'invalid_length'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->configs->upsert($nodeId, $normalizedCode, $model, $apiKey, $supportsVision);

        return [
            'provider_code' => $normalizedCode,
            'model' => $model,
            'key_configured' => true,
            'key_hint' => self::maskKey($apiKey),
            'supports_vision' => $supportsVision,
        ];
    }

    /**
     * Sends an already-uploaded product photo (by its MediaUploadService
     * storage key) to the node's configured vision-capable LLM and returns
     * a suggested description. Owner-triggered only (a button, not
     * automatic on upload) since this is a billed call against the owner's
     * own provider account.
     */
    public function describeImage(int $nodeId, string $storageKey): string
    {
        $config = $this->configs->getActiveConfig($nodeId);
        if ($config === null) {
            throw new ConflictException('No LLM provider is configured yet. Configure one under Settings before generating a description.');
        }
        if (!$config['supports_vision']) {
            throw new ValidationException([['field' => 'model', 'reason' => 'vision_not_enabled']]);
        }

        $file = $this->media->read($storageKey);
        if ($file === null) {
            throw new ValidationException([['field' => 'storage_key', 'reason' => 'not_found']]);
        }

        $this->rateLimiter?->hit('llm_call', (string) $nodeId, self::rateLimitMax(), self::rateLimitWindow());

        $provider = LLMProviderFactory::create($config['provider_code'], [
            'api_key' => $config['api_key'],
            'model' => $config['model'],
        ]);

        $result = $provider->complete([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::DESCRIBE_PROMPT],
                    ['type' => 'image', 'mime_type' => $file['content_type'], 'data_base64' => base64_encode($file['content'])],
                ],
            ],
        ]);

        $description = trim($result['content']);
        if ($description === '') {
            throw new RuntimeException('The configured LLM returned an empty description.');
        }

        return $description;
    }

    private static function maskKey(string $apiKey): string
    {
        if (strlen($apiKey) <= 7) {
            return str_repeat('*', strlen($apiKey));
        }

        return substr($apiKey, 0, 3) . '...' . substr($apiKey, -4);
    }

    private static function rateLimitMax(): int
    {
        return (int) \App\Core\Config::get('RATE_LIMIT_LLM_CALL_MAX', '20');
    }

    private static function rateLimitWindow(): int
    {
        return (int) \App\Core\Config::get('RATE_LIMIT_LLM_CALL_WINDOW', '3600');
    }
}
