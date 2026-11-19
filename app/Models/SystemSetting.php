<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = 'updated_at';

    public const DEFAULTS = [
        'organization_name' => 'TriProof AI',
        'support_email' => 'support@triproof.local',
        'default_factory_expiry_days' => '45',
        'default_retail_expiry_days' => '365',
        'compliance_notice_en' => 'TriProof AI verification supports authenticity decisions but does not replace physical inspection or applicable customs and regulatory requirements.',
        'compliance_notice_bn' => 'TriProof AI যাচাই সত্যতা নির্ধারণে সহায়তা করে, তবে পণ্যের সরাসরি পরিদর্শন বা প্রযোজ্য কাস্টমস ও নিয়ন্ত্রক প্রয়োজনীয়তার বিকল্প নয়।',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $value = static::query()->find($key)?->value;

        return $value ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::setValue($key, $value);
        }
    }

    /**
     * @return array<string, string|null>
     */
    public static function allAsArray(): array
    {
        return static::query()->pluck('value', 'key')->all();
    }
}
