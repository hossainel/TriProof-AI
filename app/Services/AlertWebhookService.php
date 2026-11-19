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

        try {
            dispatch(function () use ($url, $payload): void {
                Http::timeout(3)->retry(1)->post($url, $payload);
            })->afterResponse();

            return true;
        } catch (Throwable $e) {
            Log::warning('TriProof alert webhook dispatch failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
