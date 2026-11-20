<?php

namespace Tests\Feature;

use App\Services\AlertWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookSigningTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_webhook_payload_is_hmac_sha256_signed(): void
    {
        config([
            'triproof.alerts.webhook_url' => 'https://hooks.example.test/triproof',
            'triproof.alerts.webhook_secret' => 'webhook-test-secret',
        ]);

        Http::fake(['hooks.example.test/*' => Http::response([], 200)]);

        $this->assertTrue(app(AlertWebhookService::class)->notify('clone_detected', [
            'batch_id' => 'B01',
            'summary_bn' => 'ক্লোন শনাক্ত',
        ]));

        Http::assertSent(function ($request): bool {
            $headers = $request->header('X-TriProof-Signature');
            if (! isset($headers[0])) {
                return false;
            }

            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'webhook-test-secret');

            return $request->url() === 'https://hooks.example.test/triproof'
                && hash_equals($expected, $headers[0])
                && str_contains($request->body(), '"event":"clone_detected"');
        });
    }

    public function test_webhook_is_skipped_when_no_url_is_configured(): void
    {
        config(['triproof.alerts.webhook_url' => null]);
        Http::fake();

        $this->assertFalse(app(AlertWebhookService::class)->notify('recall_alert', []));
        Http::assertNothingSent();
    }
}
