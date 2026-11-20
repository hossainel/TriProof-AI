<?php

namespace App\Services\Ai;

use App\Models\AiAnomalyReport;
use Illuminate\Support\Facades\Http;
use Throwable;

class ContextualRagService
{
    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'are', 'was', 'were', 'will',
        'into', 'about', 'what', 'which', 'how', 'does', 'can', 'all', 'any', 'not', 'but',
        'has', 'have', 'had', 'its', 'their', 'our', 'your', 'you', 'of', 'to', 'in', 'on',
        'at', 'by', 'is', 'be', 'একটি', 'এবং', 'জন্য', 'সহ', 'অথবা', 'কিন্তু', 'এই', 'সেই',
    ];

    public function __construct(
        private readonly AiExplanationService $explanations,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function chunks(): array
    {
        $chunks = [];

        foreach ([
            'factory_sop' => base_path('docs/SOP.md'),
            'gs1_compliance' => base_path('docs/GS1-COMPLIANCE.md'),
        ] as $source => $path) {
            $chunks = array_merge($chunks, $this->documentChunks((string) $source, $path));
        }

        foreach (AiAnomalyReport::query()->latest()->limit(50)->get() as $report) {
            $body = implode("\n", array_filter([
                'Detector: '.$report->anomaly_type->value,
                'Status: '.$report->status,
                'Batch: '.($report->batch_id ?? 'unscoped'),
                'English: '.$report->explanation_en,
                'Bangla: '.$report->explanation_bn,
            ]));

            $chunks = array_merge($chunks, $this->makeChunks($body, [
                'source' => 'ai_anomaly_reports',
                'section' => $report->anomaly_type->value,
                'factory' => $report->po?->factory_id,
                'category' => $report->batch_id ?? 'unscoped',
                'failure_mode' => $report->anomaly_type->value,
                'citation' => 'anomaly:'.$report->id,
                'metadata' => [
                    'type' => 'anomaly_report',
                    'id' => $report->id,
                    'status' => $report->status,
                    'created_at' => $report->created_at?->toIso8601String(),
                ],
            ]));
        }

        $chunks = array_values($chunks);
        $this->pushToVectorStore($chunks);

        return $chunks;
    }

    /**
     * Hybrid BM25 + dense-vector retrieval with contextual RAG headers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $chunks = $this->chunks();
        if ($chunks === []) {
            return [];
        }

        $queryTokens = $this->tokens($query);
        if ($queryTokens === []) {
            return [];
        }

        $documents = array_map(fn (array $chunk) => $this->tokens($chunk['text']), $chunks);
        $documentFrequency = [];
        foreach ($documents as $tokens) {
            foreach (array_unique($tokens) as $token) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
            }
        }

        $total = count($chunks);
        $averageLength = max(1, array_sum(array_map('count', $documents)) / $total);
        $bm25Scores = [];

        foreach ($documents as $index => $tokens) {
            $length = max(1, count($tokens));
            $counts = array_count_values($tokens);
            $score = 0.0;

            foreach (array_unique($queryTokens) as $token) {
                $frequency = $documentFrequency[$token] ?? 0;
                if ($frequency === 0) {
                    continue;
                }
                $idf = log(1 + ($total - $frequency + 0.5) / ($frequency + 0.5));
                $termFrequency = $counts[$token] ?? 0;
                $score += $idf * ($termFrequency * (1.5 + 1))
                    / ($termFrequency + 1.5 * (1 - 0.75 + 0.75 * $length / $averageLength));
            }

            $bm25Scores[$index] = $score;
        }

        $maxBm25 = max(0.000001, max($bm25Scores));
        $queryEmbedding = $this->embedding($query);
        $vectorHits = $this->vectorSearch($queryEmbedding, $limit * 2);

        $scored = [];
        foreach ($chunks as $index => $chunk) {
            $bm25 = $bm25Scores[$index] / $maxBm25;
            $dense = $this->cosine($queryEmbedding, $this->embedding($chunk['text']));
            $boost = 0.0;

            if (in_array($index, $vectorHits['indices'] ?? [], true)) {
                $boost += 0.25;
            }
            if (($chunk['metadata']['type'] ?? '') === 'anomaly_report') {
                $boost += 0.08;
            }

            $scored[] = array_merge($chunk, [
                'score' => round(0.65 * $bm25 + 0.35 * $dense + $boost, 6),
                'bm25_score' => round($bm25Scores[$index], 6),
                'dense_score' => round($dense, 6),
            ]);
        }

        usort($scored, fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: strcmp((string) $left['citation'], (string) $right['citation']));

        return array_slice($scored, 0, max(1, min(20, $limit)));
    }

    /**
     * @return array<string, mixed>
     */
    public function answer(string $question, int $limit = 5): array
    {
        $chunks = $this->retrieve($question, $limit);

        if ($chunks === []) {
            return [
                'answer_en' => 'No grounded factory SOP, compliance guideline, or anomaly record matched this question.',
                'answer_bn' => 'এই প্রশ্নের সাথে মেলে এমন কোনো SOP, কমপ্লায়েন্স নির্দেশিকা বা অসঙ্গতি রেকর্ড পাওয়া যায়নি।',
                'citations' => [],
                'chunks' => [],
                'grounded' => false,
            ];
        }

        $excerpt = implode(' ', array_map(
            fn (array $chunk): string => '['.$chunk['citation'].'] '.preg_replace('/\s+/u', ' ', $chunk['text']),
            $chunks
        ));

        return [
            'answer_en' => 'Grounded TriProof compliance context: '.mb_strimwidth($excerpt, 0, 1600, '…', 'UTF-8'),
            'answer_bn' => 'ট্রাইপ্রুফ কমপ্লায়েন্স প্রসঙ্গ: '.mb_strimwidth($excerpt, 0, 1600, '…', 'UTF-8'),
            'citations' => array_column($chunks, 'citation'),
            'chunks' => $chunks,
            'grounded' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documentChunks(string $source, string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $content = (string) file_get_contents($path);
        $sections = preg_split('/(?=^#{1,6}\s+)/m', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];

        foreach ($sections as $section) {
            $lines = preg_split('/\R/u', trim($section), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $heading = trim(ltrim((string) array_shift($lines), "# \t"));
            $body = trim(implode("\n", $lines));
            if ($body === '') {
                continue;
            }

            $paragraphs = preg_split('/\n\s*\n/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $groups = [];
            $current = '';

            foreach ($paragraphs as $paragraph) {
                if ($current !== '' && strlen($current) + strlen($paragraph) > 900) {
                    $groups[] = $current;
                    $current = $paragraph;
                } else {
                    $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
                }
            }
            if ($current !== '') {
                $groups[] = $current;
            }

            foreach ($groups as $group) {
                $chunks = array_merge($chunks, $this->makeChunks($group, [
                    'source' => $source,
                    'section' => $heading,
                    'factory' => null,
                    'category' => $source === 'factory_sop' ? 'factory_operations' : 'gs1_compliance',
                    'failure_mode' => $heading,
                    'citation' => $source.'#'.$this->slug($heading),
                    'metadata' => [
                        'type' => 'contextual_document',
                        'source' => $source,
                        'section' => $heading,
                    ],
                ]));
            }
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, array<string, mixed>>
     */
    private function makeChunks(string $body, array $spec): array
    {
        $contextHeader = $this->explanations->contextHeader($body, $spec);
        $text = '[Context: '.$contextHeader."]\n".trim($body);

        return [[
            'id' => md5($spec['citation'].'|'.mb_substr($text, 0, 120, 'UTF-8')),
            'source' => $spec['source'],
            'section' => $spec['section'],
            'context_header' => $contextHeader,
            'text' => $text,
            'citation' => $spec['citation'],
            'metadata' => $spec['metadata'],
        ]];
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $parts,
            fn (string $token): bool => mb_strlen($token, 'UTF-8') > 1 && ! in_array($token, self::STOP_WORDS, true)
        ));
    }

    /**
     * @return array<int, float>
     */
    private function embedding(string $text): array
    {
        $vector = array_fill(0, 64, 0.0);

        foreach ($this->tokens($text) as $token) {
            $index = hexdec(substr(hash('sha256', $token), 0, 2)) % 64;
            $vector[$index] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));
        if ($norm > 0.0) {
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     */
    private function cosine(array $left, array $right): float
    {
        $dot = 0.0;
        foreach ($left as $index => $value) {
            $dot += $value * ($right[$index] ?? 0.0);
        }

        return max(0.0, min(1.0, $dot));
    }

    /**
     * @param  array<int, float>  $vector
     * @return array{indices: array<int, int>, driver: string}
     */
    private function vectorSearch(array $vector, int $limit): array
    {
        $url = (string) config('triproof.vectors.url', '');
        if ($url === '') {
            return ['indices' => [], 'driver' => 'local_dense'];
        }

        try {
            $collection = (string) config('triproof.vectors.collection', 'triproof_contextual');
            $response = Http::timeout((int) config('triproof.vectors.timeout', 10))
                ->throw()
                ->post(rtrim($url, '/').'/collections/'.$collection.'/points/search', [
                    'vector' => array_values($vector),
                    'limit' => $limit,
                    'with_payload' => true,
                ]);

            $indices = [];
            foreach ($response->json('result', []) as $point) {
                if (is_array($point) && isset($point['payload']['chunk_index'])) {
                    $indices[] = (int) $point['payload']['chunk_index'];
                }
            }

            return ['indices' => $indices, 'driver' => 'qdrant'];
        } catch (Throwable) {
            return ['indices' => [], 'driver' => 'local_dense_fallback'];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     */
    private function pushToVectorStore(array $chunks): void
    {
        $url = (string) config('triproof.vectors.url', '');
        if ($url === '') {
            return;
        }

        try {
            $collection = (string) config('triproof.vectors.collection', 'triproof_contextual');
            $points = [];
            foreach ($chunks as $index => $chunk) {
                $points[] = [
                    'id' => $index + 1,
                    'vector' => $this->embedding($chunk['text']),
                    'payload' => [
                        'chunk_index' => $index,
                        'citation' => $chunk['citation'],
                        'text' => $chunk['text'],
                    ],
                ];
            }

            Http::timeout((int) config('triproof.vectors.timeout', 10))
                ->throw()
                ->put(rtrim($url, '/').'/collections/'.$collection.'/points?wait=true', [
                    'points' => $points,
                ]);
        } catch (Throwable) {
            // Local lexical/dense retrieval remains authoritative.
        }
    }

    private function slug(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($value, 'UTF-8')) ?? '', '-') ?: 'section';
    }
}
