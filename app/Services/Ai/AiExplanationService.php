<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

class AiExplanationService
{
    public function __construct(
        private readonly AiClient $ai,
    ) {}

    /**
     * Produce deterministic bilingual compliance explanations with optional
     * local (air-gapped) LLM enrichment.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function explain(array $context): array
    {
        $fallback = $this->template($context);
        $provider = strtolower((string) config('triproof.local_ai.provider', 'none'));

        $generated = match ($provider) {
            'ollama' => $this->ollama($context),
            'llama_cpp', 'llamacpp', 'llama.cpp' => $this->llamaCpp($context),
            'openai', 'gemini' => $this->cloud($context),
            default => null,
        };

        if ($generated === null) {
            return array_merge($fallback, [
                'provider' => $provider === 'none' ? 'deterministic' : $provider.'_fallback',
                'model' => $provider === 'none' ? null : config('triproof.local_ai.model'),
                'fallback' => true,
            ]);
        }

        return array_merge($generated, [
            'provider' => $provider,
            'model' => config('triproof.local_ai.model'),
            'fallback' => false,
        ]);
    }

    /**
     * Anthropic-style contextual header for a chunk. Uses a local/cloud model
     * when configured, otherwise a deterministic metadata-derived header.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function contextHeader(string $text, array $metadata): string
    {
        $deterministic = trim(implode(' · ', array_filter([
            $metadata['factory'] ?? null,
            $metadata['category'] ?? null,
            $metadata['failure_mode'] ?? null,
            $metadata['source'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '')));

        if ($deterministic === '') {
            $deterministic = 'TriProof AI compliance corpus';
        }

        $provider = strtolower((string) config('triproof.local_ai.provider', 'none'));
        if (! in_array($provider, ['ollama', 'llama_cpp', 'llamacpp', 'llama.cpp', 'openai', 'gemini'], true)) {
            return $deterministic;
        }

        $prompt = 'Write one concise sentence (max 25 words) situating this chunk for retrieval. '
            .'Return plain text only. Chunk: '.mb_strimwidth(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 600, '…', 'UTF-8');

        try {
            $raw = match ($provider) {
                'ollama' => $this->ollamaRaw($prompt),
                'llama_cpp', 'llamacpp', 'llama.cpp' => $this->llamaCppRaw($prompt),
                default => $this->ai->chat(
                    'You write concise retrieval context headers. Return plain text only.',
                    $prompt
                ),
            };
        } catch (Throwable) {
            return $deterministic;
        }

        $header = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');

        return $header === '' ? $deterministic : mb_strimwidth($header, 0, 240, '…', 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function ollama(array $context): ?array
    {
        try {
            $raw = $this->ollamaRaw($this->prompt($context));
        } catch (Throwable) {
            return null;
        }

        return $this->parse($raw, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function llamaCpp(array $context): ?array
    {
        try {
            $raw = $this->llamaCppRaw($this->prompt($context));
        } catch (Throwable) {
            return null;
        }

        return $this->parse($raw, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function cloud(array $context): ?array
    {
        if (! $this->ai->isConfigured()) {
            return null;
        }

        try {
            $raw = $this->ai->chat('You are a bilingual supply-chain compliance assistant.', $this->prompt($context));
        } catch (Throwable) {
            return null;
        }

        return $this->parse($raw, $context);
    }

    private function ollamaRaw(string $prompt): string
    {
        $url = rtrim((string) config('triproof.local_ai.ollama_url'), '/').'/api/generate';

        $response = Http::timeout((int) config('triproof.local_ai.timeout', 30))
            ->throw()
            ->post($url, [
                'model' => (string) config('triproof.local_ai.model'),
                'prompt' => $prompt,
                'stream' => false,
                'options' => ['temperature' => 0],
            ]);

        return (string) ($response->json('response') ?? '');
    }

    private function llamaCppRaw(string $prompt): string
    {
        $url = rtrim((string) config('triproof.local_ai.llama_cpp_url'), '/').'/chat/completions';

        $response = Http::timeout((int) config('triproof.local_ai.timeout', 30))
            ->throw()
            ->post($url, [
                'model' => (string) config('triproof.local_ai.model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a bilingual supply-chain compliance assistant. Return JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0,
            ]);

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function prompt(array $context): string
    {
        return "Return JSON with explanation_en, explanation_bn, action_en and action_bn.\n"
            .'Context: '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function parse(string $raw, array $context): ?array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded) || trim((string) ($decoded['explanation_en'] ?? '')) === '') {
            return null;
        }

        $fallback = $this->template($context);

        return [
            'explanation_en' => (string) $decoded['explanation_en'],
            'explanation_bn' => (string) ($decoded['explanation_bn'] ?? $fallback['explanation_bn']),
            'action_en' => (string) ($decoded['action_en'] ?? $fallback['action_en']),
            'action_bn' => (string) ($decoded['action_bn'] ?? $fallback['action_bn']),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function template(array $context): array
    {
        $type = (string) ($context['anomaly_type'] ?? $context['kind'] ?? 'clone_cluster');
        $batch = (string) ($context['batch_id'] ?? 'the affected batch');

        return match ($type) {
            'ghost_production_attempt' => [
                'explanation_en' => "Quota mismatch detected for {$batch}; generated units exceed the authorized purchase-order quota.",
                'explanation_bn' => "{$batch}-এ কোটা অসমতা শনাক্ত: উৎপাদিত ইউনিট অনুমোদিত কোটার চেয়ে বেশি।",
                'action_en' => 'Freeze generation for this purchase order and reconcile the serial range.',
                'action_bn' => 'এই ক্রয় আদেশের জন্য জেনারেশন বন্ধ করুন এবং সিরিয়াল রেঞ্জ মিলান।',
            ],
            'rework_gap' => [
                'explanation_en' => "Rework lineage gap detected for {$batch}; a child serial is missing its certified parent.",
                'explanation_bn' => "{$batch}-এ রিওয়ার্ক লাইনেজ ফাঁক শনাক্ত: চাইল্ড সিরিয়ালের প্রত্যয়িত প্যারেন্ট নেই।",
                'action_en' => 'Quarantine the child unit and reconcile the parent-child relationship.',
                'action_bn' => 'চাইল্ড ইউনিট আলাদা করুন এবং প্যারেন্ট-চাইল্ড সম্পর্ক পুনরায় মিলান।',
            ],
            'defect_cluster' => [
                'explanation_en' => "Defect cluster detected for {$batch}; repeated quality failures exceed the reporting threshold.",
                'explanation_bn' => "{$batch}-এ ত্রুটি ক্লাস্টার শনাক্ত: পুনরাবৃত্ত মানের ব্যর্থতা রিপোর্ট সীমা অতিক্রম করেছে।",
                'action_en' => 'Inspect the affected machine and batch before release.',
                'action_bn' => 'মুক্তির পূর্বে আক্রান্ত মেশিন ও ব্যাচ পরিদর্শন করুন।',
            ],
            default => [
                'explanation_en' => "Clone-risk cluster detected for {$batch}; multiple devices reported the same signed serial.",
                'explanation_bn' => "{$batch}-এ ক্লোন ঝুঁকি ক্লাস্টার শনাক্ত: একাধিক ডিভাইস একই স্বাক্ষরিত সিরিয়াল রিপোর্ট করেছে।",
                'action_en' => 'Flag the serial as cloned and notify claimed device holders.',
                'action_bn' => 'সিরিয়ালটিকে ক্লোন হিসেবে চিহ্নিত করুন এবং দাবিকৃত ডিভাইস ধারকদের জানান।',
            ],
        };
    }
}
