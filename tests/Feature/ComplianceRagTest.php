<?php

namespace Tests\Feature;

use App\Models\DocSection;
use App\Models\User;
use App\Services\Ai\ComplianceRagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ComplianceRagTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_chunks_are_contextual_and_retrievable(): void
    {
        DocSection::query()->create([
            'slug' => 'rag-quota-proof',
            'category' => 'technical',
            'section_key' => 'rag_quota_proof',
            'title_en' => 'Quota Proof Contract',
            'content_markdown' => 'RAG unique marker: ObsidianQuotaProof77 describes quota-locked signing with SELECT FOR UPDATE.',
        ]);

        $results = app(ComplianceRagService::class)->retrieve('ObsidianQuotaProof77', 3);

        $this->assertNotEmpty($results);
        $this->assertSame('doc-section:rag-quota-proof', $results[0]['citation']);
        $this->assertStringStartsWith('[DocSection: Quota Proof Contract]', $results[0]['text']);
        $this->assertStringContainsString('ObsidianQuotaProof77', $results[0]['text']);
        $this->assertGreaterThan(0, $results[0]['score']);

        $readmeChunks = array_filter(
            app(ComplianceRagService::class)->chunks(),
            fn (array $chunk): bool => ($chunk['metadata']['type'] ?? null) === 'markdown'
        );
        $this->assertNotEmpty($readmeChunks);
    }

    public function test_ask_docs_returns_deterministic_citation_backed_answer(): void
    {
        config(['triproof.ai.provider' => 'none']);

        DocSection::query()->create([
            'slug' => 'rag-bilingual-proof',
            'category' => 'technical',
            'section_key' => 'rag_bilingual_proof',
            'title_en' => 'Bilingual Compliance Proof',
            'content_markdown' => 'RAG answer marker: CobaltBilingualProof91 proves deterministic English and Bangla compliance output.',
        ]);

        $user = User::factory()->buyer()->create([
            'email' => 'rag-buyer@example.test',
            'password_hash' => Hash::make('ApiPass!12345'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ApiPass!12345',
        ])->assertOk()->json('data.token');

        $response = $this->withToken($token)->postJson('/api/v1/ai/ask-docs', [
            'question' => 'What does CobaltBilingualProof91 prove?',
            'limit' => 3,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ai_used', false)
            ->assertJsonPath('data.grounded', true);

        $this->assertContains('doc-section:rag-bilingual-proof', $response->json('data.citations'));
        $this->assertStringContainsString('CobaltBilingualProof91', $response->json('data.answer_en'));
        $this->assertStringContainsString('doc-section:rag-bilingual-proof', $response->json('data.answer_en'));
    }
}
