<?php

namespace Tests\Feature;

use App\Models\AiAnomalyReport;
use App\Models\SerialRegistry;
use App\Services\Ai\AiExplanationService;
use App\Services\Ai\ContextualRagService;
use App\Services\ProvenanceGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class IntelligenceServicesTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'triproof.graph.uri' => null,
            'triproof.vectors.url' => null,
            'triproof.local_ai.provider' => 'none',
        ]);
    }

    public function test_provenance_graph_uses_sql_recursive_cte_fallback(): void
    {
        [$order, $buyer] = $this->makeOrder(6);
        $units = $this->generateSerials($order, $buyer, 2, 'M01', 'B01')['units'];

        $child = SerialRegistry::query()->where('outer_serial', $units[1]['outer_serial'])->firstOrFail();
        $child->rework_parent_serial = $units[0]['outer_serial'];
        $child->save();

        $sync = app(ProvenanceGraphService::class)->sync();
        $this->assertSame('sql_recursive_cte', $sync['driver']);
        $this->assertFalse($sync['synced']);

        $graph = app(ProvenanceGraphService::class)->recallBlastRadius('B01', 3);
        $this->assertSame('sql_recursive_cte', $graph['driver']);
        $this->assertGreaterThanOrEqual(2, $graph['node_count']);
        $this->assertContains('rework_child', array_column($graph['edges'], 'type'));
    }

    public function test_contextual_rag_chunks_are_contextual_and_retrievable_without_vector_db(): void
    {
        AiAnomalyReport::query()->create([
            'batch_id' => 'RAG7',
            'anomaly_type' => 'clone_cluster',
            'explanation_en' => 'ObsidianGraphMarker77 clone anomaly.',
            'explanation_bn' => 'অবসিডিয়ান ক্লোন সতর্কতা।',
            'affected_units_json' => [],
            'status' => 'open',
        ]);

        $service = app(ContextualRagService::class);
        $chunks = $service->chunks();

        $this->assertNotEmpty($chunks);
        $this->assertTrue(collect($chunks)->contains(
            fn (array $chunk): bool => str_contains($chunk['text'], '[Context:')
        ));

        $results = $service->retrieve('ObsidianGraphMarker77', 3);

        $this->assertNotEmpty($results);
        $this->assertSame('anomaly:'.$results[0]['metadata']['id'], $results[0]['citation']);
        $this->assertStringContainsString('ObsidianGraphMarker77', $results[0]['text']);
        $this->assertGreaterThan(0, $results[0]['score']);
    }

    public function test_explanation_service_falls_back_to_deterministic_bilingual_templates(): void
    {
        $result = app(AiExplanationService::class)->explain([
            'anomaly_type' => 'ghost_production_attempt',
            'batch_id' => 'B01',
        ]);

        $this->assertTrue($result['fallback']);
        $this->assertSame('deterministic', $result['provider']);
        $this->assertStringContainsString('Quota mismatch', $result['explanation_en']);
        $this->assertStringContainsString('কোটা', $result['explanation_bn']);
    }

    public function test_explanation_service_supports_ollama_and_falls_back_on_failure(): void
    {
        config([
            'triproof.local_ai.provider' => 'ollama',
            'triproof.local_ai.model' => 'deepseek-r1:7b',
            'triproof.local_ai.ollama_url' => 'http://ollama.test',
        ]);

        Http::fake([
            'ollama.test/*' => Http::response([
                'response' => json_encode([
                    'explanation_en' => 'Local Ollama explanation.',
                    'explanation_bn' => 'স্থানীয় ব্যাখ্যা।',
                    'action_en' => 'Quarantine.',
                    'action_bn' => 'আলাদা করুন।',
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        $result = app(AiExplanationService::class)->explain(['batch_id' => 'B01']);

        $this->assertFalse($result['fallback']);
        $this->assertSame('ollama', $result['provider']);
        $this->assertSame('deepseek-r1:7b', $result['model']);
        $this->assertSame('Local Ollama explanation.', $result['explanation_en']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/generate'));
    }

    public function test_explanation_service_supports_llama_cpp_openai_compatible_server(): void
    {
        config([
            'triproof.local_ai.provider' => 'llama_cpp',
            'triproof.local_ai.model' => 'qwen2.5:7b',
            'triproof.local_ai.llama_cpp_url' => 'http://llama.test/v1',
        ]);

        Http::fake([
            'llama.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'explanation_en' => 'Local llama.cpp explanation.',
                        'explanation_bn' => 'লোকাল ব্যাখ্যা।',
                        'action_en' => 'Inspect.',
                        'action_bn' => 'পরিদর্শন করুন।',
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ], 200),
        ]);

        $result = app(AiExplanationService::class)->explain(['batch_id' => 'B01']);

        $this->assertFalse($result['fallback']);
        $this->assertSame('llama_cpp', $result['provider']);
        $this->assertSame('Local llama.cpp explanation.', $result['explanation_en']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/chat/completions'));
    }

    public function test_explanation_service_falls_back_when_local_runtime_is_unavailable(): void
    {
        config([
            'triproof.local_ai.provider' => 'ollama',
            'triproof.local_ai.ollama_url' => 'http://ollama.test',
        ]);

        Http::fake(['ollama.test/*' => Http::response('failure', 503)]);

        $result = app(AiExplanationService::class)->explain(['batch_id' => 'B01']);

        $this->assertTrue($result['fallback']);
        $this->assertSame('ollama_fallback', $result['provider']);
        $this->assertStringContainsString('Clone-risk', $result['explanation_en']);
    }
}
