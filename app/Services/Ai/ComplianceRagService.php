<?php

namespace App\Services\Ai;

use App\Models\AiAnomalyReport;
use App\Models\DocSection;
use App\Models\RecallEvent;
use App\Models\User;

class ComplianceRagService
{
    private const MAX_CHUNK_CHARS = 1200;

    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'are', 'was', 'were', 'will', 'into',
        'about', 'what', 'which', 'how', 'does', 'can', 'all', 'any', 'not', 'but', 'has', 'have',
        'had', 'its', 'their', 'our', 'your', 'you', 'of', 'to', 'in', 'on', 'at', 'by', 'is', 'be',
        'একটি', 'এবং', 'জন্য', 'সহ', 'অথবা', 'কিন্তু', 'এই', 'সেই', 'যা', 'যে', 'হয়', 'হয়েছে',
    ];

    public function __construct(
        private readonly AnomalyAnalysisService $analysis,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function chunks(): array
    {
        $chunks = [];

        foreach ([
            'README.md' => base_path('README.md'),
            'ARCHITECTURE.md' => base_path('ARCHITECTURE.md'),
            'docs/API.md' => base_path('docs/API.md'),
        ] as $source => $path) {
            $chunks = array_merge($chunks, $this->markdownChunks((string) $source, $path));
        }

        foreach (DocSection::query()->where('is_visible', true)->orderBy('sort_order')->get() as $section) {
            $title = $section->displayTitle('en');
            $body = $title."\n\n".$section->liveMarkdown();

            if ($section->diagram_mermaid) {
                $body .= "\n\nMermaid diagram:\n".$section->diagram_mermaid;
            }

            $chunks = array_merge($chunks, $this->makeChunks(
                body: $body,
                context: 'DocSection: '.$title,
                citation: 'doc-section:'.$section->slug,
                metadata: [
                    'type' => 'doc_section',
                    'slug' => $section->slug,
                    'section_key' => $section->section_key,
                    'category' => $section->category,
                    'title' => $title,
                ]
            ));
        }

        foreach (AiAnomalyReport::query()->latest()->limit(20)->get() as $report) {
            $body = implode("\n", array_filter([
                'Anomaly type: '.$report->anomaly_type->value,
                'Status: '.$report->status,
                'PO: '.($report->po_id ?? '—'),
                'Batch: '.($report->batch_id ?? '—'),
                'English: '.$report->explanation_en,
                'Bangla: '.$report->explanation_bn,
            ]));

            $chunks = array_merge($chunks, $this->makeChunks(
                body: $body,
                context: 'Anomaly report: '.$report->anomaly_type->value,
                citation: 'anomaly:'.$report->id,
                metadata: [
                    'type' => 'anomaly_report',
                    'id' => $report->id,
                    'anomaly_type' => $report->anomaly_type->value,
                    'status' => $report->status,
                    'created_at' => $report->created_at?->toIso8601String(),
                ]
            ));
        }

        foreach (RecallEvent::query()->latest()->limit(20)->get() as $event) {
            $body = implode("\n", array_filter([
                'Recall reason: '.$event->reason,
                'Factory: '.($event->factory_id ?? '—'),
                'Batch: '.($event->batch_id ?? '—'),
                'Affected count: '.$event->affected_count,
            ]));

            $chunks = array_merge($chunks, $this->makeChunks(
                body: $body,
                context: 'Recall event: '.($event->batch_id ?? $event->factory_id ?? $event->id),
                citation: 'recall:'.$event->id,
                metadata: [
                    'type' => 'recall_event',
                    'id' => $event->id,
                    'factory_id' => $event->factory_id,
                    'batch_id' => $event->batch_id,
                    'created_at' => $event->created_at?->toIso8601String(),
                ]
            ));
        }

        return array_values($chunks);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(string $query, int $limit = 5): array
    {
        $query = trim($query);
        $chunks = $this->chunks();

        if ($query === '' || $chunks === []) {
            return [];
        }

        $queryTokens = $this->tokens($query);
        if ($queryTokens === []) {
            return [];
        }

        $docTokens = array_map(fn (array $chunk) => $this->tokens($chunk['text']), $chunks);
        $documentFrequency = [];

        foreach ($docTokens as $tokens) {
            foreach (array_unique($tokens) as $token) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
            }
        }

        $total = count($chunks);
        $idf = [];
        foreach ($documentFrequency as $token => $frequency) {
            $idf[$token] = log((1 + $total) / (1 + $frequency)) + 1;
        }

        $queryCounts = array_count_values($queryTokens);
        $queryNorm = 0.0;
        foreach ($queryCounts as $token => $count) {
            $weight = $count * ($idf[$token] ?? log(1 + $total) + 1);
            $queryNorm += $weight * $weight;
        }

        $uniqueQueryTokens = array_keys($queryCounts);
        $scored = [];

        foreach ($chunks as $index => $chunk) {
            $counts = array_count_values($docTokens[$index]);
            $dot = 0.0;
            $documentNorm = 0.0;
            $present = 0;

            foreach ($counts as $token => $count) {
                $weight = $count * ($idf[$token] ?? 0.0);
                $documentNorm += $weight * $weight;

                if (isset($queryCounts[$token])) {
                    $dot += $weight * ($queryCounts[$token] * ($idf[$token] ?? 0.0));
                    $present++;
                }
            }

            $cosine = $queryNorm > 0.0 && $documentNorm > 0.0
                ? $dot / (sqrt($queryNorm) * sqrt($documentNorm))
                : 0.0;

            $overlap = $uniqueQueryTokens === [] ? 0.0 : $present / count($uniqueQueryTokens) * 0.15;
            $score = $cosine + $overlap + $this->metadataBoost($chunk);

            $scored[] = array_merge($chunk, ['score' => round($score, 6)]);
        }

        usort($scored, function (array $left, array $right): int {
            $comparison = $right['score'] <=> $left['score'];

            return $comparison !== 0 ? $comparison : strcmp((string) $left['citation'], (string) $right['citation']);
        });

        return array_slice($scored, 0, max(1, min(20, $limit)));
    }

    /**
     * @return array<string, mixed>
     */
    public function answer(string $question, User $actor, int $limit = 5): array
    {
        $chunks = $this->retrieve($question, $limit);
        $grounded = $this->analysis->groundedAnswer($question, $chunks);

        if ($grounded !== null && ($grounded['answer_en'] ?? '') !== '') {
            return [
                'question' => $question,
                'answer_en' => $grounded['answer_en'],
                'answer_bn' => $grounded['answer_bn'] ?? '',
                'citations' => $grounded['citations'] ?? array_column($chunks, 'citation'),
                'chunks' => $chunks,
                'grounded' => true,
                'ai_used' => true,
            ];
        }

        return $this->deterministicAnswer($question, $chunks);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<string, mixed>
     */
    private function deterministicAnswer(string $question, array $chunks): array
    {
        if ($chunks === []) {
            return [
                'question' => $question,
                'answer_en' => 'No grounded documentation or operational records matched this question.',
                'answer_bn' => 'এই প্রশ্নের সাথে মেলে এমন কোনো নথি বা কার্যকরী রেকর্ড পাওয়া যায়নি।',
                'citations' => [],
                'chunks' => [],
                'grounded' => false,
                'ai_used' => false,
            ];
        }

        $excerpts = [];
        foreach ($chunks as $index => $chunk) {
            $excerpt = preg_replace('/\s+/u', ' ', (string) $chunk['text']) ?? '';
            $excerpts[] = '['.($index + 1).'] '.$chunk['citation'].': '.mb_strimwidth($excerpt, 0, 420, '…', 'UTF-8');
        }

        return [
            'question' => $question,
            'answer_en' => 'Grounded TriProof AI records: '.implode(' ', $excerpts),
            'answer_bn' => 'ট্রাইপ্রুফ এআই রেকর্ড ভিত্তিক তথ্য: '.implode(' ', $excerpts),
            'citations' => array_column($chunks, 'citation'),
            'chunks' => $chunks,
            'grounded' => true,
            'ai_used' => false,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $parts,
            fn (string $token) => mb_strlen($token, 'UTF-8') > 1 && ! in_array($token, self::STOP_WORDS, true)
        ));
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    private function metadataBoost(array $chunk): float
    {
        $metadata = is_array($chunk['metadata'] ?? null) ? $chunk['metadata'] : [];
        $type = $metadata['type'] ?? '';

        $boost = match ($type) {
            'doc_section' => 0.12,
            'anomaly_report', 'recall_event' => 0.10,
            'markdown' => 0.06,
            default => 0.0,
        };

        $createdAt = $metadata['created_at'] ?? null;
        if (is_string($createdAt) && $createdAt !== '') {
            try {
                if (now()->diffInDays(now()->parse($createdAt)) <= 30) {
                    $boost += 0.04;
                }
            } catch (\Throwable) {
                // Ignore malformed metadata timestamps.
            }
        }

        return $boost;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function markdownChunks(string $source, string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $content = (string) file_get_contents($path);
        $sections = preg_split('/(?=^#{1,6}\s+)/m', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        foreach ($sections as $section) {
            $lines = preg_split('/\R/u', trim($section), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $heading = (string) array_shift($lines);
            $heading = trim(ltrim($heading, "# \t"));
            $body = trim(implode("\n", $lines));

            if ($body === '') {
                continue;
            }

            $paragraphs = preg_split('/\n\s*\n/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $groups = $this->groupParagraphs($paragraphs);

            foreach ($groups as $group) {
                $chunks = array_merge($chunks, $this->makeChunks(
                    body: $group,
                    context: $source.' > '.($heading !== '' ? $heading : 'Overview'),
                    citation: $source.'#'.$this->slugify($heading !== '' ? $heading : 'overview'),
                    metadata: [
                        'type' => 'markdown',
                        'source' => $source,
                        'section' => $heading !== '' ? $heading : 'Overview',
                    ]
                ));
            }
        }

        return $chunks;
    }

    /**
     * @param  array<int, string>  $paragraphs
     * @return array<int, string>
     */
    private function groupParagraphs(array $paragraphs): array
    {
        $groups = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($paragraph) + 2 > self::MAX_CHUNK_CHARS) {
                $groups[] = $current;
                $current = $paragraph;
            } else {
                $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
            }
        }

        if ($current !== '') {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function makeChunks(string $body, string $context, string $citation, array $metadata): array
    {
        $paragraphs = preg_split('/\n\s*\n/u', trim($body), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $groups = $this->groupParagraphs($paragraphs);

        $chunks = [];
        foreach ($groups as $index => $group) {
            $text = '['.$context."]\n".trim($group);
            $chunks[] = [
                'id' => md5($citation.'|'.$index.'|'.mb_substr($text, 0, 80, 'UTF-8')),
                'source' => $metadata['source'] ?? ($metadata['type'] ?? 'documentation'),
                'section' => $metadata['section'] ?? ($metadata['title'] ?? $context),
                'text' => $text,
                'citation' => $citation.($index > 0 ? '#'.$index : ''),
                'metadata' => $metadata,
            ];
        }

        return $chunks;
    }

    private function slugify(string $value): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($value, 'UTF-8')) ?? '';

        return trim($slug, '-') ?: 'section';
    }
}
