<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TriProof Product Passport</title>
    <style>
        *{box-sizing:border-box}body{margin:0;font-family:DejaVu Sans,Arial,sans-serif;background:#f1f5f9;color:#0f172a;line-height:1.5}
        .wrap{max-width:720px;margin:0 auto;padding:20px 16px 48px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px;margin-bottom:16px;box-shadow:0 4px 18px rgba(15,23,42,.05)}
        .brand{display:flex;align-items:center;gap:10px;font-weight:700;color:#065f46;font-size:18px}.dot{width:11px;height:11px;border-radius:3px;background:#0a9b68}
        h1{font-size:22px;margin:12px 0 6px}h2{font-size:15px;margin:0 0 12px;color:#334155}.muted{color:#64748b;font-size:13px}
        .badge{display:inline-block;background:#ecfdf5;color:#065f46;border-radius:99px;padding:5px 11px;font-size:12px;font-weight:700;margin:4px 4px 0 0}.warn{background:#fff7ed;color:#9a3412}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}.field{background:#f8fafc;border-radius:10px;padding:10px}.label{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;font-weight:700}.value{font-family:DejaVu Sans Mono,monospace;font-size:12px;margin-top:3px;word-break:break-all}
        .bar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden}.bar span{display:block;height:100%;background:#0a9b68}.steps{counter-reset:step;padding-left:22px}.steps li{margin:8px 0}.btn{display:inline-block;background:#065f46;color:#fff;text-decoration:none;border-radius:10px;padding:11px 16px;font-weight:700;font-size:14px}.code{font-family:DejaVu Sans Mono,monospace;background:#f8fafc;padding:10px;border-radius:8px;word-break:break-all;font-size:11px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="brand"><span class="dot"></span>TriProof AI · Product Passport</div>
        @if ($serial)
            <h1>Authentic product record found</h1>
            <p class="muted">Public verification page · no login required. Cloud ownership and recall state are checked inside the TriProof AI app.</p>
            <span class="badge">Ed25519 signed</span>
            <span class="badge">PO {{ $serial->po?->po_number ?? '—' }}</span>
            @if ($dppDigest)<span class="badge">EU DPP {{ $dppDigest }}</span>@endif
        @else
            <h1>Product record not found</h1>
            <p class="muted">The scanned outer serial is not registered, or the product label has been replaced.</p>
            <span class="badge warn">Do not trust this label</span>
        @endif
    </div>

    @if ($serial)
        <div class="card">
            <h2>Product origin and batch</h2>
            <div class="grid">
                <div class="field"><div class="label">Outer serial</div><div class="value">{{ $outer }}</div></div>
                <div class="field"><div class="label">Factory</div><div class="value">{{ $serial->factory_id }}</div></div>
                <div class="field"><div class="label">Machine / Batch</div><div class="value">{{ $serial->machine_id }} / {{ $serial->batch_id }}</div></div>
                <div class="field"><div class="label">Unit</div><div class="value">{{ str_pad((string) $serial->unit_number, 6, '0', STR_PAD_LEFT) }}</div></div>
                <div class="field"><div class="label">Produced</div><div class="value">{{ $serial->prod_date?->format('d M Y') }}</div></div>
                <div class="field"><div class="label">Expiry</div><div class="value">{{ $serial->exp_date?->format('d M Y') }}</div></div>
            </div>
        </div>

        @if ($scorecard)
            <div class="card">
                <h2>EU Digital Product Passport · sustainability scorecard</h2>
                <p class="muted">Digest {{ $dppDigest ?? '—' }} is included in the signed serial.</p>
                <p><strong>{{ $scorecard['score'] }}/100</strong> provenance and compliance score</p>
                <div class="bar"><span style="width:{{ $scorecard['score'] }}%"></span></div>
                <div class="grid" style="margin-top:14px">
                    <div class="field"><div class="label">Yarn mill</div><div class="value">{{ $scorecard['yarn_mill'] ?? '—' }}</div></div>
                    <div class="field"><div class="label">Dye house</div><div class="value">{{ $scorecard['dye_house'] ?? '—' }}</div></div>
                    <div class="field"><div class="label">Fabric</div><div class="value">{{ $scorecard['fabric_composition'] ?? '—' }}</div></div>
                    <div class="field"><div class="label">Certifications</div><div class="value">
                        OEKO-TEX {{ $scorecard['certifications']['oeko_tex'] ?? '—' }}<br>
                        GOTS {{ $scorecard['certifications']['gots'] ?? '—' }}<br>
                        BSCI {{ $scorecard['certifications']['bsci'] ?? '—' }}
                    </div></div>
                    <div class="field"><div class="label">Carbon footprint</div><div class="value">{{ $scorecard['carbon_footprint_kg'] ?? '—' }} kg CO2e</div></div>
                    <div class="field"><div class="label">Water footprint</div><div class="value">{{ $scorecard['water_footprint_liters'] ?? '—' }} L</div></div>
                </div>
            </div>
        @endif
    @endif

    <div class="card">
        <h2>Verify the physical seal</h2>
        <ol class="steps">
            <li>Inspect the tamper-evident seal. Do not remove it if it is broken or resealed.</li>
            <li>Open the TriProof AI Scanner app and choose <strong>Inner Seal</strong> on the paired sticker.</li>
            <li>Scan the inner QR. The app verifies the Ed25519 signature offline and checks recall/clone state when online.</li>
        </ol>
        <p class="muted">Only the outer serial is shown publicly. The private inner signature never appears on this page.</p>
        <a class="btn" href="{{ $appScheme }}">Open in TriProof AI Scanner</a>
    </div>

    <div class="card">
        <div class="label">Scanned outer payload</div>
        <div class="code">{{ $outer }}</div>
    </div>
</div>
</body>
</html>
