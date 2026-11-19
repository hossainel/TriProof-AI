<?php

return [
    'crypto' => [
        'private_key' => env('HQ_PRIVATE_SIGNING_KEY'),
        'public_key' => env('UNIVERSAL_PUBLIC_VERIFICATION_KEY'),
        'key_id' => hash('sha256', (string) env('UNIVERSAL_PUBLIC_VERIFICATION_KEY')),
    ],

    'keys' => [
        'legacy_version' => 'K00',
        'rotation_months' => (int) env('KEY_ROTATION_MONTHS', 6),
    ],

    'anchors' => [
        'provider' => env('ANCHOR_PROVIDER', 'local_ledger'),
    ],

    'alerts' => [
        'webhook_url' => env('ALERT_WEBHOOK_URL'),
    ],

    'jwt' => [
        'secret' => env('JWT_SECRET') ?: env('APP_KEY'),
        'ttl_minutes' => (int) env('JWT_TTL_MINUTES', 720),
        'issuer' => env('JWT_ISSUER', 'triproof-ai'),
        'audience' => env('JWT_AUDIENCE', 'triproof-mobile'),
    ],

    'blacklist' => [
        'max_bytes' => (int) env('OFFLINE_BLACKLIST_MAX_BYTES', 50000),
        'max_cloned' => (int) env('OFFLINE_BLACKLIST_MAX_CLONED', 5000),
        'bloom_target_entries' => (int) env('OFFLINE_BLOOM_TARGET_ENTRIES', 30000),
        'bloom_max_bits' => (int) env('OFFLINE_BLOOM_MAX_BITS', 240000),
    ],

    'verification' => [
        'grace_seconds' => (int) env('VERIFICATION_GRACE_SECONDS', 300),
        'grace_meters' => (int) env('VERIFICATION_GRACE_METERS', 100),
        'max_future_seconds' => 300,
    ],

    // Used by the QR sticker sheet and kept alongside the legacy export setting for compatibility.
    'max_pdf_units' => (int) env('MAX_PDF_UNITS', 20000),

    'exports' => [
        'max_generate' => (int) env('MAX_BATCH_GENERATE', 50000),
        'max_pdf_units' => (int) env('MAX_PDF_UNITS', 20000),
        'qr_preview_pairs' => (int) env('QR_PREVIEW_PAIRS', 2),
    ],

    'ai' => [
        'provider' => strtolower((string) env('AI_PROVIDER', 'none')),
        'api_key' => env('AI_API_KEY'),
        'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'gemini_base_url' => rtrim((string) env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'vision_model' => env('AI_VISION_MODEL', env('AI_MODEL', 'gpt-4o-mini')),
        'timeout' => (int) env('AI_TIMEOUT', 25),
    ],

    'rate_limits' => [
        'login' => (int) env('RATE_LIMIT_LOGIN', 10),
        'api' => (int) env('RATE_LIMIT_API', 120),
        'verify' => (int) env('RATE_LIMIT_VERIFY', 600),
        'ai' => (int) env('RATE_LIMIT_AI', 20),
        'export' => (int) env('RATE_LIMIT_EXPORT', 30),
    ],

    'factory' => [
        'default_expiry_days' => 45,
        'default_retail_expiry_days' => 365,
    ],

    'demo_seed' => (bool) env('TRIPROOF_DEMO_SEED', false),
    'show_demo_hints' => (bool) env('TRIPROOF_SHOW_DEMO_HINTS', false),

    'roles' => [
        'global_buyer' => 'Global Buyer',
        'factory_qa' => 'Factory QA',
        'admin' => 'Administrator',
    ],
];
