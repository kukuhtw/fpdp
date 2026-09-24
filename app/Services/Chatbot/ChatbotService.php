<?php

declare(strict_types=1);

namespace App\Services\Chatbot;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\PaymentRequiredException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\ChatbotSettingsRepository;
use App\Repositories\ChatMessageRepository;
use App\Repositories\ChatSessionRepository;
use App\Repositories\LlmConfigRepository;
use App\Repositories\RagFaqRepository;
use App\Repositories\VisitorWalletRepository;
use App\Services\Llm\LLMProviderFactory;
use RuntimeException;
use Throwable;

/**
 * Visitor-facing paid chatbot (documentation/AI-MONETIZATION-STRATEGY.id.md
 * §6, built as Phase 2 after the RAG pipeline): answers are grounded only
 * in the owner's published RAG FAQs, never invented, and every reply is
 * labelled automated (§6.3) so it's never mistaken for the owner typing
 * live. Pricing is a flat per-question charge the owner sets — deducted
 * from the visitor's deposit wallet on every question, refunded if the LLM
 * call itself fails so a provider error never costs the visitor anything.
 */
final class ChatbotService
{
    private const DEFAULT_TOP_K = 3;
    private const MAX_QUESTION_LENGTH = 1000;
    private const ALLOWED_CURRENCIES = ['IDR', 'USD'];
    private const SYSTEM_PROMPT = 'You are an automated assistant answering on behalf of a website owner. '
        . 'Answer ONLY using the grounding context below. If the context does not contain the answer, '
        . 'say you do not know rather than guessing or inventing information. Never claim to be the owner '
        . 'speaking personally, and keep answers concise.';

    public function __construct(
        private readonly ChatbotSettingsRepository $settings,
        private readonly VisitorWalletRepository $wallets,
        private readonly ChatSessionRepository $sessions,
        private readonly ChatMessageRepository $messages,
        private readonly RagFaqRepository $faqs,
        private readonly LlmConfigRepository $llmConfigs,
    ) {
    }

    /**
     * Public (no auth) — lets the profile page show pricing before the
     * visitor signs in.
     *
     * @return array{enabled: bool, price_per_question: string, currency: string}
     */
    public function getPublicSettings(int $nodeId): array
    {
        $settings = $this->settings->findByNodeId($nodeId);

        return [
            'enabled' => $settings !== null && (string) $settings['status'] === 'ENABLED',
            'price_per_question' => $settings !== null ? (string) $settings['price_per_question'] : '0',
            'currency' => $settings !== null ? (string) $settings['currency'] : 'IDR',
        ];
    }

