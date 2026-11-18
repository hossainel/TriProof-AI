@php $rework = session('rework_result'); @endphp

@if ($rework)
    @php
        $counts = $rework['counts'];
        $statusClasses = [
            'linked' => 'badge-success',
            'already_linked' => 'badge-info',
            'gap' => 'badge-warning',
            'rejected' => 'badge-danger',
        ];
    @endphp

    <div class="mt-5 space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="section-kicker">{{ __('triproof.dashboard.rework_result_title') }}</p>
                    <p class="mt-1 text-sm font-semibold text-ink-950">
                        {{ __('triproof.dashboard.rework_counts', [
                            'linked' => $counts['linked'],
                            'gaps' => $counts['gaps'],
                            'rejected' => $counts['rejected'],
                            'ocr' => $counts['ocr_used'],
                        ]) }}
                    </p>
                </div>
                <span class="badge {{ $rework['ai_generated'] ? 'badge-success' : 'badge-neutral' }}">
                    <x-icon :name="$rework['ai_generated'] ? 'sparkles' : 'file'" class="h-3 w-3" />
                    {{ $rework['ai_generated'] ? __('triproof.dashboard.rework_ai') : __('triproof.dashboard.rework_no_ai') }}
                </span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-3">
                    <p class="text-[10px] font-bold tracking-[0.12em] text-slate-400 uppercase">{{ __('triproof.dashboard.rework_summary') }} · English</p>
                    <p class="mt-1.5 text-xs leading-5 text-slate-600">{{ $rework['summary_en'] }}</p>
                </div>
                <div class="rounded-xl bg-brand-50/60 p-3">
                    <p class="text-[10px] font-bold tracking-[0.12em] text-brand-700/70 uppercase">{{ __('triproof.dashboard.rework_summary') }} · বাংলা</p>
                    <p class="font-bengali mt-1.5 text-xs leading-6 text-slate-700">{{ $rework['summary_bn'] }}</p>
                </div>
            </div>
        </div>

        @if ($rework['results'] !== [])
            <details class="card overflow-hidden" open>
                <summary class="cursor-pointer border-b border-slate-100 px-5 py-4 text-sm font-semibold text-ink-950">
                    {{ __('triproof.dashboard.rework_result_title') }} ({{ count($rework['results']) }})
                </summary>
                <div class="table-shell">
                    <table class="data-table min-w-[900px]">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ __('triproof.dashboard.rework_status') }}</th>
                                <th>{{ __('triproof.dashboard.rework_parent') }}</th>
                                <th>{{ __('triproof.dashboard.rework_child') }}</th>
                                <th>{{ __('triproof.dashboard.rework_reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rework['results'] as $entry)
                                <tr>
                                    <td class="text-[11px] text-slate-400">{{ $entry['index'] + 1 }}</td>
                                    <td>
                                        <span class="badge {{ $statusClasses[$entry['status']] ?? 'badge-neutral' }}">
                                            {{ __('triproof.rework.'.$entry['status']) }}
                                        </span>
                                        @if ($entry['ocr'])
                                            <span class="badge badge-info mt-1">{{ __('triproof.dashboard.rework_ocr_used') }}</span>
                                        @endif
                                    </td>
                                    <td class="font-mono text-[10px] text-slate-600">{{ $entry['parent_outer_serial'] ?: '—' }}</td>
                                    <td class="font-mono text-[10px] text-slate-600">{{ $entry['child_outer_serial'] ?: '—' }}</td>
                                    <td class="max-w-sm">
                                        <p class="text-[11px] leading-5 text-slate-600">{{ $entry['reason_en'] }}</p>
                                        <p class="font-bengali text-[11px] leading-5 text-slate-500">{{ $entry['reason_bn'] }}</p>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rework['results_truncated'])
                    <p class="border-t border-slate-100 px-5 py-3 text-[11px] text-slate-400">{{ __('triproof.dashboard.rework_results_truncated') }}</p>
                @endif
            </details>
        @endif
    </div>
@endif
