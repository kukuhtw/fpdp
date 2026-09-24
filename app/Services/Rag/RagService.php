<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\LlmConfigRepository;
use App\Repositories\RagDocumentRepository;
use App\Repositories\RagFaqRepository;
use App\Services\Llm\LLMProviderFactory;
use App\Services\Security\RateLimiter;
use Throwable;

/**
 * Owner-facing RAG pipeline (documentation/AI-MONETIZATION-STRATEGY.id.md's
 * chatbot grounding, built out ahead of schedule at the user's request):
 * upload a plain-text/markdown document about yourself, generate an
 * editable FAQ list from it via the configured LLM, and embed each FAQ for
 * later similarity search once the chatbot (a separate, later feature)
 * needs to retrieve grounding context for a visitor's question.
 *
 * Embeddings are stored as a JSON float array per FAQ (`rag_faqs.embedding`)
 * rather than in a dedicated vector store — MySQL has no native vector
 * search, and a brute-force cosine-similarity scan in PHP is more than
 * adequate for the FAQ counts a single personal node will ever hold (tens
 * to low hundreds, not millions), so it isn't worth a new infrastructure
 * dependency for this.
 */
final class RagService
{
    private const ALLOWED_CONTENT_TYPES = ['text/plain', 'text/markdown'];
    private const MAX_TITLE_LENGTH = 255;
    private const MAX_CONTENT_BYTES = 200000;
    private const DEFAULT_FAQ_COUNT = 5;
    private const MAX_FAQ_COUNT = 20;
    private const MAX_QUESTION_LENGTH = 500;
    private const MAX_ANSWER_LENGTH = 4000;