    /**
     * @return array{enabled: bool, price_per_question: string, currency: string}
     */
    public function updateSettings(int $nodeId, string $pricePerQuestion, string $currency, bool $enabled): array
    {
        $errors = [];
        if (!is_numeric($pricePerQuestion) || (float) $pricePerQuestion < 0) {
            $errors[] = ['field' => 'price_per_question', 'reason' => 'invalid_value'];
        }
        $currency = strtoupper($currency);
        if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            $errors[] = ['field' => 'currency', 'reason' => 'unsupported_currency'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->settings->upsert($nodeId, number_format((float) $pricePerQuestion, 2, '.', ''), $currency, $enabled ? 'ENABLED' : 'DISABLED');

        return $this->getPublicSettings($nodeId);
    }

    /**
     * @param array<string, mixed> $visitor
     * @return array{session_id: string, question: string, answer: string, automated: true, wallet_balance: string, cost_charged: string}
     */
    public function ask(int $nodeId, array $visitor, string $question): array
    {
        $question = trim($question);
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            throw new ValidationException([['field' => 'question', 'reason' => 'invalid_length']]);
        }

        $settings = $this->settings->findByNodeId($nodeId);
        if ($settings === null || (string) $settings['status'] !== 'ENABLED') {
            throw new ConflictException('The chatbot is not enabled for this profile yet.');
        }

        $config = $this->llmConfigs->getActiveConfig($nodeId);
        if ($config === null) {
            throw new ConflictException('No LLM provider is configured for this node yet.');
        }

        $visitorId = (int) $visitor['id'];
        $wallet = $this->wallets->getOrCreate($visitorId, (string) $settings['currency']);
        $price = (string) $settings['price_per_question'];
        $charging = (float) $price > 0.0;

        if ($charging && !$this->wallets->debit((int) $wallet['id'], $price, 'CHAT_COST', 'Chat question')) {
            throw new PaymentRequiredException('Insufficient deposit balance. Please top up before asking another question.');
        }

        try {
            $provider = LLMProviderFactory::create($config['provider_code'], [
                'api_key' => $config['api_key'],
                'model' => $config['model'],
            ]);
            $grounding = $this->buildGroundingContext($nodeId, $provider, $question);
            $result = $provider->complete([
                ['role' => 'user', 'content' => self::SYSTEM_PROMPT . "\n\nGrounding context:\n" . $grounding . "\n\nVisitor question: " . $question],
            ]);
        } catch (Throwable $e) {
            if ($charging) {
                $this->wallets->credit((int) $wallet['id'], $price, 'REFUND', null, 'Refund: chatbot call failed');
            }
            throw new RuntimeException('The chatbot could not generate an answer right now. Your deposit was not charged.', 0, $e);
        }

        $session = $this->sessions->findActiveForVisitor($nodeId, $visitorId);
        $sessionId = $session !== null ? (int) $session['id'] : $this->sessions->create(Uuid::v4(), $nodeId, $visitorId);

        $this->messages->create($sessionId, 'VISITOR', $question, $charging ? $price : null);
        $this->sessions->recordMessage($sessionId);
        $this->messages->create($sessionId, 'ASSISTANT', $result['content']);
        $this->sessions->recordMessage($sessionId);

        $updatedWallet = $this->wallets->findById((int) $wallet['id']) ?? $wallet;

        return [
            'session_id' => $session['public_id'] ?? $this->sessionPublicId($sessionId),
            'question' => $question,
            'answer' => $result['content'],
            'automated' => true,
            'wallet_balance' => (string) $updatedWallet['balance_amount'],
            'cost_charged' => $charging ? $price : '0',
        ];
    }

    /**
     * @return array{balance_amount: string, currency: string}
     */
    public function getWalletBalance(int $visitorId, string $currency = 'IDR'): array
    {
        $wallet = $this->wallets->getOrCreate($visitorId, $currency);

        return ['balance_amount' => (string) $wallet['balance_amount'], 'currency' => (string) $wallet['currency']];
    }

    private function sessionPublicId(int $sessionId): string
    {
        return (string) ($this->sessions->findById($sessionId)['public_id'] ?? '');
    }

    private function buildGroundingContext(int $nodeId, \App\Contracts\LLMProviderInterface $provider, string $question): string
    {
        $faqs = $this->faqs->listEmbeddedByNodeId($nodeId);
        if ($faqs === []) {
            return 'No grounding documents have been published yet.';
        }

        try {
            $questionVector = $provider->embed($question)['vector'];
        } catch (Throwable) {
            // A provider without embeddings support (e.g. Anthropic) can't
            // rank relevance — fall back to the first few FAQs rather than
            // failing the whole question.
            return self::formatFaqs(array_slice($faqs, 0, self::DEFAULT_TOP_K));
        }

        $ranked = [];
        foreach ($faqs as $faq) {
            $vector = json_decode((string) $faq['embedding'], true);
            if (!is_array($vector)) {
                continue;
            }
            $ranked[] = ['faq' => $faq, 'score' => self::cosineSimilarity($questionVector, $vector)];
        }
        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return self::formatFaqs(array_map(static fn (array $r) => $r['faq'], array_slice($ranked, 0, self::DEFAULT_TOP_K)));
    }

    /**
     * @param array<int, array<string, mixed>> $faqs
     */
    private static function formatFaqs(array $faqs): string
    {
        if ($faqs === []) {
            return 'No grounding documents have been published yet.';
        }

        $lines = [];
        foreach ($faqs as $faq) {
            $lines[] = "Q: {$faq['question']}\nA: {$faq['answer']}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
