<?php

namespace Tests\Feature;

use App\Models\AiAnomalyReport;
use App\Models\RecallEvent;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AnomalyAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class AnomalyAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    public function test_deterministic_bilingual_fallback_is_used_without_an_ai_provider(): void
    {
        [$order, , $factory] = $this->makeOrder(20);
        $this->makeRecall($factory, $order->id, 'DHK04', 'M01', 'B01', 5);

        $result = app(AnomalyAnalysisService::class)->analyze($factory, $order->id);

        $this->assertFalse($result['ai_configured']);
        $this->assertFalse($result['ai_used']);
        $this->assertSame(1, $result['findings']);
        $this->assertNotEmpty($result['reports'][0]['explanation_en']);
        $this->assertNotEmpty($result['reports'][0]['explanation_bn']);
        $this->assertFalse($result['reports'][0]['affected_units']['ai_generated']);
        $this->assertNotEmpty($result['reports'][0]['affected_units']['recommended_action_bn']);
    }

    public function test_non_admin_analysis_does_not_expose_other_factory_null_po_recalls(): void
    {
        [$order, , $factory] = $this->makeOrder(20);
        $this->makeRecall($factory, null, 'DHK04', 'M01', 'B01', 5);
        $this->makeRecall($factory, null, 'OTHER', 'M09', 'B01', 9);

        $result = app(AnomalyAnalysisService::class)->analyze($factory);

        $this->assertSame(1, $result['findings']);
        $this->assertSame('DHK04', $result['reports'][0]['affected_units']['factory_id']);
        $this->assertSame(5, $result['reports'][0]['affected_units']['count']);
    }

    public function test_admin_analysis_sees_null_po_findings_from_all_factories(): void
    {
        [$order, , $factory] = $this->makeOrder(20);
        $admin = User::factory()->admin()->create();
        $this->makeRecall($admin, null, 'DHK04', 'M01', 'B01', 5);
        $this->makeRecall($admin, null, 'OTHER', 'M09', 'B02', 9);

        $result = app(AnomalyAnalysisService::class)->analyze($admin);

        $this->assertSame(2, $result['findings']);
    }

    public function test_non_admin_does_not_include_null_po_rework_gap_reports(): void
    {
        [$order, , $factory] = $this->makeOrder(20);
        AiAnomalyReport::create([
            'po_id' => null,
            'batch_id' => 'X1',
            'anomaly_type' => 'rework_gap',
            'explanation_en' => 'Null PO gap',
            'explanation_bn' => 'নাল পিও গ্যাপ',
            'affected_units_json' => ['factory_id' => 'OTHER', 'serials' => [], 'count' => 1],
            'status' => 'open',
        ]);

        $result = app(AnomalyAnalysisService::class)->analyze($factory);

        $this->assertSame(0, $result['findings']);
    }

    public function test_configured_ai_advice_is_parsed_and_validated(): void
    {
        config([
            'triproof.ai.provider' => 'openai',
            'triproof.ai.api_key' => 'test-key',
            'triproof.ai.base_url' => 'https://api.openai.test/v1',
        ]);

        Http::fake([
            'api.openai.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"explanation_en":"Clear risk.","explanation_bn":"স্পষ্ট ঝুঁকি।","action_en":"Quarantine.","action_bn":"আলাদা করুন।","severity":"high"}',
                    ],
                ]],
            ]),
        ]);

        $advice = app(AiClient::class)->anomalyAdvice(['count' => 3]);

        $this->assertSame('high', $advice['severity']);
        $this->assertSame('আলাদা করুন।', $advice['action_bn']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/completions'));
    }

    private function makeRecall(
        User $actor,
        ?int $poId,
        string $factory,
        string $machine,
        string $batch,
        int $affected,
    ): RecallEvent {
        return RecallEvent::create([
            'po_id' => $poId,
            'factory_id' => $factory,
            'machine_id' => $machine,
            'batch_id' => $batch,
            'affected_count' => $affected,
            'reason' => 'Test recall',
            'triggered_by' => $actor->id,
        ]);
    }
}
