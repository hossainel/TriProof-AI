@php
    $chartPayload = [
        'values' => [
            (int) $chartData['generated'],
            (int) $chartData['packed'],
            (int) $chartData['in_transit'],
            (int) $chartData['claimed'],
            (int) $chartData['cloned'],
            (int) $chartData['recalled'],
        ],
        'labels' => [
            __('triproof.status.generated'),
            __('triproof.status.packed'),
            __('triproof.status.in_transit'),
            __('triproof.status.claimed_genuine'),
            __('triproof.status.cloned_flagged'),
            __('triproof.status.recalled'),
        ],
        'colors' => ['#94a3b8', '#0fa974', '#5b9cf5', '#16c98a', '#ef6464', '#7c3aed'],
    ];

    $mapPayload = [
        'markers' => $markers,
        'labels' => [
            'serial' => __('triproof.dashboard.map.serial'),
            'batch' => __('triproof.common.batch'),
            'device' => __('triproof.dashboard.map.device'),
            'scanned' => __('triproof.dashboard.map.scanned_at'),
            'verdicts' => [
                'clone_detected' => __('triproof.verdict.clone_detected'),
                'forged_signature' => __('triproof.verdict.forged_signature'),
                'recalled_batch' => __('triproof.verdict.recalled_batch'),
                'expired' => __('triproof.verdict.expired'),
                'verified_clean' => __('triproof.verdict.verified_clean'),
            ],
        ],
    ];
@endphp

<div class="grid gap-5 lg:grid-cols-2">
    <section class="card overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.dashboard.chart.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.dashboard.chart.title') }}</h2>
            </div>
            <span class="stat-icon"><x-icon name="chart" class="h-4 w-4" /></span>
        </div>
        <div class="p-5">
            @if (array_sum($chartData) === 0)
                <div class="empty-state min-h-[260px] py-8">
                    <span class="empty-icon"><x-icon name="chart" class="h-6 w-6" /></span>
                    <p class="max-w-xs text-xs leading-5 text-slate-500">{{ __('triproof.dashboard.chart.empty') }}</p>
                </div>
            @else
                <div class="chart-canvas-wrap">
                    <canvas id="triproof-chart" aria-label="{{ __('triproof.dashboard.chart.title') }}" role="img"></canvas>
                </div>
            @endif
        </div>
    </section>

    <section class="card overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.dashboard.map.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.dashboard.map.title') }}</h2>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="badge badge-danger">{{ __('triproof.dashboard.map.legend_clone') }}</span>
                <span class="badge badge-warning">{{ __('triproof.dashboard.map.legend_forged') }}</span>
                <span class="badge badge-info">{{ __('triproof.dashboard.map.legend_recall') }}</span>
                <span class="badge badge-neutral">{{ __('triproof.dashboard.map.legend_expired') }}</span>
            </div>
        </div>
        <div class="relative p-2.5">
            <div id="triproof-map" class="map-canvas" aria-label="{{ __('triproof.dashboard.map.title') }}"></div>
            @if ($markers === [])
                <div class="pointer-events-none absolute inset-2.5 flex items-center justify-center rounded-xl bg-white/55 backdrop-blur-[1px]">
                    <div class="rounded-2xl border border-slate-200 bg-white/90 px-5 py-4 text-center shadow-sm">
                        <x-icon name="map" class="mx-auto h-5 w-5 text-slate-400" />
                        <p class="mt-2 max-w-xs text-xs leading-5 text-slate-500">{{ __('triproof.dashboard.map.no_markers') }}</p>
                    </div>
                </div>
            @endif
        </div>
    </section>
</div>

<script type="application/json" id="triproof-chart-data">@json($chartPayload)</script>
<script type="application/json" id="triproof-map-markers">@json($mapPayload)</script>
