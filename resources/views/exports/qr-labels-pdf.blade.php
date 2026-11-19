@php
    $merkleSnippet = strlen($merkle) > 16 ? substr($merkle, 0, 8).'…'.substr($merkle, -8) : $merkle;
    $batchLabels = collect($serials)->pluck('batch_id')->filter()->unique()->values()->implode(', ');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>TriProof QR sticker sheet — {{ $order->po_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #0f172a;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8pt;
            line-height: 1.3;
        }
        .sheet-header {
            width: 100%;
            border-collapse: collapse;
            padding-bottom: 3mm;
            border-bottom: 1px solid #cbd5e1;
        }
        .sheet-header td { vertical-align: middle; }
        .brand { color: #065f46; font-size: 12pt; font-weight: bold; }
        .title { font-size: 10pt; font-weight: bold; }
        .meta { color: #475569; font-size: 7pt; }
        .mono { font-family: "DejaVu Sans Mono", monospace; }
        .pill {
            display: inline-block;
            padding: 1mm 2mm;
            border-radius: 2mm;
            background: #ecfdf5;
            color: #065f46;
            font-size: 6.5pt;
            font-weight: bold;
        }
        .note {
            margin: 2mm 0 3mm;
            padding: 1.5mm 2mm;
            background: #f8fafc;
            color: #334155;
            font-size: 6.5pt;
        }
        .pair-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .pair-row { page-break-inside: avoid; break-inside: avoid; }
        .pair-cell {
            width: 43%;
            vertical-align: top;
            padding: 0 1mm 3mm 0;
        }
        .pair-cell.right { padding-right: 0; padding-left: 1mm; }
        .cut-column {
            width: 14%;
            vertical-align: middle;
            text-align: center;
            padding: 0 1mm 3mm;
        }
        .sticker {
            border: 1.2px dashed #334155;
            border-radius: 3mm;
            padding: 6mm;
            text-align: center;
            height: 67mm;
        }
        .sticker.inner { border-color: #dc2626; }
        .sticker-head {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2mm;
        }
        .sticker-head td { vertical-align: middle; }
        .sticker-title {
            color: #1e293b;
            font-size: 7pt;
            font-weight: bold;
            text-align: left;
        }
        .sticker.inner .sticker-title { color: #b91c1c; }
        .badge-cell { width: 14mm; text-align: right; }
        .corner-badge {
            display: inline-block;
            min-width: 9mm;
            padding: 1mm 1.5mm;
            border-radius: 1.5mm;
            background: #1e293b;
            color: #ffffff;
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 8pt;
            font-weight: bold;
        }
        .sticker.inner .corner-badge { background: #b91c1c; }
        .qr { display: block; margin: 0 auto; image-rendering: pixelated; }
        .qr.outer { width: 30mm; height: 30mm; }
        .qr.inner { width: 34mm; height: 34mm; }
        .human {
            margin-top: 2mm;
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 9pt;
            font-weight: bold;
            line-height: 1.2;
            word-break: break-all;
        }
        .footer-note {
            margin-top: 2mm;
            color: #475569;
            font-size: 6.5pt;
            line-height: 1.35;
        }
        .placement {
            margin-top: 1.5mm;
            color: #b91c1c;
            font-size: 7pt;
            font-weight: bold;
        }
        .cut-line {
            width: 0;
            height: 52mm;
            margin: 0 auto 2mm;
            border-left: 1.5px dashed #475569;
        }
        .cut-badge {
            color: #334155;
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 5.6pt;
            font-weight: bold;
            line-height: 1.25;
            word-break: break-all;
        }
        .page-footer {
            margin-top: 3mm;
            padding-top: 1.5mm;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 6pt;
            text-align: center;
        }
    </style>
</head>
<body>
    <table class="sheet-header">
        <tr>
            <td>
                <div class="brand">TriProof AI · QR Sticker Sheet</div>
                <div class="title">PO {{ $order->po_number }} · Factory {{ $order->factory_id }}</div>
                <div class="meta">
                    Batch: {{ $batchLabels !== '' ? $batchLabels : '—' }}
                    · Pairs: {{ $printedPairs }}{{ $truncated ? ' of '.$totalPairs : '' }}
                    · Merkle: <span class="mono">{{ $merkleSnippet }}</span>
                </div>
            </td>
            <td style="text-align: right;">
                <span class="pill">OFFLINE ED25519 VERIFIED</span>
                <div class="meta">Printed {{ $printedAt->format('d M Y, H:i') }}</div>
            </td>
        </tr>
    </table>

    <div class="note">
        <strong>Scale: 100% Actual Size for Thermal / A4 Sticker Paper.</strong>
        Outer: Error Correction M · 30 mm · Inner: Error Correction Q · 34 mm · 4-module quiet zone.
        Match the pair code before sealing. Print at 100%, never "Fit to page".
    </div>

    @foreach ($serials as $serial)
        @php $pair = $loop->iteration; @endphp
        <table class="pair-table">
            <tr class="pair-row">
                <td class="pair-cell">
                    <div class="sticker">
                        <table class="sticker-head">
                            <tr>
                                <td class="sticker-title">{{ $pair }} - OUTER SERIAL</td>
                                <td class="badge-cell"><span class="corner-badge">O{{ $pair }}</span></td>
                            </tr>
                        </table>
                        <img class="qr outer" src="{{ $serial['outer_qr'] }}" alt="Outer public serial QR">
                        <div class="human">{{ $serial['outer_serial'] }}</div>
                        <div class="footer-note">
                            Factory: {{ $serial['factory_id'] }} | Machine: {{ $serial['machine_id'] }} |
                            Batch: {{ $serial['batch_id'] }} | Exp: {{ $serial['exp_date'] }}
                        </div>
                    </div>
                </td>
                <td class="cut-column">
                    <div class="cut-line"></div>
                    <div class="cut-badge">{!! 'PAIR '.$pair.': O'.$pair.' &lt;-&gt; I'.$pair !!}</div>
                </td>
                <td class="pair-cell right">
                    <div class="sticker inner">
                        <table class="sticker-head">
                            <tr>
                                <td class="sticker-title">{{ $pair }} - INNER SERIAL</td>
                                <td class="badge-cell"><span class="corner-badge">I{{ $pair }}</span></td>
                            </tr>
                        </table>
                        <img class="qr inner" src="{{ $serial['inner_qr'] }}" alt="Inner private signature QR">
                        <div class="footer-note">Unit Hash: {{ substr($serial['unit_hash'], 0, 12) }}...</div>
                        <div class="placement">Apply inside sealed package</div>
                    </div>
                </td>
            </tr>
        </table>
    @endforeach

    <div class="page-footer">
        Keep each inner QR with its matching outer carton · TriProof AI · {{ $order->po_number }}
    </div>
</body>
</html>
