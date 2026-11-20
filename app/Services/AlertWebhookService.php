<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AlertWebhookService
{
    public function notify(string $kind, array $context): bool
    {
        $url = (string) config('triproof.alerts.webhook_url', '');
        if ($url === '') {
            return false;
        }

        $payload = [
            'event' => $kind,
            'occurred_at' => now()->toIso8601String(),
            'context' => $context,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $secret = (string) (config('triproof.alerts.webhook_secret') ?: config('app.key'));
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        try {
            $dispatch = dispatch(function () use ($url, $body, $signature): void {
                Http::timeout(3)
                    ->retry(1)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-TriProof-Signature' => $signature,
                    ])
                    ->withBody($body, 'application/json')
                    ->post($url);
            });

            if (! app()->runningUnitTests()) {
                $dispatch->afterResponse();
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('TriProof alert webhook dispatch failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
