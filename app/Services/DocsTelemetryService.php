<?php

namespace App\Services;

use App\Enums\SerialStatus;
use App\Models\AiAnomalyReport;
use App\Models\Factory;
use App\Models\PurchaseOrder;
use App\Models\RecallEvent;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\SigningKey;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

class DocsTelemetryService
{
    /**
     * @return array<string, mixed>
     */
    public function getTelemetry(): array
    {
        return Cache::remember('docs_live_telemetry', 60, function () {
            return [
                'factories_active' => Factory::where('is_active', true)->count(),
                'users_total' => User::count(),
                'buyers_count' => User::where('role', 'global_buyer')->count(),
                'operators_count' => User::where('role', 'factory_qa')->count(),
                'admins_count' => User::where('role', 'admin')->count(),
                'purchase_orders_count' => PurchaseOrder::count(),
                'authorized_quota_sum' => PurchaseOrder::sum('authorized_quota') ?: 0,
                'signed_serials_count' => SerialRegistry::count(),
                'packed_units_count' => SerialRegistry::where('status', SerialStatus::Packed)->count(),
                'verified_scans_count' => ScanLog::count(),
                'active_recalls_count' => RecallEvent::count(),
                'anomaly_reports_count' => AiAnomalyReport::count(),
                'active_signing_keys_count' => SigningKey::where('status', 'active')->count(),
                'k00_active' => (bool) config('triproof.crypto.public_key')
                    || SigningKey::where('key_version', 'K00')->exists(),
                'subsystems' => [
                    [
                        'name' => 'Ed25519 Signing Engine',
                        'status' => 'Live',
                        'badge' => 'green',
                        'description' => 'Cryptographic serial signing and verification via sodium/sodium_compat.',
                    ],
                    [
                        'name' => 'Quota-Locked Purchase Orders',
                        'status' => 'Active',
                        'badge' => 'green',
                        'description' => 'Atomic SELECT ... FOR UPDATE quota validation preventing double-generation.',
                    ],
                    [
                        'name' => 'Offline 50 KB Blacklist + 240K Bloom Filter',
                        'status' => 'Configured',
                        'badge' => 'blue',
                        'description' => 'Zero-latency edge verification supporting fully disconnected scanning.',
                    ],
                    [
                        'name' => 'Bilingual EN/বাংলা AI Compliance',
                        'status' => 'Live',
                        'badge' => 'green',
                        'description' => 'Deterministic bilingual anomaly detection and automated audit reporting.',
                    ],
                    [
                        'name' => 'Precision Batch Recalls & Rework Lineage',
                        'status' => 'Active',
                        'badge' => 'green',
                        'description' => 'Targeted supply-chain recall triggers with Merkle root verification.',
                    ],
                    [
                        'name' => 'Zero-SSH cPanel Installer',
                        'status' => 'Ready',
                        'badge' => 'purple',
                        'description' => 'Self-contained deployment archives with pre-compiled assets and vendor binaries.',
                    ],
                ],
                'api_endpoints_count' => count(array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with($r->uri(), 'api/v1'))),
                'web_routes_count' => count(array_filter(Route::getRoutes()->getRoutes(), fn ($r) => ! str_starts_with($r->uri(), 'api/v1'))),
                'timestamp' => now()->toIso8601String(),
            ];
        });
    }
}
