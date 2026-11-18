@if ($anomalies->isEmpty())
    <div class="empty-state">
        <span class="empty-icon"><x-icon name="anomalies" class="h-6 w-6" /></span>
        <h3 class="text-sm font-semibold text-ink-950">{{ __('triproof.dashboard.anomalies.empty') }}</h3>
        <p class="mt-1 max-w-md text-xs leading-5 text-slate-500">{{ __('triproof.dashboard.anomalies.empty_hint') }}</p>
    </div>
@else
    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ($anomalies as $report)
            @php
                $affected = $report->affected_units_json ?? [];
                $count = (int) ($affected['count'] ?? count($affected['serials'] ?? []));
                $rawSeverity = strtolower((string) ($affected['ai_severity'] ?? ''));
                $severity = in_array($rawSeverity, ['low', 'medium', 'high', 'critical'], true)
                    ? $rawSeverity
                    : (in_array($report->anomaly_type->value, ['clone_cluster', 'ghost_production_attempt'], true) ? 'high' : 'medium');
                $severityClass = match ($severity) {
                    'high', 'critical' => 'badge-danger',
                    'low' => 'badge-neutral',
                    default => 'badge-warning',
                };
                $accent = match ($severity) {
                    'high', 'critical' => 'border-l-rose-500',
                    'low' => 'border-l-slate-300',
                    default => 'border-l-amber-400',
                };
                $serials = collect($affected['serials'] ?? [])
                    ->map(fn ($entry) => is_array($entry) ? ($entry['outer_serial'] ?? null) : $entry)
                    ->filter()
                    ->take(6);
                $actionEn = $affected['recommended_action_en'] ?? null;
                $actionBn = $affected['recommended_action_bn'] ?? null;
                $aiGenerated = (bool) ($affected['ai_generated'] ?? false);
            @endphp
            <article class="card overflow-hidden border-l-4 {{ $accent }}">
                <div class="flex flex-wrap items-start justify-between gap-3 p-5">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="badge badge-info">{{ __('triproof.ai.types.'.$report->anomaly_type->value) }}</span>
                            <span class="badge {{ $severityClass }}">{{ __('triproof.ai.severity') }}: {{ __('triproof.ai.severity_levels.'.$severity) }}</span>
                            <span class="badge {{ $aiGenerated ? 'badge-success' : 'badge-neutral' }}">
                                <x-icon :name="$aiGenerated ? 'sparkles' : 'file'" class="h-3 w-3" />
                                {{ $aiGenerated ? __('triproof.ai.ai_generated') : __('triproof.ai.template_generated') }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm font-semibold text-ink-950">
                            {{ __('triproof.dashboard.anomalies.count', ['count' => $count]) }}
                        </p>
                        <p class="mt-0.5 font-mono text-[10px] text-slate-400">
                            {{ $report->batch_id ?: '—' }}
                            @if ($report->po)
                                · {{ $report->po->po_number }}
                            @endif
                        </p>
                    </div>
                    <span class="badge badge-danger">{{ __('triproof.anomaly.open') }}</span>
                </div>

                <div class="space-y-4 border-t border-slate-100 px-5 py-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-[10px] font-bold tracking-[0.12em] text-slate-400 uppercase">English</p>
                            <p class="mt-1.5 text-xs leading-5 text-slate-600">{{ $report->explanation_en }}</p>
                        </div>
                        <div class="rounded-xl bg-brand-50/60 p-3">
                            <p class="text-[10px] font-bold tracking-[0.12em] text-brand-700/70 uppercase">বাংলা</p>
                            <p class="font-bengali mt-1.5 text-xs leading-6 text-slate-700">{{ $report->explanation_bn }}</p>
                        </div>
                    </div>

                    @if ($actionEn || $actionBn)
                        <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-3">
                            <p class="flex items-center gap-1.5 text-[10px] font-bold tracking-[0.12em] text-amber-800 uppercase">
                                <x-icon name="check" class="h-3.5 w-3.5" />
                                {{ __('triproof.dashboard.anomalies.recommended') }}
                            </p>
                            @if ($actionEn)
                                <p class="mt-1.5 text-xs leading-5 text-amber-950">{{ $actionEn }}</p>
                            @endif
                            @if ($actionBn)
                                <p class="font-bengali mt-1 text-xs leading-6 text-amber-950/80">{{ $actionBn }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($serials->isNotEmpty())
                        <details class="group">
                            <summary class="cursor-pointer text-[11px] font-semibold text-slate-500 hover:text-ink-950">
                                {{ __('triproof.dashboard.anomalies.sample_serials') }} ({{ $serials->count() }})
                            </summary>
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($serials as $serial)
                                    <code class="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[9px] text-slate-600">{{ $serial }}</code>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
@endif