    public function __construct(
        private readonly RagDocumentRepository $documents,
        private readonly RagFaqRepository $faqs,
        private readonly LlmConfigRepository $llmConfigs,
        private readonly ?RateLimiter $rateLimiter = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function uploadDocument(int $nodeId, array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $contentType = (string) ($input['content_type'] ?? 'text/plain');
        $contentBase64 = (string) ($input['content_base64'] ?? '');
        $originalFilename = isset($input['filename']) ? mb_substr((string) $input['filename'], 0, 255) : null;

        $errors = [];
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            $errors[] = ['field' => 'title', 'reason' => 'invalid_length'];
        }
        if (!in_array($contentType, self::ALLOWED_CONTENT_TYPES, true)) {
            $errors[] = ['field' => 'content_type', 'reason' => 'invalid_value'];
        }

        $decoded = $contentBase64 === '' ? false : base64_decode($contentBase64, true);
        if ($decoded === false || $decoded === '') {
            $errors[] = ['field' => 'content_base64', 'reason' => 'invalid_encoding'];
        } elseif (strlen($decoded) > self::MAX_CONTENT_BYTES) {
            $errors[] = ['field' => 'content_base64', 'reason' => 'file_too_large'];
        } elseif (!mb_check_encoding($decoded, 'UTF-8')) {
            $errors[] = ['field' => 'content_base64', 'reason' => 'not_valid_utf8_text'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $documentId = $this->documents->create(Uuid::v4(), $nodeId, $title, $originalFilename, (string) $decoded);

        return $this->documents->findById($documentId) ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDocuments(int $nodeId): array
    {
        return $this->documents->listByNodeId($nodeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOwnedDocument(int $nodeId, string $documentPublicId): array
    {
        $document = $this->documents->findByPublicId($documentPublicId);
        if ($document === null) {
            throw new NotFoundException('Document not found.');
        }
        if ((int) $document['node_id'] !== $nodeId) {
            throw new ForbiddenException('You do not own this document.');
        }

        return $document;
    }

    public function deleteDocument(int $nodeId, string $documentPublicId): void
    {
        $document = $this->getOwnedDocument($nodeId, $documentPublicId);
        $this->documents->delete((int) $document['id']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFaqs(int $nodeId, string $documentPublicId): array
    {
        $document = $this->getOwnedDocument($nodeId, $documentPublicId);

        return $this->faqs->listByDocumentId((int) $document['id']);
    }

    /**
     * Generates `count` FAQ pairs from a document via the node's configured
     * LLM, storing and best-effort embedding each one immediately. A
     * provider without embeddings support (Anthropic) still produces usable
     * FAQ text — the embedding just stays null until an embedding-capable
     * provider is configured and the FAQ is re-saved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generateFaqs(int $nodeId, string $documentPublicId, int $count): array
    {
        $document = $this->getOwnedDocument($nodeId, $documentPublicId);
        $count = max(1, min(self::MAX_FAQ_COUNT, $count ?: self::DEFAULT_FAQ_COUNT));

        $config = $this->llmConfigs->getActiveConfig($nodeId);
        if ($config === null) {
            throw new ConflictException('No LLM provider is configured yet. Configure one under Settings before generating FAQs.');
        }

        $this->rateLimiter?->hit('llm_call', (string) $nodeId, self::rateLimitMax(), self::rateLimitWindow());

        $provider = LLMProviderFactory::create($config['provider_code'], [
            'api_key' => $config['api_key'],
            'model' => $config['model'],
        ]);

        $prompt = sprintf(
            "You are drafting a FAQ section for someone's personal website, grounded only in the document below. "
            . "Generate exactly %d frequently-asked-question pairs a visitor might reasonably ask, with concise, factual answers "
            . "based only on this document — never invent information it doesn't contain. "
            . "Respond with ONLY a JSON array, no other text, no markdown code fences, in this exact shape: "
            . '[{"question": "...", "answer": "..."}, ...]' . "\n\nDocument:\n%s",
            $count,
            $document['content'],
        );

        $result = $provider->complete([['role' => 'user', 'content' => $prompt]]);
        $pairs = self::parseFaqPairs($result['content']);

        $created = [];
        foreach ($pairs as $pair) {
            $question = mb_substr(trim($pair['question']), 0, self::MAX_QUESTION_LENGTH);
            $answer = mb_substr(trim($pair['answer']), 0, self::MAX_ANSWER_LENGTH);
            if ($question === '' || $answer === '') {
                continue;
            }

            $faqId = $this->faqs->create(Uuid::v4(), (int) $document['id'], $question, $answer);
            $this->tryEmbed($config, $faqId, $question, $answer);
            $created[] = $this->faqs->findById($faqId) ?? [];
        }

        return array_values(array_filter($created, static fn ($row) => $row !== []));
    }

    /**
     * @return array<string, mixed>
     */
    public function updateFaq(int $nodeId, string $faqPublicId, string $question, string $answer): array
    {
        $faq = $this->requireOwnedFaq($nodeId, $faqPublicId);

        $question = trim($question);
        $answer = trim($answer);
        $errors = [];
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            $errors[] = ['field' => 'question', 'reason' => 'invalid_length'];
        }
        if ($answer === '' || mb_strlen($answer) > self::MAX_ANSWER_LENGTH) {
            $errors[] = ['field' => 'answer', 'reason' => 'invalid_length'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->faqs->updateText((int) $faq['id'], $question, $answer);

        $config = $this->llmConfigs->getActiveConfig($nodeId);
        if ($config !== null) {
            $this->rateLimiter?->hit('llm_call', (string) $nodeId, self::rateLimitMax(), self::rateLimitWindow());
            $this->tryEmbed($config, (int) $faq['id'], $question, $answer);
        }

        return $this->faqs->findByPublicId($faqPublicId) ?? [];
    }

    public function deleteFaq(int $nodeId, string $faqPublicId): void
    {
        $faq = $this->requireOwnedFaq($nodeId, $faqPublicId);
        $this->faqs->delete((int) $faq['id']);
    }

    /**
     * @param array{provider_code: string, model: string, api_key: string} $config
     */
    private function tryEmbed(array $config, int $faqId, string $question, string $answer): void
    {
        try {
            $provider = LLMProviderFactory::create($config['provider_code'], ['api_key' => $config['api_key']]);
            $result = $provider->embed($question . "\n" . $answer);
            $this->faqs->updateEmbedding($faqId, $result['vector'], $config['provider_code']);
        } catch (Throwable) {
            // Best-effort: a provider without embeddings support (e.g.
            // Anthropic) or a transient failure leaves the FAQ's embedding
            // null rather than failing FAQ generation/editing entirely —
            // the FAQ text itself is still useful, and re-saving it later
            // (once an embedding-capable provider is configured) retries.
        }
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    private static function parseFaqPairs(string $rawContent): array
    {
        $jsonText = trim($rawContent);
        if (!str_starts_with($jsonText, '[')) {
            // The model sometimes wraps the array in prose or a ```json fence
            // despite instructions not to — pull out the first [...] block.
            if (preg_match('/\[.*\]/s', $jsonText, $match) === 1) {
                $jsonText = $match[0];
            }
        }

        $decoded = json_decode($jsonText, true);
        if (!is_array($decoded)) {
            throw new ValidationException([['field' => '_', 'reason' => 'llm_response_not_parseable']], 'The configured LLM did not return a parseable FAQ list.');
        }

        $pairs = [];
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['question'], $item['answer']) && is_string($item['question']) && is_string($item['answer'])) {
                $pairs[] = ['question' => $item['question'], 'answer' => $item['answer']];
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwnedFaq(int $nodeId, string $faqPublicId): array
    {
        $faq = $this->faqs->findByPublicId($faqPublicId);
        if ($faq === null) {
            throw new NotFoundException('FAQ not found.');
        }
        $document = $this->documents->findById((int) $faq['document_id']);
        if ($document === null || (int) $document['node_id'] !== $nodeId) {
            throw new ForbiddenException('You do not own this FAQ.');
        }

        return $faq;
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
