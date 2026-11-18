<?php

namespace App\Services\Ai;

use App\Exceptions\AiResponseException;
use App\Exceptions\AiUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiClient
{
    public function isConfigured(): bool
    {
        $provider = (string) config('triproof.ai.provider');

        return in_array($provider, ['openai', 'gemini'], true)
            && filled(config('triproof.ai.api_key'));
    }

    public function provider(): string
    {
        return (string) config('triproof.ai.provider');
    }

    public function generateJson(string $systemPrompt, string $userPrompt, ?array $image = null): array
    {
        $text = $this->chat($systemPrompt, $userPrompt, $image);
        $decoded = $this->extractJson($text);

        if (! is_array($decoded)) {
            throw new AiResponseException('The AI response was not valid JSON.');
        }

        return $decoded;
    }

    public function chat(string $systemPrompt, string $userPrompt, ?array $image = null): string
    {
        if (! $this->isConfigured()) {
            throw new AiUnavailableException;
        }

        return match ($this->provider()) {
            'openai' => $this->openAiChat($systemPrompt, $userPrompt, $image),
            'gemini' => $this->geminiChat($systemPrompt, $userPrompt, $image),
            default => throw new AiUnavailableException,
        };
    }

    public function extractSerialsFromImage(string $base64, string $mime): array
    {
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (! in_array($mime, $allowed, true)) {
            throw new AiUnavailableException('Unsupported image type for Vision AI.');
        }

        $system = <<<'PROMPT'
You are the secure label OCR engine for TriProof AI. Read the supplied product label image.
Extract only text you can actually see. Never invent or "repair" characters from memory.
The outer serial looks like FACTORY-MACHINE-BATCH-123456|YYYYMMDD|YYYYMMDD.
The inner serial is a long Base64 string (an Ed25519 signature) printed near the outer serial.
Return ONLY strict JSON with exactly these keys:
{"outer_serial":"...","inner_serial":"...","confidence":0.0,"notes":"short reason if unreadable"}
Use an empty string when a value is not present. confidence is 0 to 1.
PROMPT;

        $user = 'Extract the outer serial and inner serial from this label. Return JSON only.';

        $result = $this->generateJson($system, $user, ['base64' => $base64, 'mime' => $mime]);

        return [
            'outer_serial' => trim((string) ($result['outer_serial'] ?? '')),
            'inner_serial' => trim((string) ($result['inner_serial'] ?? '')),
            'confidence' => max(0, min(1, (float) ($result['confidence'] ?? 0))),
            'notes' => trim((string) ($result['notes'] ?? '')),
        ];
    }

    public function anomalyAdvice(array $context): array
    {
        $system = <<<'PROMPT'
You are a senior supply-chain compliance analyst for garment factories in Bangladesh.
Analyse the anomaly context and give exact, safe, actionable instructions for a factory
supervisor and a short compliance summary for the global buyer. Bangla must be plain,
practical Bangla in Bengali script (not transliteration), understandable on the factory floor.
Never suggest generating new signatures, editing serials or bypassing quotas.
Return ONLY strict JSON:
{"explanation_en":"...","explanation_bn":"...","action_en":"...","action_bn":"...","severity":"low|medium|high"}
PROMPT;

        $result = $this->generateJson($system, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach (['explanation_en', 'explanation_bn', 'action_en', 'action_bn'] as $key) {
            if (! isset($result[$key]) || ! is_string($result[$key]) || trim($result[$key]) === '') {
                throw new AiResponseException('AI advice is missing required bilingual fields.');
            }
        }

        return $result;
    }

    public function reworkAdvice(array $context): array
    {
        $system = <<<'PROMPT'
You are a TriProof AI rework-lineage analyst. Summarize the reconciliation result for
factory QA. Explain only what the evidence supports. Bangla must be clear and actionable
in Bengali script. Do not invent serial links and do not propose new signatures.
Return ONLY strict JSON:
{"summary_en":"...","summary_bn":"..."}
PROMPT;

        $result = $this->generateJson($system, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (empty($result['summary_en']) || empty($result['summary_bn'])) {
            throw new AiResponseException('AI rework summary is incomplete.');
        }

        return $result;
    }

    private function openAiChat(string $systemPrompt, string $userPrompt, ?array $image): string
    {
        $base = (string) config('triproof.ai.base_url');
        $url = Str::endsWith($base, '/chat/completions') ? $base : $base.'/chat/completions';
        $model = $image ? config('triproof.ai.vision_model') : config('triproof.ai.model');

        $content = $userPrompt;
        if ($image) {
            $content = [
                ['type' => 'text', 'text' => $userPrompt],
                ['type' => 'image_url', 'image_url' => [
                    'url' => 'data:'.$image['mime'].';base64,'.$image['base64'],
                ]],
            ];
        }

        $response = Http::timeout((int) config('triproof.ai.timeout', 25))
            ->withToken((string) config('triproof.ai.api_key'))
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => 1400,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $content],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('AI provider request failed', ['provider' => 'openai', 'status' => $response->status()]);
            throw new AiResponseException('The AI provider could not complete the request.');
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            throw new AiResponseException('The AI provider returned an empty response.');
        }

        return $text;
    }

    private function geminiChat(string $systemPrompt, string $userPrompt, ?array $image): string
    {
        $base = (string) config('triproof.ai.gemini_base_url');
        $model = $image ? config('triproof.ai.vision_model') : config('triproof.ai.model');
        $url = "{$base}/models/{$model}:generateContent";

        $parts = [['text' => $systemPrompt."\n\n".$userPrompt]];
        if ($image) {
            $parts[] = ['inline_data' => ['mime_type' => $image['mime'], 'data' => $image['base64']]];
        }

        $response = Http::timeout((int) config('triproof.ai.timeout', 25))
            ->acceptJson()
            ->asJson()
            ->post($url.'?key='.urlencode((string) config('triproof.ai.api_key')), [
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 1600,
                ],
            ]);

        if ($response->failed()) {
            Log::warning('AI provider request failed', ['provider' => 'gemini', 'status' => $response->status()]);
            throw new AiResponseException('The AI provider could not complete the request.');
        }

        $parts = $response->json('candidates.0.content.parts');
        if (! is_array($parts)) {
            throw new AiResponseException('The AI provider returned an empty response.');
        }

        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        if (trim($text) === '') {
            throw new AiResponseException('The AI provider returned an empty response.');
        }

        return $text;
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
