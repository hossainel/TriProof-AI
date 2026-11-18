<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AnomalyAnalysisService;
use App\Services\Crypto\SerialCryptoService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReworkService
{
    public function __construct(
        private readonly SerialCryptoService $crypto,
        private readonly AnomalyAnalysisService $anomalies,
        private readonly AiClient $ai,
    ) {}

    public function reconcile(User $actor, array $entries): array
    {
        if (! $actor->isAdmin() && ! $actor->isFactory()) {
            throw new BusinessRuleException('forbidden', 'Only factory QA and administrators can reconcile rework lineage.', 403);
        }

        if ($entries === []) {
            throw new BusinessRuleException('entries_required', 'Provide at least one rework entry.');
        }

        if (count($entries) > 1000) {
            throw new BusinessRuleException('too_many_entries', 'A reconciliation request may contain at most 1000 entries.');
        }

        $results = [];
        $linked = 0;
        $alreadyLinked = 0;
        $rejected = 0;
        $gaps = 0;
        $ocrUsed = 0;

        foreach (array_values($entries) as $index => $entry) {
            if (! is_array($entry)) {
                $rejected++;

                continue;
            }

            $result = $this->reconcileEntry($actor, $entry, $index, $ocrUsed);
            $results[] = $result;

            match ($result['status']) {
                'linked' => $linked++,
                'already_linked' => $alreadyLinked++,
                'gap' => $gaps++,
                default => $rejected++,
            };
        }

        $counts = [
            'entries' => count($entries),
            'linked' => $linked,
            'already_linked' => $alreadyLinked,
            'rejected' => $rejected,
            'gaps' => $gaps,
            'ocr_used' => $ocrUsed,
        ];

        $aiSummary = null;
        $aiGenerated = false;

        if ($this->ai->isConfigured() && ($linked + $alreadyLinked + $gaps + $rejected) > 0) {
            try {
                $aiSummary = $this->ai->reworkAdvice([
                    'counts' => $counts,
                    'sample_linked' => array_slice(array_values(array_map(
                        fn ($r) => ['parent' => $r['parent_outer_serial'], 'child' => $r['child_outer_serial']],
                        array_filter($results, fn ($r) => $r['status'] === 'linked')
                    )), 0, 10),
                    'sample_gaps' => array_slice(array_values(array_map(
                        fn ($r) => ['child' => $r['child_outer_serial'], 'reason' => $r['reason_en']],
                        array_filter($results, fn ($r) => $r['status'] === 'gap')
                    )), 0, 10),
                ]);
                $aiGenerated = true;
            } catch (Throwable) {
                Log::warning('AI rework summary failed; using deterministic bilingual guidance.');
            }
        }

        if (! $aiSummary) {
            if ($linked + $alreadyLinked > 0 && $gaps === 0 && $rejected === 0) {
                $aiSummary = [
                    'summary_en' => "Reconciled {$linked} rework link(s); no lineage gaps detected in this submission. Keep the signed rework log with the batch record.",
                    'summary_bn' => "{$linked}টি রিওয়ার্ক লিংক সফলভাবে যুক্ত হয়েছে; এই জমায় কোনো লাইনেজ গ্যাপ পাওয়া যায়নি। স্বাক্ষরিত রিওয়ার্ক লগ ব্যাচ রেকর্ডের সাথে সংরক্ষণ করুন।",
                ];
            } else {
                $aiSummary = [
                    'summary_en' => "Linked {$linked} rework record(s), {$gaps} gap(s), {$rejected} rejected. Hold affected units at the floor until a supervisor verifies every parent label against the paper rework log.",
                    'summary_bn' => "{$linked}টি রিওয়ার্ক রেকর্ড যুক্ত হয়েছে, গ্যাপ {$gaps}টি, বাতিল {$rejected}টি। সুপারভাইজার কাগজের রিওয়ার্ক লগের সাথে প্রতিটি মূল লেবেল যাচাই না করা পর্যন্ত আক্রান্ত ইউনিটগুলো ফ্লোরে আটকে রাখুন।",
                ];
            }
        }

        return [
            'results' => $results,
            'counts' => $counts,
            'summary_en' => $aiSummary['summary_en'],
            'summary_bn' => $aiSummary['summary_bn'],
            'ai_generated' => $aiGenerated,
            'ai_configured' => $this->ai->isConfigured(),
        ];
    }

    private function reconcileEntry(User $actor, array $entry, int $index, int &$ocrUsed): array
    {
        $parentSerial = strtoupper(trim((string) ($entry['parent_outer_serial'] ?? '')));
        $childSerial = strtoupper(trim((string) ($entry['child_outer_serial'] ?? '')));
        $ocr = null;

        if ($childSerial === '' && ! empty($entry['image_base64'])) {
            try {
                $base64 = (string) $entry['image_base64'];
                $mime = (string) ($entry['image_mime'] ?? 'image/jpeg');
                if (str_starts_with($base64, 'data:')) {
                    [$meta, $base64] = explode(',', $base64, 2);
                    if (preg_match('/data:([^;]+);base64/', $meta, $m)) {
                        $mime = $m[1];
                    }
                }
                $ocr = $this->ai->extractSerialsFromImage($base64, $mime);
                $childSerial = strtoupper($ocr['outer_serial'] ?? '');
                $ocrUsed++;
            } catch (Throwable $e) {
                return $this->result($index, $parentSerial, $childSerial, 'rejected', 'AI Vision OCR is not available for this image. Enter the child serial manually.', 'ছবির জন্য AI Vision OCR পাওয়া যাচ্ছে না। শিশু সিরিয়ালটি হাতে লিখুন।', $ocr);
            }
        }

        if ($parentSerial === '' || $childSerial === '') {
            return $this->result($index, $parentSerial, $childSerial, 'rejected', 'Both parent and child outer serials are required (or provide a readable label image for the child).', 'মূল ও নতুন উভয় আউটার সিরিয়াল প্রয়োজন (অথবা নতুন ইউনিটের পাঠযোগ্য লেবেল ছবি দিন)।', $ocr);
        }

        $childParsed = $this->crypto->parseOuterSerial($childSerial);
        $parentParsed = $this->crypto->parseOuterSerial($parentSerial);

        if (! $childParsed || ! $parentParsed) {
            return $this->result($index, $parentSerial, $childSerial, 'rejected', 'One or both serials do not match the TriProof outer serial format.', 'একটি বা উভয় সিরিয়াল TriProof আউটার সিরিয়াল ফরম্যাটের সাথে মিলছে না।', $ocr);
        }

        /** @var SerialRegistry|null $child */
        $child = SerialRegistry::query()->where('outer_serial', $childSerial)->first();
        /** @var SerialRegistry|null $parent */
        $parent = SerialRegistry::query()->where('outer_serial', $parentSerial)->first();

        if (! $child || ! $parent) {
            $po = $this->findPo($child?->po_id ?? null, $childParsed, $actor);
            $this->recordGap($po, $childParsed['batch_id'], 'Parent or child serial is missing from the cloud registry.', [[
                'outer_serial' => $childSerial,
                'parent_serial' => $parentSerial,
                'reason' => 'Missing from registry',
            ]]);

            return $this->result($index, $parentSerial, $childSerial, 'gap', 'The child or parent serial is missing from the cloud registry. Do not pack this unit.', 'নতুন বা মূল সিরিয়াল ক্লাউড রেজিস্ট্রিতে নেই। ইউনিটটি প্যাক করবেন না।', $ocr);
        }

        if (! $actor->isAdmin() && ! $actor->hasFactoryAccess($child->factory_id)) {
            return $this->result($index, $parentSerial, $childSerial, 'rejected', 'You do not have access to this factory unit.', 'এই কারখানার ইউনিটে আপনার অনুমতি নেই।', $ocr);
        }

        if ($child->factory_id !== $parent->factory_id) {
            $this->recordGap($child->po, $child->batch_id, 'Parent and child serials belong to different factories.', [[
                'outer_serial' => $childSerial,
                'parent_serial' => $parentSerial,
                'reason' => 'Factory mismatch',
            ]]);

            return $this->result($index, $parentSerial, $childSerial, 'gap', 'Parent and child units belong to different factories; lineage cannot be linked.', 'মূল ও নতুন ইউনিট ভিন্ন কারখানার; সম্পর্ক যুক্ত করা যাবে না।', $ocr);
        }

        if (! $this->signatureMatches($child) || ! $this->signatureMatches($parent)) {
            $this->recordGap($child->po, $child->batch_id, 'Rework entry references an invalid signature.', [[
                'outer_serial' => $childSerial,
                'parent_serial' => $parentSerial,
                'reason' => 'Invalid signature',
            ]]);

            return $this->result($index, $parentSerial, $childSerial, 'gap', 'A referenced serial failed Ed25519 registry verification. Quarantine both units.', 'রেফারেন্স করা সিরিয়ালের Ed25519 যাচাই ব্যর্থ। উভয় ইউনিট আলাদা করুন।', $ocr);
        }

        if ($child->outer_serial === $parent->outer_serial) {
            return $this->result($index, $parentSerial, $childSerial, 'rejected', 'A unit cannot be its own rework parent.', 'একটি ইউনিট নিজেই নিজের রিওয়ার্ক মূল হতে পারে না।', $ocr);
        }

        if ($child->rework_parent_serial !== null && $child->rework_parent_serial !== $parent->outer_serial) {
            $this->recordGap($child->po, $child->batch_id, 'Conflicting rework parent link detected.', [[
                'outer_serial' => $childSerial,
                'parent_serial' => $parentSerial,
                'existing_parent' => $child->rework_parent_serial,
                'reason' => 'Conflicting lineage',
            ]]);

            return $this->result($index, $parentSerial, $childSerial, 'gap', 'This child already has a different parent serial. Resolve the conflict with the QA manager before packing.', 'এই নতুন সিরিয়ালের ইতিমধ্যে ভিন্ন মূল সিরিয়াল রয়েছে। প্যাকিংয়ের আগে কিউএ ম্যানেজারের সাথে দ্বন্দ্ব সমাধান করুন।', $ocr);
        }

        if ($this->wouldCreateCycle($child->outer_serial, $parent->outer_serial)) {
            $this->recordGap($child->po, $child->batch_id, 'Cycle detected in rework lineage.', [[
                'outer_serial' => $childSerial,
                'parent_serial' => $parentSerial,
                'reason' => 'Lineage cycle',
            ]]);

            return $this->result($index, $parentSerial, $childSerial, 'rejected', 'Linking would create a cycle in the serial lineage.', 'সংযুক্ত করলে সিরিয়াল লাইনেজে চক্র তৈরি হবে।', $ocr);
        }

        if ($child->rework_parent_serial === $parent->outer_serial) {
            return $this->result($index, $parentSerial, $childSerial, 'already_linked', 'This parent-child lineage is already recorded.', 'এই মূল ও নতুন সিরিয়ালের সম্পর্ক ইতিমধ্যে সংরক্ষিত আছে।', $ocr);
        }

        $child->rework_parent_serial = $parent->outer_serial;
        $child->save();

        return $this->result($index, $parentSerial, $childSerial, 'linked', 'Rework lineage linked and ready for audit.', 'রিওয়ার্ক লাইনেজ যুক্ত হয়েছে এবং অডিটের জন্য প্রস্তুত।', $ocr);
    }

    private function result(int $index, string $parent, string $child, string $status, string $reasonEn, string $reasonBn, ?array $ocr): array
    {
        return [
            'index' => $index,
            'parent_outer_serial' => $parent ?: null,
            'child_outer_serial' => $child ?: null,
            'status' => $status,
            'reason_en' => $reasonEn,
            'reason_bn' => $reasonBn,
            'ocr' => $ocr,
        ];
    }

    private function signatureMatches(SerialRegistry $serial): bool
    {
        return $this->crypto->verify($serial->outer_serial, $serial->inner_serial)
            && hash_equals($serial->unit_hash, $this->crypto->unitHash($serial->outer_serial, $serial->inner_serial));
    }

    private function wouldCreateCycle(string $child, string $parent): bool
    {
        $cursor = $parent;

        for ($i = 0; $i < 50; $i++) {
            if ($cursor === $child) {
                return true;
            }

            $next = SerialRegistry::query()->where('outer_serial', $cursor)->value('rework_parent_serial');
            if (! $next) {
                return false;
            }

            $cursor = $next;
        }

        return true;
    }

    private function findPo(?int $knownPoId, array $parsed, User $actor): ?PurchaseOrder
    {
        if ($knownPoId) {
            return PurchaseOrder::query()->find($knownPoId);
        }

        $query = SerialRegistry::query()
            ->where('factory_id', $parsed['factory_id'])
            ->where('machine_id', $parsed['machine_id'])
            ->where('batch_id', $parsed['batch_id']);

        $poId = $query->value('po_id');

        return $poId ? PurchaseOrder::query()->find($poId) : null;
    }

    private function recordGap(?PurchaseOrder $po, ?string $batchId, string $reason, array $affected): void
    {
        $this->anomalies->recordReworkGap($po?->id, $batchId, $reason, $affected);
    }
}
