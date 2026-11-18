@if ($batches->isEmpty())
    <div class="empty-state">
        <span class="empty-icon"><x-icon name="batches" class="h-6 w-6" /></span>
        <h3 class="text-sm font-semibold text-ink-950">{{ __('triproof.dashboard.batches.empty') }}</h3>
        <p class="mt-1 max-w-sm text-xs leading-5 text-slate-500">{{ __('triproof.dashboard.batches.empty_hint') }}</p>
    </div>
@else
    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('triproof.dashboard.batches.batch') }}</th>
                    <th>{{ __('triproof.dashboard.batches.order') }}</th>
                    <th>{{ __('triproof.dashboard.batches.units') }}</th>
                    <th>{{ __('triproof.dashboard.batches.packed') }}</th>
                    <th>{{ __('triproof.dashboard.batches.genuine') }}</th>
                    <th>{{ __('triproof.dashboard.batches.risk') }}</th>
                    <th>{{ __('triproof.dashboard.batches.latest') }}</th>
                    <th class="min-w-[240px]">{{ __('triproof.dashboard.batches.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($batches as $batch)
                    @php
                        $scope = "{$batch->factory_id}-{$batch->machine_id}-{$batch->batch_id}";
                        $risk = (int) $batch->cloned + (int) $batch->recalled;
                    @endphp
                    <tr>
                        <td>
                            <p class="font-mono text-[11px] font-semibold text-ink-950">{{ $scope }}</p>
                            <p class="mt-0.5 text-[10px] text-slate-400">{{ $batch->batch_id }}</p>
                        </td>
                        <td>
                            <p class="font-mono text-[11px] text-slate-600">{{ $batch->po_number }}</p>
                        </td>
                        <td class="text-xs font-semibold text-slate-700 tabular-nums">{{ number_format($batch->units) }}</td>
                        <td class="text-xs text-slate-600 tabular-nums">{{ number_format($batch->packed) }}</td>
                        <td class="text-xs text-slate-600 tabular-nums">{{ number_format($batch->claimed) }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <span class="badge {{ $batch->cloned > 0 ? 'badge-danger' : 'badge-success' }}">{{ __('triproof.orders.cloned') }} {{ $batch->cloned }}</span>
                                @if ($batch->recalled > 0)
                                    <span class="badge badge-danger">{{ __('triproof.orders.recalled') }} {{ $batch->recalled }}</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <p class="text-[11px] text-slate-500">{{ \Illuminate\Support\Carbon::parse($batch->latest_at)->format('d M Y') }}</p>
                            <p class="text-[10px] text-slate-400">{{ \Illuminate\Support\Carbon::parse($batch->latest_at)->format('H:i') }}</p>
                        </td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if ($canPack)
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-xs"
                                        data-open-dialog="pack-dialog"
                                        data-action="{{ route('orders.pack', $batch->po_id) }}"
                                        data-context="{{ $batch->po_number }} · {{ $scope }}"
                                        data-machine="{{ $batch->machine_id }}"
                                        data-batch="{{ $batch->batch_id }}"
                                    >
                                        <x-icon name="upload" class="h-3.5 w-3.5" />
                                        {{ __('triproof.orders.pack') }}
                                    </button>
                                @endif

                                <a
                                    href="{{ route('orders.export', ['po' => $batch->po_id, 'format' => 'csv', 'batch' => $batch->batch_id]) }}"
                                    class="btn btn-ghost btn-xs"
                                    title="{{ __('triproof.orders.export_csv') }}"
                                >
                                    <x-icon name="download" class="h-3.5 w-3.5" />
                                    CSV
                                </a>
                                <a
                                    href="{{ route('orders.export', ['po' => $batch->po_id, 'format' => 'pdf', 'batch' => $batch->batch_id]) }}"
                                    class="btn btn-ghost btn-xs"
                                    title="{{ __('triproof.orders.export_pdf') }}"
                                >
                                    PDF
                                </a>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-xs text-rose-600 hover:bg-rose-50"
                                    data-open-dialog="recall-dialog"
                                    data-factory="{{ $batch->factory_id }}"
                                    data-machine="{{ $batch->machine_id }}"
                                    data-batch="{{ $batch->batch_id }}"
                                    data-context="{{ $batch->po_number }} · {{ $scope }}"
                                >
                                    <x-icon name="recall" class="h-3.5 w-3.5" />
                                    {{ __('triproof.orders.recall') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
