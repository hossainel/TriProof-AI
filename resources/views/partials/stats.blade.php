@php
    $quota = max(0, (int) $totals['authorized_quota']);
    $signed = max(0, (int) $totals['generated']);
    $signedPct = $quota > 0 ? min(100, (int) round(($signed / $quota) * 100)) : 0;
@endphp

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
    <div class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <p class="text-[10px] font-bold tracking-[0.14em] text-slate-500 uppercase">{{ __('triproof.dashboard.stats.authorized_quota') }}</p>
            <span class="stat-icon"><x-icon name="orders" class="h-4 w-4" /></span>
        </div>
        <p class="stat-value">{{ number_format($quota) }}</p>
        <p class="hint">{{ __('triproof.dashboard.stats.quota_sub', ['count' => $totals['order_count']]) }}</p>
    </div>

    <div class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <p class="text-[10px] font-bold tracking-[0.14em] text-slate-500 uppercase">{{ __('triproof.dashboard.stats.signed') }}</p>
            <span class="stat-icon bg-brand-50 text-brand-700"><x-icon name="verify" class="h-4 w-4" /></span>
        </div>
        <p class="stat-value">{{ number_format($signed) }}</p>
        <div class="mt-2 flex items-center gap-2">
            <div class="progress-track flex-1"><div class="progress-bar" style="width: {{ $signedPct }}%"></div></div>
            <span class="text-[10px] font-bold text-slate-500">{{ $signedPct }}%</span>
        </div>
        <p class="hint mt-1.5">{{ __('triproof.dashboard.stats.signed_sub', ['percent' => $signedPct]) }}</p>
    </div>

    <div class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <p class="text-[10px] font-bold tracking-[0.14em] text-slate-500 uppercase">{{ __('triproof.dashboard.stats.packed') }}</p>
            <span class="stat-icon bg-blue-50 text-blue-600"><x-icon name="batches" class="h-4 w-4" /></span>
        </div>
        <p class="stat-value">{{ number_format($totals['packed']) }}</p>
        <p class="hint">{{ __('triproof.dashboard.stats.packed_sub') }}</p>
    </div>

    <div class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <p class="text-[10px] font-bold tracking-[0.14em] text-slate-500 uppercase">{{ __('triproof.dashboard.stats.genuine') }}</p>
            <span class="stat-icon bg-emerald-50 text-emerald-700"><x-icon name="check-circle" class="h-4 w-4" /></span>
        </div>
        <p class="stat-value">{{ number_format($totals['claimed']) }}</p>
        <p class="hint">{{ __('triproof.dashboard.stats.genuine_sub') }}</p>
    </div>

    <div class="stat-card border-rose-200/80 bg-rose-50/55">
        <div class="flex items-start justify-between gap-3">
            <p class="text-[10px] font-bold tracking-[0.14em] text-rose-700 uppercase">{{ __('triproof.dashboard.stats.clone_flags') }}</p>
            <span class="stat-icon bg-rose-100 text-rose-600"><x-icon name="alert" class="h-4 w-4" /></span>
        </div>
        <div class="mt-3 grid grid-cols-2 gap-3">
            <div>
                <p class="text-2xl font-semibold tracking-tight text-rose-700 tabular-nums">{{ number_format($totals['cloned']) }}</p>
                <p class="text-[10px] font-medium text-rose-600/80">{{ __('triproof.dashboard.stats.clone_flags') }}</p>
            </div>
            <div>
                <p class="text-2xl font-semibold tracking-tight text-rose-700 tabular-nums">{{ number_format($totals['recalled']) }}</p>
                <p class="text-[10px] font-medium text-rose-600/80">{{ __('triproof.dashboard.stats.recalled') }}</p>
            </div>
        </div>
        <div class="mt-3 flex items-center justify-between gap-2">
            <span class="hint text-rose-700/70">{{ __('triproof.dashboard.stats.risk_sub') }}</span>
            @if ($anomalies->isNotEmpty())
                <span class="badge badge-danger">{{ $anomalies->count() }}</span>
            @endif
        </div>
    </div>
</div>
