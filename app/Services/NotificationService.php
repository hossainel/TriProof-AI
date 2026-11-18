<?php

namespace App\Services;

use App\Enums\NotificationSeverity;
use App\Models\Notification;
use App\Models\SerialRegistry;
use App\Support\DeviceToken;

class NotificationService
{
    public function create(
        string $deviceToken,
        ?string $outerSerial,
        NotificationSeverity $severity,
        string $titleEn,
        string $messageEn,
        string $titleBn,
        string $messageBn,
    ): Notification {
        return Notification::create([
            'device_token' => $deviceToken,
            'outer_serial' => $outerSerial,
            'severity' => $severity,
            'title_en' => $titleEn,
            'message_en' => $messageEn,
            'title_bn' => $titleBn,
            'message_bn' => $messageBn,
            'is_read' => false,
        ]);
    }

    public function cloneAlert(string $deviceToken, SerialRegistry $serial, string $otherDeviceToken): Notification
    {
        $other = DeviceToken::mask($otherDeviceToken);
        $serialNumber = $serial->outer_serial;

        return $this->create(
            $deviceToken,
            $serialNumber,
            NotificationSeverity::CriticalClone,
            'Counterfeit risk detected',
            "Serial {$serialNumber} was scanned by another device ({$other}). If you did not transfer this product, quarantine the unit and inform the buyer. TriProof has flagged the serial as a possible clone.",
            'নকল পণ্যের ঝুঁকি শনাক্ত হয়েছে',
            "সিরিয়াল {$serialNumber} অন্য একটি ডিভাইস ({$other}) থেকে স্ক্যান হয়েছে। আপনি পণ্যটি হস্তান্তর না করে থাকলে ইউনিটটি আলাদা করে রাখুন এবং ক্রেতাকে জানান। TriProof সিরিয়ালটিকে সম্ভাব্য ক্লোন হিসেবে চিহ্নিত করেছে।"
        );
    }

    public function recallForSerial(string $deviceToken, SerialRegistry $serial): ?Notification
    {
        $exists = Notification::query()
            ->where('device_token', $deviceToken)
            ->where('outer_serial', $serial->outer_serial)
            ->where('severity', NotificationSeverity::RecallAlert->value)
            ->exists();

        if ($exists) {
            return null;
        }

        return $this->create(
            $deviceToken,
            $serial->outer_serial,
            NotificationSeverity::RecallAlert,
            'Recalled product',
            "Serial {$serial->outer_serial} belongs to a recalled batch ({$serial->factory_id}-{$serial->machine_id}-{$serial->batch_id}). Do not sell or use this unit. Return it through the approved recall channel.",
            'পণ্য রিকল করা হয়েছে',
            "সিরিয়াল {$serial->outer_serial} রিকল করা ব্যাচের ({$serial->factory_id}-{$serial->machine_id}-{$serial->batch_id}) অন্তর্ভুক্ত। ইউনিটটি বিক্রি বা ব্যবহার করবেন না। অনুমোদিত রিকল প্রক্রিয়ায় ফেরত দিন।"
        );
    }

    public function forgedAlert(string $deviceToken, string $outerSerial): Notification
    {
        return $this->create(
            $deviceToken,
            $outerSerial,
            NotificationSeverity::Warning,
            'Signature verification failed',
            "The label for {$outerSerial} does not carry a valid TriProof Ed25519 signature. Treat it as counterfeit and report the source.",
            'সিগনেচার যাচাই ব্যর্থ',
            "{$outerSerial} লেবেলের TriProof Ed25519 সিগনেচার বৈধ নয়। এটি নকল হিসেবে গণ্য করুন এবং উৎস সম্পর্কে রিপোর্ট করুন।"
        );
    }

    public function recallSummary(string $deviceToken, string $batchKey, int $affected, ?string $reason): Notification
    {
        $reasonEn = $reason ? " Reason: {$reason}" : '';
        $reasonBn = $reason ? " কারণ: {$reason}" : '';

        return $this->create(
            $deviceToken,
            null,
            NotificationSeverity::RecallAlert,
            'Precision batch recall',
            "Batch {$batchKey} has been recalled ({$affected} units affected). Stop sale or dispatch of any unit from this batch and follow the buyer's return instructions.{$reasonEn}",
            'নির্দিষ্ট ব্যাচ রিকল',
            "ব্যাচ {$batchKey} রিকল করা হয়েছে (আক্রান্ত ইউনিট: {$affected})। এই ব্যাচের কোনো পণ্য বিক্রি বা পাঠাবেন না; ক্রেতার ফেরত নির্দেশনা অনুসরণ করুন।{$reasonBn}"
        );
    }

    public function bulkRecallSummaries(array $deviceTokens, string $batchKey, int $affected, ?string $reason): int
    {
        $deviceTokens = array_values(array_unique(array_filter($deviceTokens)));
        if ($deviceTokens === []) {
            return 0;
        }

        $reasonEn = $reason ? " Reason: {$reason}" : '';
        $reasonBn = $reason ? " কারণ: {$reason}" : '';
        $now = now();
        $rows = [];

        foreach ($deviceTokens as $token) {
            $rows[] = [
                'device_token' => $token,
                'outer_serial' => null,
                'severity' => NotificationSeverity::RecallAlert->value,
                'title_en' => 'Precision batch recall',
                'message_en' => "Batch {$batchKey} has been recalled ({$affected} units affected). Stop sale or dispatch of any unit from this batch and follow the buyer's return instructions.{$reasonEn}",
                'title_bn' => 'নির্দিষ্ট ব্যাচ রিকল',
                'message_bn' => "ব্যাচ {$batchKey} রিকল করা হয়েছে (আক্রান্ত ইউনিট: {$affected})। এই ব্যাচের কোনো পণ্য বিক্রি বা পাঠাবেন না; ক্রেতার ফেরত নির্দেশনা অনুসরণ করুন।{$reasonBn}",
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::query()->insert($chunk);
        }

        return count($rows);
    }

    public static function toPayload(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'device_token' => $notification->device_token,
            'outer_serial' => $notification->outer_serial,
            'severity' => $notification->severity->value,
            'title_en' => $notification->title_en,
            'message_en' => $notification->message_en,
            'title_bn' => $notification->title_bn,
            'message_bn' => $notification->message_bn,
            'is_read' => $notification->is_read,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
