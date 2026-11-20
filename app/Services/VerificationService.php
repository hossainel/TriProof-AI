<?php

namespace App\Services;

use App\Enums\CloudVerdict;
use App\Enums\ScanMode;
use App\Enums\SerialStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DeviceAttestation;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\Ai\AnomalyAnalysisService;
use App\Services\Crypto\SerialCryptoService;
use App\Support\DeviceToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class VerificationService
{
    public function __construct(
        private readonly SerialCryptoService $crypto,
        private readonly NotificationService $notifications,
        private readonly AnomalyAnalysisService $anomalies,
        private readonly OwnershipTransferService $transfers,
        private readonly AlertWebhookService $alerts,
    ) {}

    public function crossCheck(array $logs, User $actor): array
    {
        foreach ($logs as $index => $log) {
            if (! is_array($log)) {
                throw new BusinessRuleException('invalid_scan_log', "Scan log at index {$index} is invalid.", 422);
            }
        }

        $prepared = [];
        foreach (array_values($logs) as $index => $log) {
            $mode = ScanMode::tryFrom((string) ($log['scan_mode'] ?? ScanMode::OfflineSynced->value))
                ?? ScanMode::OfflineSynced;
            $scannedAt = $this->scanTime($log['scanned_at'] ?? null, $mode);

            $prepared[] = [
                'index' => $index,
                'log' => $log,
                'mode' => $mode,
                'scanned_at' => $scannedAt,
            ];
        }

        usort($prepared, function (array $a, array $b) {
            return [$a['scanned_at']->getTimestamp(), $a['index']] <=> [$b['scanned_at']->getTimestamp(), $b['index']];
        });

        $results = [];
        $feedback = [];
        $summary = [];

        foreach ($prepared as $item) {
            try {
                $result = $this->processOne($item['log'], $actor, $item['mode'], $item['scanned_at']);
            } catch (QueryException) {
                $existing = ScanLog::query()->where('sync_uuid', (string) ($item['log']['sync_uuid'] ?? ''))->first();
                if (! $existing) {
                    throw new BusinessRuleException('verification_failed', 'The scan could not be recorded. Please retry.', 409);
                }
                $result = $this->duplicateResult($existing);
            }

            $results[] = $result;
            foreach ($result['_notifications'] as $notification) {
                if (hash_equals((string) $notification->device_token, (string) $item['log']['device_token'])) {
                    $feedback[$notification->id] = NotificationService::toPayload($notification);
                }
            }
            unset($result['_notifications']);

            $verdict = $result['verdict'];
            $summary[$verdict] = ($summary[$verdict] ?? 0) + 1;
        }

        $cleanResults = array_map(function (array $result) {
            unset($result['_notifications']);

            return $result;
        }, $results);

        return [
            'results' => $cleanResults,
            'feedback_notifications' => array_values($feedback),
            'summary' => $summary,
        ];
    }

    /**
     * Read-only verification used by the web verifier. Never claims ownership.
     */
    public function inspect(string $outerSerial, string $innerSerial): array
    {
        $outerSerial = $this->crypto->normalizePayload(trim($outerSerial));
        $innerSerial = $this->normalizeInnerSerial($innerSerial);

        $signatureValid = $this->crypto->verify($outerSerial, $innerSerial);
        $registry = SerialRegistry::query()->where('outer_serial', $outerSerial)->first();

        $registered = $registry !== null;
        $hashMatches = $registered
            && hash_equals($registry->inner_serial, $innerSerial)
            && hash_equals($registry->unit_hash, $this->crypto->unitHash($outerSerial, $innerSerial));

        $authentic = $signatureValid && $hashMatches;
        $recalled = $registered && $registry->status === SerialStatus::Recalled;
        $expired = $registered && now()->toDateString() > $registry->exp_date->toDateString();
        $cloned = $registered && $registry->status === SerialStatus::ClonedFlagged;

        if (! $authentic) {
            $verdict = CloudVerdict::ForgedSignature;
        } elseif ($recalled) {
            $verdict = CloudVerdict::RecalledBatch;
        } elseif ($expired) {
            $verdict = CloudVerdict::Expired;
        } elseif ($cloned) {
            $verdict = CloudVerdict::CloneDetected;
        } else {
            $verdict = CloudVerdict::VerifiedClean;
        }

        [$messageEn, $messageBn] = $this->inspectionMessage($verdict, $registry);

        return [
            'outer_serial' => $outerSerial,
            'signature_valid' => $signatureValid,
            'registered' => $registered,
            'hash_matches' => $hashMatches,
            'authentic' => $authentic,
            'verdict' => $verdict->value,
            'status' => $registry?->status->value,
            'recalled' => $recalled,
            'expired' => $expired,
            'claimed' => $registry?->claimed_device_token !== null,
            'claimed_device' => $registry?->claimed_device_token ? DeviceToken::mask($registry->claimed_device_token) : null,
            'factory_id' => $registry?->factory_id,
            'machine_id' => $registry?->machine_id,
            'batch_id' => $registry?->batch_id,
            'unit_number' => $registry?->unit_number,
            'prod_date' => $registry?->prod_date?->toDateString(),
            'exp_date' => $registry?->exp_date?->toDateString(),
            'message_en' => $messageEn,
            'message_bn' => $messageBn,
        ];
    }

    private function normalizeInnerSerial(string $innerSerial): string
    {
        $innerSerial = preg_replace('/\s+/', '', trim($innerSerial)) ?? '';

        return str_starts_with($innerSerial, 'TP1:') ? substr($innerSerial, 4) : $innerSerial;
    }

    private function processOne(array $log, User $actor, ScanMode $mode, CarbonImmutable $scannedAt): array
    {
        $syncUuid = trim((string) ($log['sync_uuid'] ?? ''));
        if ($syncUuid === '') {
            throw new BusinessRuleException('sync_uuid_required', 'Every scan log needs a sync_uuid idempotency key.');
        }

        $existing = ScanLog::query()->where('sync_uuid', $syncUuid)->first();
        if ($existing) {
            return $this->duplicateResult($existing);
        }

        $outer = $this->crypto->normalizePayload(trim((string) ($log['outer_serial'] ?? '')));
        $inner = $this->normalizeInnerSerial((string) ($log['inner_serial'] ?? ''));
        $deviceToken = trim((string) ($log['device_token'] ?? ''));

        if ($outer === '' || $inner === '' || $deviceToken === '') {
            throw new BusinessRuleException('scan_fields_required', 'outer_serial, inner_serial and device_token are required.');
        }

        $attestation = DeviceAttestation::query()->where('device_token', $deviceToken)->first();
        $scanSeq = isset($log['scan_seq']) ? (int) $log['scan_seq'] : null;

        if ($attestation && $scanSeq !== null) {
            if ($scanSeq <= (int) $attestation->last_scan_seq) {
                throw new BusinessRuleException('scan_seq_replay', 'The device scan sequence is stale; replay rejected.', 409);
            }

            if (! empty($log['device_signature'])) {
                $signature = base64_decode((string) $log['device_signature'], true);
                $publicKey = base64_decode((string) $attestation->device_public_key_b64, true);
                $message = "{$syncUuid}|{$scanSeq}|{$outer}";
                if ($signature === false || $publicKey === false
                    || ! sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                    throw new BusinessRuleException('device_signature_invalid', 'The device signature did not verify.', 422);
                }
            }
        }

        return DB::transaction(function () use ($syncUuid, $outer, $inner, $deviceToken, $actor, $mode, $scannedAt, $log, $attestation, $scanSeq) {
            $duplicate = ScanLog::query()->where('sync_uuid', $syncUuid)->first();
            if ($duplicate) {
                return $this->duplicateResult($duplicate);
            }

            $lat = isset($log['gps_lat']) && $log['gps_lat'] !== '' ? (float) $log['gps_lat'] : null;
            $lng = isset($log['gps_lng']) && $log['gps_lng'] !== '' ? (float) $log['gps_lng'] : null;

            /** @var SerialRegistry|null $registry */
            $registry = SerialRegistry::query()->where('outer_serial', $outer)->lockForUpdate()->first();

            $signatureValid = $this->crypto->verify($outer, $inner);
            $registered = $registry !== null;
            $hashMatches = $registered
                && hash_equals($registry->inner_serial, $inner)
                && hash_equals($registry->unit_hash, $this->crypto->unitHash($outer, $inner));
            $authentic = $signatureValid && $hashMatches;

            $createdNotifications = [];
            $claimedNow = false;

            if (! $authentic) {
                $verdict = CloudVerdict::ForgedSignature;
                $createdNotifications[] = $this->notifications->forgedAlert($deviceToken, $outer);
            } elseif ($registry->status === SerialStatus::Recalled) {
                $verdict = CloudVerdict::RecalledBatch;
                $recallNotification = $this->notifications->recallForSerial($deviceToken, $registry);
                if ($recallNotification) {
                    $createdNotifications[] = $recallNotification;
                }
            } elseif ($scannedAt->toDateString() > $registry->exp_date->toDateString()) {
                $verdict = CloudVerdict::Expired;
            } elseif ($registry->claimed_device_token === null || ! empty($log['transfer_ticket']) || ! empty($log['transfer'])) {
                if (! empty($log['transfer_ticket'])) {
                    $this->transfers->redeem($registry, $log['transfer_ticket'], $deviceToken);
                } elseif (! empty($log['transfer'])) {
                    $this->transfers->redeemFlutterPayload($registry, $log['transfer'], $deviceToken);
                }
                $registry->claimed_device_token = $deviceToken;
                $registry->claimed_at = $scannedAt;
                $registry->claimed_gps = $lat !== null && $lng !== null
                    ? sprintf('%.7F,%.7F', $lat, $lng)
                    : null;

                if ($registry->status !== SerialStatus::ClonedFlagged) {
                    $registry->status = SerialStatus::ClaimedGenuine;
                }

                $registry->save();
                $verdict = CloudVerdict::VerifiedClean;
                $claimedNow = true;
            } elseif (hash_equals($registry->claimed_device_token, $deviceToken)) {
                $verdict = CloudVerdict::OwnerRepeatOk;
            } elseif ($registry->status !== SerialStatus::ClonedFlagged
                && $this->isWithinCheckoutGrace($registry, $scannedAt, $lat, $lng)) {
                $verdict = CloudVerdict::StoreGraceOk;
            } else {
                $verdict = CloudVerdict::CloneDetected;
                $claimedToken = (string) $registry->claimed_device_token;
                $registry->status = SerialStatus::ClonedFlagged;
                $registry->save();

                $createdNotifications[] = $this->notifications->cloneAlert($claimedToken, $registry, $deviceToken);
                $createdNotifications[] = $this->notifications->cloneAlert($deviceToken, $registry, $claimedToken);

                $this->anomalies->recordClone($registry, $claimedToken, $deviceToken, $lat, $lng);
            }

            $offlineVerdict = isset($log['offline_verdict']) && $log['offline_verdict'] !== ''
                ? mb_substr((string) $log['offline_verdict'], 0, 32)
                : null;

            $scan = ScanLog::create([
                'sync_uuid' => $syncUuid,
                'outer_serial' => $outer,
                'inner_serial' => $inner,
                'device_token' => $deviceToken,
                'user_role' => $actor->role,
                'scan_mode' => $mode,
                'offline_verdict' => $offlineVerdict,
                'cloud_cross_check_verdict' => $verdict,
                'gps_lat' => $lat,
                'gps_lng' => $lng,
                'scanned_at' => $scannedAt,
                'synced_at' => now(),
            ]);

            if ($attestation && $scanSeq !== null) {
                $attestation->update(['last_scan_seq' => $scanSeq]);
            }

            if (in_array($verdict, [CloudVerdict::CloneDetected, CloudVerdict::RecalledBatch], true)) {
                $this->alerts->notify($verdict->value, [
                    'outer_serial' => $outer,
                    'device_token' => DeviceToken::mask($deviceToken),
                    'contact_hash' => $log['contact_hash'] ?? null,
                    'verdict' => $verdict->value,
                    'scanned_at' => $scannedAt->toIso8601String(),
                ]);
            }

            [$messageEn, $messageBn] = $this->verdictMessage($verdict, $registry, $claimedNow);

            return [
                'sync_uuid' => $syncUuid,
                'outer_serial' => $outer,
                'verdict' => $verdict->value,
                'verdict_label' => str_replace('_', ' ', ucfirst($verdict->value)),
                'signature_valid' => $signatureValid,
                'registered' => $registered,
                'claimed_now' => $claimedNow,
                'status' => $registry?->status->value,
                'unit' => $registry ? [
                    'factory_id' => $registry->factory_id,
                    'machine_id' => $registry->machine_id,
                    'batch_id' => $registry->batch_id,
                    'unit_number' => $registry->unit_number,
                    'prod_date' => $registry->prod_date?->toDateString(),
                    'exp_date' => $registry->exp_date?->toDateString(),
                ] : null,
                'feedback_notification_ids' => array_values(array_map(fn ($n) => $n->id, array_filter($createdNotifications, fn ($n) => $n->device_token === $deviceToken))),
                'message_en' => $messageEn,
                'message_bn' => $messageBn,
                'duplicate' => false,
                'scan_id' => $scan->id,
                'processed_at' => now()->toIso8601String(),
                '_notifications' => $createdNotifications,
            ];
        }, 3);
    }

    private function duplicateResult(ScanLog $existing): array
    {
        $registry = SerialRegistry::query()->where('outer_serial', $existing->outer_serial)->first();
        [$messageEn, $messageBn] = $this->verdictMessage($existing->cloud_cross_check_verdict, $registry, false);

        return [
            'sync_uuid' => $existing->sync_uuid,
            'outer_serial' => $existing->outer_serial,
            'verdict' => $existing->cloud_cross_check_verdict->value,
            'verdict_label' => str_replace('_', ' ', ucfirst($existing->cloud_cross_check_verdict->value)),
            'signature_valid' => $existing->cloud_cross_check_verdict !== CloudVerdict::ForgedSignature,
            'registered' => $registry !== null,
            'claimed_now' => false,
            'status' => $registry?->status->value,
            'unit' => $registry ? [
                'factory_id' => $registry->factory_id,
                'machine_id' => $registry->machine_id,
                'batch_id' => $registry->batch_id,
                'unit_number' => $registry->unit_number,
                'prod_date' => $registry->prod_date?->toDateString(),
                'exp_date' => $registry->exp_date?->toDateString(),
            ] : null,
            'feedback_notification_ids' => [],
            'message_en' => $messageEn,
            'message_bn' => $messageBn,
            'duplicate' => true,
            'scan_id' => $existing->id,
            'processed_at' => $existing->synced_at?->toIso8601String(),
            '_notifications' => [],
        ];
    }

    private function scanTime(mixed $value, ScanMode $mode): CarbonImmutable
    {
        if ($mode === ScanMode::OnlineLive || ! $value) {
            return CarbonImmutable::now();
        }

        try {
            $time = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw new BusinessRuleException('invalid_scanned_at', 'scanned_at must be a valid date/time.');
        }

        $maxFuture = (int) config('triproof.verification.max_future_seconds', 300);
        if ($time->greaterThan(CarbonImmutable::now()->addSeconds($maxFuture))) {
            return CarbonImmutable::now();
        }

        return $time;
    }

    private function isWithinCheckoutGrace(SerialRegistry $registry, CarbonImmutable $scannedAt, ?float $lat, ?float $lng): bool
    {
        if (! $registry->claimed_at || $lat === null || $lng === null) {
            return false;
        }

        $seconds = abs($registry->claimed_at->getTimestamp() - $scannedAt->getTimestamp());
        if ($seconds > (int) config('triproof.verification.grace_seconds', 300)) {
            return false;
        }

        $claimed = $registry->claimedCoordinates();
        if (! $claimed) {
            return false;
        }

        return $this->distanceMeters($claimed[0], $claimed[1], $lat, $lng)
            <= (float) config('triproof.verification.grace_meters', 100);
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    private function verdictMessage(CloudVerdict $verdict, ?SerialRegistry $registry, bool $claimedNow): array
    {
        return match ($verdict) {
            CloudVerdict::VerifiedClean => [
                $claimedNow
                    ? 'Authentic. Ownership of this unit is now linked to this device.'
                    : 'Authentic TriProof signature and registry match.',
                $claimedNow
                    ? 'পণ্যটি আসল। এই ইউনিটের মালিকানা এখন এই ডিভাইসের সাথে যুক্ত হয়েছে।'
                    : 'পণ্যটি আসল; TriProof সিগনেচার ও রেজিস্ট্রি সঠিক।',
            ],
            CloudVerdict::OwnerRepeatOk => [
                'Authentic. This device previously registered this unit; no action needed.',
                'পণ্যটি আসল। এই ডিভাইসই আগে ইউনিটটি যাচাই করেছে; কোনো পদক্ষেপ প্রয়োজন নেই।',
            ],
            CloudVerdict::StoreGraceOk => [
                'Authentic. The second scan is within the store checkout grace window (5 minutes / 100 metres).',
                'পণ্যটি আসল। দ্বিতীয় স্ক্যানটি স্টোর চেকআউট গ্রেস সীমার (৫ মিনিট / ১০০ মিটার) মধ্যে হয়েছে।',
            ],
            CloudVerdict::CloneDetected => [
                'Counterfeit risk. Another device already owns this serial and the grace rule did not apply. Quarantine the unit and check the outer box.',
                'নকলের ঝুঁকি। অন্য একটি ডিভাইস ইতিমধ্যে এই সিরিয়ালের মালিকানা নিয়েছে এবং গ্রেস নিয়ম প্রযোজ্য হয়নি। ইউনিটটি আলাদা করে আউটার বক্স যাচাই করুন।',
            ],
            CloudVerdict::RecalledBatch => [
                'This batch is recalled. Do not sell, dispatch or use the unit; follow the recall instructions.',
                'এই ব্যাচটি রিকল করা হয়েছে। ইউনিটটি বিক্রি, পাঠানো বা ব্যবহার করবেন না; রিকল নির্দেশনা অনুসরণ করুন।',
            ],
            CloudVerdict::Expired => [
                'The signature is authentic, but the retail expiry date has passed. Do not sell this unit.',
                'সিগনেচার আসল, তবে বিক্রয়ের মেয়াদ শেষ হয়েছে। ইউনিটটি বিক্রি করবেন না।',
            ],
            CloudVerdict::ForgedSignature => [
                'Verification failed. The label does not carry a valid TriProof Ed25519 signature matching the cloud registry. Treat it as counterfeit.',
                'যাচাই ব্যর্থ। লেবেলে বৈধ TriProof Ed25519 সিগনেচার নেই অথবা ক্লাউড রেজিস্ট্রির সাথে মিলছে না। এটিকে নকল হিসেবে গণ্য করুন।',
            ],
        };
    }

    private function inspectionMessage(CloudVerdict $verdict, ?SerialRegistry $registry): array
    {
        return match ($verdict) {
            CloudVerdict::VerifiedClean => [
                'Signature valid. The serial is registered and not recalled, cloned or expired.',
                'সিগনেচার বৈধ। সিরিয়ালটি নিবন্ধিত এবং রিকল, ক্লোন বা মেয়াদোত্তীর্ণ নয়।',
            ],
            CloudVerdict::CloneDetected => [
                'Signature valid, but this serial is flagged as a possible clone. Quarantine and investigate.',
                'সিগনেচার বৈধ, তবে সিরিয়ালটি সম্ভাব্য ক্লোন হিসেবে চিহ্নিত। ইউনিটটি আলাদা করে তদন্ত করুন।',
            ],
            CloudVerdict::RecalledBatch => [
                'This serial belongs to a recalled batch. Do not sell, dispatch or use it.',
                'সিরিয়ালটি রিকল করা ব্যাচের অন্তর্ভুক্ত। বিক্রি, পাঠানো বা ব্যবহার করবেন না।',
            ],
            CloudVerdict::Expired => [
                'Signature valid, but the retail expiry date has passed.',
                'সিগনেচার বৈধ, তবে বিক্রয়ের মেয়াদ শেষ হয়েছে।',
            ],
            default => [
                'Signature verification failed or the serial is not in the TriProof registry. Treat it as counterfeit.',
                'সিগনেচার যাচাই ব্যর্থ অথবা সিরিয়ালটি TriProof রেজিস্ট্রিতে নেই। এটিকে নকল হিসেবে গণ্য করুন।',
            ],
        };
    }
}
