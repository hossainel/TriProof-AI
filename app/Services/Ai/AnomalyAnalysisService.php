<?php

namespace App\Services\Ai;

use App\Enums\AnomalyType;
use App\Enums\CloudVerdict;
use App\Exceptions\BusinessRuleException;
use App\Models\AiAnomalyReport;
use App\Models\PurchaseOrder;
use App\Models\RecallEvent;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\Crypto\SerialCryptoService;
use App\Support\DeviceToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnomalyAnalysisService
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly SerialCryptoService $crypto,
    ) {}

    /**
     * Answer a documentation/compliance question using grounded RAG chunks.
     * Returns null when no AI provider is configured or the provider call fails,
     * allowing callers to use the deterministic citation-backed fallback.
     *
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<string, mixed>|null
     */
    public function groundedAnswer(string $question, array $chunks): ?array
    {
        if (! $this->ai->isConfigured() || $chunks === []) {
            return null;
        }

        $context = collect($chunks)
            ->map(fn (array $chunk, int $index): string => '['.($index + 1).'] '.($chunk['citation'] ?? 'source')."\n".($chunk['text'] ?? ''))
            ->implode("\n\n");

        $system = 'You are the TriProof AI compliance documentation assistant. '
            .'Answer strictly from the grounded context provided. Never invent facts, keys, or endpoints. '
            .'Return JSON with answer_en, answer_bn, and citations (array of source ids).';

        $user = "Question: {$question}\n\nGrounded context:\n{$context}";

        try {
            $raw = $this->ai->chat($system, $user);
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded) && trim((string) ($decoded['answer_en'] ?? '')) !== '') {
            $citations = $decoded['citations'] ?? null;

            return [
                'answer_en' => (string) $decoded['answer_en'],
                'answer_bn' => (string) ($decoded['answer_bn'] ?? ''),
                'citations' => is_array($citations) ? array_values($citations) : array_column($chunks, 'citation'),
            ];
        }

        if (trim($raw) === '') {
            return null;
        }

        return [
            'answer_en' => $raw,
            'answer_bn' => '',
            'citations' => array_column($chunks, 'citation'),
        ];
    }

    public function recordClone(SerialRegistry $serial, string $claimedDeviceToken, string $currentDeviceToken, ?float $lat, ?float $lng): AiAnomalyReport
    {
        $entry = [
            'outer_serial' => $serial->outer_serial,
            'devices' => array_values(array_unique([
                DeviceToken::mask($claimedDeviceToken),
                DeviceToken::mask($currentDeviceToken),
            ])),
            'gps' => $lat !== null && $lng !== null ? ['lat' => $lat, 'lng' => $lng] : null,
            'detected_at' => now()->toIso8601String(),
        ];

        return $this->storeFinding(
            $serial->po_id,
            $serial->batch_id,
            AnomalyType::CloneCluster,
            [
                'factory_id' => $serial->factory_id,
                'machine_id' => $serial->machine_id,
                'serials' => [$entry],
                'count' => 1,
                'locations' => $entry['gps'] ? 1 : 0,
            ],
            append: true
        );
    }

    public function recordReworkGap(?int $poId, ?string $batchId, string $reason, array $affected): AiAnomalyReport
    {
        return $this->storeFinding(
            $poId,
            $batchId,
            AnomalyType::ReworkGap,
            [
                'serials' => $affected,
                'count' => max(1, count($affected)),
                'reason' => $reason,
            ],
            append: true
        );
    }

    public function analyze(User $actor, ?int $poId = null, ?string $batchFilter = null): array
    {
        $orders = PurchaseOrder::query()
            ->visibleTo($actor)
            ->when($poId, fn ($q) => $q->whereKey($poId))
            ->when($batchFilter, fn ($q) => $q->whereHas('serials', fn ($s) => $s->where('batch_id', $batchFilter)))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        if ($poId && $orders->isEmpty()) {
            throw new BusinessRuleException('forbidden', 'You cannot analyse this purchase order.', 403);
        }

        $poIds = $orders->pluck('id')->all();
        if ($poIds === []) {
            return [
                'reports' => [],
                'ai_configured' => $this->ai->isConfigured(),
                'ai_used' => false,
                'findings' => 0,
            ];
        }

        $scope = $this->scopeMap($poIds);
        $accessibleFactories = $orders->pluck('factory_id')->unique()->values()->all();
        $findings = [];

        $findings = array_merge($findings, $this->detectCloneClusters($poIds, $batchFilter));
        $findings = array_merge($findings, $this->detectGhostProduction($orders, $scope, $batchFilter, $actor));
        $findings = array_merge($findings, $this->detectReworkGaps($poIds, $batchFilter, $actor));
        $findings = array_merge($findings, $this->detectDefectClusters($poIds, $batchFilter, $actor, $accessibleFactories));

        $findings = $this->dedupeFindings($findings);
        $reports = [];
        $aiBalance = 6;

        foreach ($findings as $finding) {
            $advice = null;

            if ($aiBalance > 0 && $this->ai->isConfigured()) {
                try {
                    $advice = $this->ai->anomalyAdvice([
                        'anomaly_type' => $finding['type']->value,
                        'factory_id' => $finding['affected']['factory_id'] ?? null,
                        'machine_id' => $finding['affected']['machine_id'] ?? null,
                        'batch_id' => $finding['batch_id'],
                        'count' => $finding['affected']['count'] ?? 0,
                        'sample_serials' => array_slice(array_column($finding['affected']['serials'] ?? [], 'outer_serial'), 0, 12),
                        'location_clusters' => $finding['affected']['location_clusters'] ?? null,
                        'reason' => $finding['affected']['reason'] ?? null,
                    ]);
                    $aiBalance--;
                } catch (Throwable $e) {
                    Log::warning('AI anomaly enrichment failed; using deterministic bilingual guidance.', [
                        'type' => $finding['type']->value,
                    ]);
                }
            }

            $reports[] = $this->storeFinding(
                $finding['po_id'],
                $finding['batch_id'],
                $finding['type'],
                $finding['affected'],
                append: $finding['type'] !== AnomalyType::DefectCluster,
                advice: $advice,
                replace: $finding['type'] === AnomalyType::CloneCluster || $finding['type'] === AnomalyType::GhostProductionAttempt,
            );
        }

        return [
            'reports' => array_map(fn (AiAnomalyReport $report) => $this->toPayload($report), $reports),
            'ai_configured' => $this->ai->isConfigured(),
            'ai_used' => $aiBalance < 6,
            'findings' => count($findings),
        ];
    }

    private function detectCloneClusters(array $poIds, ?string $batchFilter): array
    {
        $rows = DB::table('scan_logs as s')
            ->join('serial_registry as r', 'r.outer_serial', '=', 's.outer_serial')
            ->where('s.cloud_cross_check_verdict', CloudVerdict::CloneDetected->value)
            ->whereIn('r.po_id', $poIds)
            ->when($batchFilter, fn ($q) => $q->where('r.batch_id', $batchFilter))
            ->where('s.scanned_at', '>=', now()->subDays(180))
            ->select(
                's.outer_serial',
                's.device_token',
                's.gps_lat',
                's.gps_lng',
                's.scanned_at',
                'r.po_id',
                'r.batch_id',
                'r.factory_id',
                'r.machine_id'
            )
            ->orderByDesc('s.scanned_at')
            ->limit(5000)
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $batchKey = $row->po_id.'|'.$row->batch_id;
            $cell = $row->gps_lat !== null && $row->gps_lng !== null
                ? number_format((float) $row->gps_lat, 2, '.', '').','.number_format((float) $row->gps_lng, 2, '.', '')
                : 'unknown';

            $groups[$batchKey] ??= [
                'po_id' => (int) $row->po_id,
                'batch_id' => $row->batch_id,
                'factory_id' => $row->factory_id,
                'machine_id' => $row->machine_id,
                'serials' => [],
                'cells' => [],
                'devices' => [],
                'count' => 0,
            ];

            $serial = $row->outer_serial;

            if (! isset($groups[$batchKey]['serials'][$serial])) {
                $groups[$batchKey]['serials'][$serial] = [
                    'outer_serial' => $serial,
                    'gps' => $row->gps_lat !== null ? ['lat' => (float) $row->gps_lat, 'lng' => (float) $row->gps_lng] : null,
                    'detected_at' => (string) $row->scanned_at,
                ];
                $groups[$batchKey]['count']++;
            }

            $groups[$batchKey]['cells'][$cell] = true;
            $groups[$batchKey]['devices'][DeviceToken::mask($row->device_token)] = true;
        }

        $findings = [];
        foreach ($groups as $group) {
            $findings[] = [
                'po_id' => $group['po_id'],
                'batch_id' => $group['batch_id'],
                'type' => AnomalyType::CloneCluster,
                'affected' => [
                    'factory_id' => $group['factory_id'],
                    'machine_id' => $group['machine_id'],
                    'serials' => array_slice(array_values($group['serials']), 0, 200),
                    'count' => $group['count'],
                    'location_clusters' => count($group['cells']),
                    'devices' => array_keys($group['devices']),
                ],
            ];
        }

        return $findings;
    }

    private function detectGhostProduction(Collection $orders, Collection $scope, ?string $batchFilter, User $actor): array
    {
        $scans = ScanLog::query()
            ->where('cloud_cross_check_verdict', CloudVerdict::ForgedSignature->value)
            ->where('scanned_at', '>=', now()->subDays(180))
            ->orderByDesc('scanned_at')
            ->limit(5000)
            ->get();

        $groups = [];
        $accessibleFactories = $orders->pluck('factory_id')->unique()->all();

        foreach ($scans as $scan) {
            $parsed = $this->crypto->parseOuterSerial($scan->outer_serial);
            if (! $parsed) {
                continue;
            }

            if (! $actor->isAdmin() && ! in_array($parsed['factory_id'], $accessibleFactories, true)) {
                continue;
            }

            if ($batchFilter && $parsed['batch_id'] !== $batchFilter) {
                continue;
            }

            $key = "{$parsed['factory_id']}|{$parsed['machine_id']}|{$parsed['batch_id']}";
            $groups[$key] ??= [
                'factory_id' => $parsed['factory_id'],
                'machine_id' => $parsed['machine_id'],
                'batch_id' => $parsed['batch_id'],
                'serials' => [],
                'devices' => [],
                'count' => 0,
            ];

            $groups[$key]['count']++;
            $groups[$key]['devices'][DeviceToken::mask($scan->device_token)] = true;
            $groups[$key]['serials'][$scan->outer_serial] = [
                'outer_serial' => $scan->outer_serial,
                'detected_at' => $scan->scanned_at?->toIso8601String(),
                'gps' => $scan->gps_lat !== null ? ['lat' => $scan->gps_lat, 'lng' => $scan->gps_lng] : null,
            ];
        }

        $findings = [];
        foreach ($groups as $key => $group) {
            if ($group['count'] < 2) {
                continue;
            }

            $mappedPo = $scope->get($key) ?? $scope->first(fn ($value, $scopeKey) => str_starts_with($scopeKey, $key.'|'));
            $poId = $mappedPo['po_id'] ?? null;

            if ($poId === null && ! $actor->isAdmin()) {
                continue;
            }

            $findings[] = [
                'po_id' => $poId,
                'batch_id' => $group['batch_id'],
                'type' => AnomalyType::GhostProductionAttempt,
                'affected' => [
                    'factory_id' => $group['factory_id'],
                    'machine_id' => $group['machine_id'],
                    'serials' => array_slice(array_values($group['serials']), 0, 200),
                    'count' => $group['count'],
                    'devices' => array_keys($group['devices']),
                ],
            ];
        }

        return $findings;
    }

    private function detectReworkGaps(array $poIds, ?string $batchFilter, User $actor): array
    {
        $orphans = SerialRegistry::query()
            ->whereIn('po_id', $poIds)
            ->whereNotNull('rework_parent_serial')
            ->when($batchFilter, fn ($q) => $q->where('batch_id', $batchFilter))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('serial_registry as parent')
                    ->whereColumn('parent.outer_serial', 'serial_registry.rework_parent_serial');
            })
            ->limit(1000)
            ->get();

        $groups = [];
        foreach ($orphans as $serial) {
            $key = implode('|', [$serial->po_id, $serial->factory_id, $serial->machine_id, $serial->batch_id]);
            $groups[$key] ??= [
                'po_id' => $serial->po_id,
                'batch_id' => $serial->batch_id,
                'factory_id' => $serial->factory_id,
                'machine_id' => $serial->machine_id,
                'serials' => [],
                'count' => 0,
            ];

            $groups[$key]['serials'][] = [
                'outer_serial' => $serial->outer_serial,
                'reason' => 'Parent serial not found in the registry.',
            ];
            $groups[$key]['count']++;
        }

        $existing = AiAnomalyReport::query()
            ->where('anomaly_type', AnomalyType::ReworkGap->value)
            ->where('status', 'open')
            ->where(function ($q) use ($poIds, $actor) {
                $q->whereIn('po_id', $poIds);
                if ($actor->isAdmin()) {
                    $q->orWhereNull('po_id');
                }
            })
            ->when($batchFilter, fn ($q) => $q->where('batch_id', $batchFilter))
            ->limit(100)
            ->get();

        foreach ($existing as $report) {
            $affected = $report->affected_units_json ?? [];
            $key = implode('|', [
                $report->po_id ?? 'null',
                $affected['factory_id'] ?? '',
                $affected['machine_id'] ?? '',
                $report->batch_id,
            ]);
            if (isset($groups[$key])) {
                continue;
            }

            $groups[$key] = [
                'po_id' => $report->po_id,
                'batch_id' => $report->batch_id,
                'factory_id' => $affected['factory_id'] ?? null,
                'machine_id' => $affected['machine_id'] ?? null,
                'serials' => $affected['serials'] ?? [],
                'count' => $affected['count'] ?? count($affected['serials'] ?? []),
                'reason' => $affected['reason'] ?? 'Rework lineage gap recorded during reconciliation.',
            ];
        }

        $findings = [];
        foreach ($groups as $group) {
            $findings[] = [
                'po_id' => $group['po_id'],
                'batch_id' => $group['batch_id'],
                'type' => AnomalyType::ReworkGap,
                'affected' => [
                    'factory_id' => $group['factory_id'],
                    'machine_id' => $group['machine_id'],
                    'serials' => array_slice($group['serials'], 0, 200),
                    'count' => $group['count'],
                    'reason' => $group['reason'] ?? null,
                ],
            ];
        }

        return $findings;
    }

    private function detectDefectClusters(array $poIds, ?string $batchFilter, User $actor, array $accessibleFactories): array
    {
        $recalls = RecallEvent::query()
            ->where(function ($q) use ($poIds, $actor, $accessibleFactories) {
                $q->whereIn('po_id', $poIds);

                if ($actor->isAdmin()) {
                    $q->orWhere(function ($sub) {
                        $sub->whereNull('po_id')->where('created_at', '>=', now()->subDays(180));
                    });
                } elseif ($accessibleFactories !== []) {
                    $q->orWhere(function ($sub) use ($accessibleFactories) {
                        $sub->whereNull('po_id')
                            ->whereIn('factory_id', $accessibleFactories)
                            ->where('created_at', '>=', now()->subDays(180));
                    });
                }
            })
            ->where('created_at', '>=', now()->subDays(365))
            ->when($batchFilter, fn ($q) => $q->where('batch_id', $batchFilter))
            ->get();

        $groups = [];
        foreach ($recalls as $recall) {
            $key = implode('|', [$recall->po_id ?? 'null', $recall->factory_id, $recall->machine_id, $recall->batch_id]);
            $groups[$key] ??= [
                'po_id' => $recall->po_id,
                'batch_id' => $recall->batch_id,
                'factory_id' => $recall->factory_id,
                'machine_id' => $recall->machine_id,
                'affected' => 0,
                'serials' => [],
                'recalls' => 0,
            ];
            $groups[$key]['affected'] += $recall->affected_count;
            $groups[$key]['recalls']++;
            $groups[$key]['serials'][] = [
                'outer_serial' => null,
                'recall_event_id' => $recall->id,
                'reason' => $recall->reason,
                'affected_count' => $recall->affected_count,
            ];
        }

        $findings = [];
        foreach ($groups as $group) {
            if ($group['affected'] < 5) {
                continue;
            }

            $findings[] = [
                'po_id' => $group['po_id'],
                'batch_id' => $group['batch_id'],
                'type' => AnomalyType::DefectCluster,
                'affected' => [
                    'factory_id' => $group['factory_id'],
                    'machine_id' => $group['machine_id'],
                    'serials' => $group['serials'],
                    'count' => $group['affected'],
                    'recall_events' => $group['recalls'],
                    'reason' => 'Recall density indicates a batch-level defect.',
                ],
            ];
        }

        return $findings;
    }

    private function dedupeFindings(array $findings): array
    {
        $deduped = [];

        foreach ($findings as $finding) {
            $key = implode('|', [
                $finding['po_id'] ?? 'null',
                $finding['affected']['factory_id'] ?? '',
                $finding['affected']['machine_id'] ?? '',
                $finding['batch_id'],
                $finding['type']->value,
            ]);
            if (! isset($deduped[$key])) {
                $deduped[$key] = $finding;
            }
        }

        return array_slice(array_values($deduped), 0, 40);
    }

    private function storeFinding(
        ?int $poId,
        ?string $batchId,
        AnomalyType $type,
        array $affected,
        bool $append = false,
        ?array $advice = null,
        bool $replace = false,
    ): AiAnomalyReport {
        $existing = AiAnomalyReport::query()
            ->where('anomaly_type', $type->value)
            ->where('status', 'open')
            ->where('batch_id', $batchId)
            ->when($poId === null, fn ($q) => $q->whereNull('po_id'), fn ($q) => $q->where('po_id', $poId))
            ->latest('id')
            ->first();

        if ($existing && $append && ! $replace) {
            $current = $existing->affected_units_json ?? [];
            $merged = $this->mergeAffected($current, $affected);
            $affected = $merged;
        }

        [$explanationEn, $explanationBn, $actionEn, $actionBn] = $this->template(
            $type,
            $batchId,
            $affected
        );

        if ($advice) {
            $explanationEn = trim($advice['explanation_en']);
            $explanationBn = trim($advice['explanation_bn']);
            $actionEn = trim($advice['action_en']);
            $actionBn = trim($advice['action_bn']);
            $affected['ai_generated'] = true;
            $affected['ai_severity'] = $advice['severity'] ?? null;
        } else {
            $affected['ai_generated'] = false;
        }

        $affected['recommended_action_en'] = $actionEn;
        $affected['recommended_action_bn'] = $actionBn;

        if ($existing) {
            $existing->fill([
                'po_id' => $poId,
                'batch_id' => $batchId,
                'explanation_en' => $explanationEn,
                'explanation_bn' => $explanationBn,
                'affected_units_json' => $affected,
                'status' => 'open',
            ])->save();

            return $existing->fresh();
        }

        return AiAnomalyReport::create([
            'po_id' => $poId,
            'batch_id' => $batchId,
            'anomaly_type' => $type,
            'explanation_en' => $explanationEn,
            'explanation_bn' => $explanationBn,
            'affected_units_json' => $affected,
            'status' => 'open',
        ]);
    }

    private function mergeAffected(array $current, array $incoming): array
    {
        $serials = [];
        foreach (array_merge($current['serials'] ?? [], $incoming['serials'] ?? []) as $entry) {
            $key = $entry['outer_serial'] ?? ('event-'.($entry['recall_event_id'] ?? uniqid()));
            if (! isset($serials[$key])) {
                $serials[$key] = $entry;
            }
        }

        $merged = array_merge($current, $incoming);
        $merged['serials'] = array_slice(array_values($serials), -200);
        $merged['count'] = max(
            (int) ($incoming['count'] ?? 0),
            (int) ($current['count'] ?? 0),
            count($merged['serials'])
        );

        return $merged;
    }

    private function template(AnomalyType $type, ?string $batchId, array $affected): array
    {
        $batch = $batchId ?: 'unknown batch';
        $count = (int) ($affected['count'] ?? count($affected['serials'] ?? []));
        $factory = $affected['factory_id'] ?? 'factory';
        $machine = $affected['machine_id'] ?? 'machine';

        return match ($type) {
            AnomalyType::CloneCluster => [
                "{$count} clone-flagged scan(s) were detected in {$factory}-{$machine}-{$batch}. This pattern indicates serial copying or outer-box swapping. Immediate action: quarantine the affected units, hold the batch at the packing line, compare every physical label with the printed manifest, and notify the buyer's compliance desk.",
                "ব্যাচ {$batch}-এ {$count}টি ক্লোন সতর্কতা শনাক্ত হয়েছে। এটি সিরিয়াল কপি বা আউটার বক্স পরিবর্তনের লক্ষণ। করণীয়: আক্রান্ত ইউনিটগুলো আলাদা করুন, প্যাকিং লাইনে ব্যাচটি আটকে রাখুন, প্রতিটি লেবেল প্রিন্টেড ম্যানিফেস্টের সাথে মিলিয়ে দেখুন এবং ক্রেতার কমপ্লায়েন্স টিমকে জানান।",
                'Quarantine flagged units, stop packing this batch, and audit the label printer and outer boxes against the PO manifest.',
                'ফ্ল্যাগ করা ইউনিট আলাদা করুন, এই ব্যাচের প্যাকিং বন্ধ করুন এবং পিও ম্যানিফেস্টের বিপরীতে লেবেল প্রিন্টার ও আউটার বক্স যাচাই করুন।',
            ],
            AnomalyType::GhostProductionAttempt => [
                "{$count} invalid-signature scan attempt(s) referenced {$factory}-{$machine}-{$batch}. Unauthorized labels may have been produced outside the buyer-issued quota. Immediate action: stop the line, secure label printers and signing credentials, audit label stock, and report the batch to the buyer.",
                "ব্যাচ {$batch}-এ {$count}টি অবৈধ সিগনেচার শনাক্ত হয়েছে। ক্রেতার অনুমোদিত কোটার বাইরে লেবেল তৈরি হয়ে থাকতে পারে। করণীয়: লাইন বন্ধ করুন, লেবেল প্রিন্টার ও সাইনিং তথ্য নিয়ন্ত্রণে নিন, লেবেল স্টক যাচাই করুন এবং ব্যাচটি ক্রেতাকে জানান।",
                'Freeze the line, secure printers/credentials, reconcile physical label stock to the PO quota, and escalate to compliance.',
                'লাইন বন্ধ করুন, প্রিন্টার ও সাইনিং তথ্য সুরক্ষিত করুন, পিও কোটার সাথে লেবেল স্টক মিলিয়ে নিন এবং কমপ্লায়েন্স টিমকে জানান।',
            ],
            AnomalyType::ReworkGap => [
                "{$count} rework lineage gap(s) were found in {$factory}-{$machine}-{$batch}. A replacement or reworked unit cannot be traced to its original parent serial. Do not pack or ship these units until the parent-child link is verified from the physical rework log.",
                "ব্যাচ {$batch}-এ {$count}টি রিওয়ার্ক লাইনেজ গ্যাপ পাওয়া গেছে। রিপ্লেসমেন্ট বা রিওয়ার্ক করা ইউনিটের সাথে মূল সিরিয়ালের সম্পর্ক যাচাই করা যাচ্ছে না। শারীরিক রিওয়ার্ক লগ থেকে সম্পর্ক নিশ্চিত না হওয়া পর্যন্ত ইউনিটগুলো প্যাক বা শিপ করবেন না।",
                'Reconcile parent and child serials from the paper/machine rework log, then resubmit the reconciliation file before packing.',
                'কাগজ বা মেশিনের রিওয়ার্ক লগ থেকে মূল ও নতুন সিরিয়ালের সম্পর্ক মিলিয়ে নিন, তারপর প্যাকিংয়ের আগে পুনরায় রিকনসাইল ফাইল জমা দিন।',
            ],
            AnomalyType::DefectCluster => [
                "Batch {$factory}-{$machine}-{$batch} has {$count} recalled unit(s). This concentration points to a production or labelling defect rather than an isolated failure. Quarantine remaining stock, open a CAPA with quality, and verify the affected production window.",
                "ব্যাচ {$factory}-{$machine}-{$batch}-এ {$count}টি ইউনিট রিকল হয়েছে। এই ঘনত্ব বিচ্ছিন্ন সমস্যা নয়, বরং উৎপাদন বা লেবেলিং ত্রুটির ইঙ্গিত দেয়। অবশিষ্ট স্টক আলাদা করুন, কোয়ালিটির সাথে CAPA খুলুন এবং আক্রান্ত উৎপাদন সময় যাচাই করুন।",
                'Quarantine all remaining units, open a CAPA, and inspect the production window and label process for the affected batch.',
                'অবশিষ্ট সব ইউনিট আলাদা করুন, CAPA খুলুন এবং আক্রান্ত ব্যাচের উৎপাদন সময় ও লেবেলিং প্রক্রিয়া পরীক্ষা করুন।',
            ],
        };
    }

    private function scopeMap(array $poIds): Collection
    {
        return SerialRegistry::query()
            ->whereIn('po_id', $poIds)
            ->select('po_id', 'factory_id', 'machine_id', 'batch_id')
            ->distinct()
            ->get()
            ->mapWithKeys(fn ($row) => [
                "{$row->factory_id}|{$row->machine_id}|{$row->batch_id}|{$row->po_id}" => ['po_id' => $row->po_id],
            ]);
    }

    public function toPayload(AiAnomalyReport $report): array
    {
        return [
            'id' => $report->id,
            'po_id' => $report->po_id,
            'batch_id' => $report->batch_id,
            'anomaly_type' => $report->anomaly_type->value,
            'explanation_en' => $report->explanation_en,
            'explanation_bn' => $report->explanation_bn,
            'affected_units' => $report->affected_units_json,
            'status' => $report->status,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }
}
