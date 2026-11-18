@php
    $fingerprint = $publicKey !== ''
        ? substr(hash('sha256', base64_decode($publicKey, true) ?: $publicKey), 0, 32)
        : '—';
    $merkle = $order->merkle_root_hash ?: '—';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>TriProof batch package — {{ $order->po_number }}</title>
    <style>
        @page {
            margin: 24px 22px 30px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #0f172a;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8px;
            line-height: 1.35;
        }

        .muted {
            color: #64748b;
        }

        .brand {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -0.2px;
        }

        .brand-mark {
            display: inline-block;
            width: 9px;
            height: 9px;
            margin-right: 5px;
            vertical-align: -1px;
            background: #0a9b68;
            border-radius: 2px;
        }

        h1 {
            margin: 2px 0 0;
            font-size: 18px;
            letter-spacing: -0.3px;
        }

        .header-table,
        .meta-table,
        .registry-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: top;
        }

        .header-table .right {
            text-align: right;
        }

        .pill {
            display: inline-block;
            padding: 3px 7px;
            border-radius: 8px;
            background: #ecfdf5;
            color: #047857;
            font-size: 6.5px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .panel {
            margin-top: 10px;
            padding: 9px 11px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
        }

        .meta-table td {
            width: 25%;
            padding: 3px 8px 3px 0;
            vertical-align: top;
        }

        .meta-label {
            display: block;
            margin-bottom: 1px;
            color: #94a3b8;
            font-size: 5.5px;
            font-weight: 700;
            letter-spacing: 0.45px;
            text-transform: uppercase;
        }

        .meta-value {
            font-size: 7.5px;
            font-weight: 600;
            word-break: break-all;
        }

        .mono {
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 6px;
            word-break: break-all;
        }

        .section-title {
            margin: 12px 0 5px;
            color: #0f172a;
            font-size: 9px;
            font-weight: 700;
        }

        .registry-table {
            border: 1px solid #dfe6ee;
        }

        .registry-table thead th {
            padding: 5px 4px;
            border-bottom: 1px solid #dfe6ee;
            background: #f1f5f9;
            color: #475569;
            font-size: 5.5px;
            font-weight: 700;
            letter-spacing: 0.35px;
            text-align: left;
            text-transform: uppercase;
        }

        .registry-table tbody td {
            padding: 4px;
            border-bottom: 1px solid #edf1f5;
            vertical-align: top;
        }

        .registry-table tbody tr:nth-child(even) td {
            background: #fbfcfd;
        }

        .registry-table tbody tr {
            page-break-inside: avoid;
        }

        .registry-table .index {
            color: #94a3b8;
            font-size: 5.5px;
            text-align: right;
        }

        .registry-table .tight {
            font-size: 5.5px;
            line-height: 1.3;
        }

        .state {
            display: inline-block;
            padding: 2px 4px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #334155;
            font-size: 4.8px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .state-claimed {
            background: #ecfdf5;
            color: #047857;
        }

        .state-recalled {
            background: #fef2f2;
            color: #b91c1c;
        }

        .state-cloned {
            background: #fff7ed;
            color: #c2410c;
        }

        .notice {
            margin-top: 10px;
            padding: 8px 10px;
            border-left: 3px solid #0a9b68;
            background: #f0fdf9;
            color: #334155;
            font-size: 6.5px;
            line-height: 1.5;
        }

        .footer {
            margin-top: 10px;
            padding-top: 5px;
            border-top: 1px solid #e2e8f0;
            color: #94a3b8;
            font-size: 5.5px;
            text-align: center;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td>
                <div class="brand"><span class="brand-mark"></span>TriProof AI</div>
                <h1>Batch package</h1>
                <div class="muted" style="margin-top: 2px; font-size: 7px;">
                    Serial registry export for purchase order {{ $order->po_number }}
                </div>
            </td>
            <td class="right">
                <span class="pill">Ed25519 signed</span>
                <div class="muted" style="margin-top: 5px; font-size: 6.5px;">
                    Generated {{ $generatedAt->format('d M Y, H:i') }}
                </div>
                <div class="muted" style="font-size: 6.5px;">
                    {{ $serials->count() }} unit{{ $serials->count() === 1 ? '' : 's' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="panel">
        <table class="meta-table">
            <tr>
                <td>
                    <span class="meta-label">Purchase order</span>
                    <span class="meta-value">{{ $order->po_number }}</span>
                </td>
                <td>
                    <span class="meta-label">Buyer</span>
                    <span class="meta-value">{{ $order->buyer?->name ?? '—' }}</span>
                </td>
                <td>
                    <span class="meta-label">Factory</span>
                    <span class="meta-value">{{ $order->factory_id }}</span>
                </td>
                <td>
                    <span class="meta-label">Authorized quota</span>
                    <span class="meta-value">{{ number_format($order->authorized_quota) }} units</span>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <span class="meta-label">Merkle root</span>
                    <span class="meta-value mono">{{ $merkle }}</span>
                </td>
                <td colspan="2">
                    <span class="meta-label">Signing key fingerprint (SHA-256)</span>
                    <span class="meta-value mono">{{ $fingerprint }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Unit registry</div>
    <table class="registry-table">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 27%;">Outer serial</th>
                <th style="width: 25%;">Inner signature (Base64 Ed25519)</th>
                <th style="width: 17%;">SHA-256 unit hash</th>
                <th style="width: 12%;">Line / batch</th>
                <th style="width: 10%;">Unit / dates</th>
                <th style="width: 6%;">State</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($serials as $serial)
                @php
                    $stateClass = match ($serial->status->value) {
                        'claimed_genuine' => 'state-claimed',
                        'recalled' => 'state-recalled',
                        'cloned_flagged' => 'state-cloned',
                        default => '',
                    };
                @endphp
                <tr>
                    <td class="index">{{ $loop->iteration }}</td>
                    <td class="mono">{{ $serial->outer_serial }}</td>
                    <td class="mono">{{ $serial->inner_serial }}</td>
                    <td class="mono">{{ $serial->unit_hash }}</td>
                    <td class="tight">
                        <strong>{{ $serial->machine_id }}</strong><br>
                        {{ $serial->batch_id }}
                        @if ($serial->rework_parent_serial)
                            <br><span class="muted">from {{ $serial->rework_parent_serial }}</span>
                        @endif
                    </td>
                    <td class="tight">
                        #{{ str_pad((string) $serial->unit_number, 6, '0', STR_PAD_LEFT) }}<br>
                        {{ $serial->prod_date?->format('Y-m-d') ?? '—' }}<br>
                        {{ $serial->exp_date?->format('Y-m-d') ?? '—' }}
                    </td>
                    <td>
                        <span class="state {{ $stateClass }}">{{ str_replace('_', ' ', $serial->status->value) }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="notice">
        <strong>Offline authenticity check.</strong>
        The outer serial is <span class="mono">FACTORY-LINE-BATCH-UNIT</span> followed by production and retail expiry dates.
        Recompute <span class="mono">SHA-256(outer + "|" + inner)</span> and compare it with the registry hash. Verify the
        inner Base64 signature with the public key at <span class="mono">/.well-known/triproof-key.json</span> and the
        fingerprint printed above. A valid signature proves HQ origin; the registry proves over-quota forgery protection.
    </div>

    <div class="footer">
        TriProof AI · Confidential batch package · {{ $order->po_number }} · Generated {{ $generatedAt->toIso8601String() }}
    </div>
</body>
</html>
