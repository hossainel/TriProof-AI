@if ($recalls->isEmpty())
    <div class="empty-state">
        <span class="empty-icon"><x-icon name="recall" class="h-6 w-6" /></span>
        <h3 class="text-sm font-semibold text-ink-950">{{ __('triproof.dashboard.recalls.empty') }}</h3>
        <p class="mt-1 max-w-sm text-xs leading-5 text-slate-500">{{ __('triproof.dashboard.recalls.empty_hint') }}</p>
    </div>
@else
    <div class="table-shell">
        <table class="data-table min-w-[680px]">
            <thead>
                <tr>
                    <th>{{ __('triproof.dashboard.recalls.batch') }}</th>
                    <th>{{ __('triproof.dashboard.batches.order') }}</th>
                    <th>{{ __('triproof.dashboard.recalls.affected') }}</th>
                    <th>{{ __('triproof.dashboard.recalls.reason') }}</th>
                    <th>{{ __('triproof.dashboard.recalls.by') }}</th>
                    <th>{{ __('triproof.dashboard.recalls.triggered') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recalls as $recall)
                    <tr>
                        <td>
                            <p class="font-mono text-[11px] font-semibold text-ink-950">
                                {{ $recall->factory_id }}-{{ $recall->machine_id }}-{{ $recall->batch_id }}
                            </p>
                        </td>
                        <td class="font-mono text-[11px] text-slate-500">{{ $recall->po?->po_number ?? '—' }}</td>
                        <td><span class="badge badge-danger">{{ number_format($recall->affected_count) }}</span></td>
                        <td class="max-w-xs text-xs leading-5 text-slate-600">{{ $recall->reason ?: '—' }}</td>
                        <td class="text-xs text-slate-600">{{ $recall->triggeredBy?->name ?? '—' }}</td>
                        <td>
                            <p class="text-[11px] text-slate-500">{{ $recall->created_at?->format('d M Y') }}</p>
                            <p class="text-[10px] text-slate-400">{{ $recall->created_at?->format('H:i') }}</p>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
