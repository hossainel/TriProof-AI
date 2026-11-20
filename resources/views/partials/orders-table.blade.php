@if ($orders->isEmpty())
    <div class="empty-state">
        <span class="empty-icon"><x-icon name="orders" class="h-6 w-6" /></span>
        <h3 class="text-sm font-semibold text-ink-950">{{ __('triproof.orders.empty') }}</h3>
        <p class="mt-1 max-w-sm text-xs leading-5 text-slate-500">{{ $emptyMessage }}</p>
    </div>
@else
    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('triproof.orders.po') }}</th>
                    @if ($showBuyer)
                        <th>{{ __('triproof.orders.buyer') }}</th>
                    @endif
                    <th>{{ __('triproof.orders.factory') }}</th>
                    <th class="min-w-[170px]">{{ __('triproof.orders.progress') }}</th>
                    <th>{{ __('triproof.orders.deadline') }}</th>
                    <th>{{ __('triproof.orders.risk') }}</th>
                    <th class="min-w-[260px]">{{ __('triproof.orders.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $order)
                    @php
                        $authorized = max(1, (int) $order->authorized_quota);
                        $progress = min(100, (int) round(((int) $order->generated_count / $authorized) * 100));
                        $deadline = $order->factoryDeadline();
                        $overdue = now()->greaterThan($deadline);
                        $remaining = $order->remainingQuota();
                        $context = $order->po_number.' · '.$order->factory_id;
                    @endphp
                    <tr>
                        <td>
                            <p class="font-mono text-xs font-semibold text-ink-950">{{ $order->po_number }}</p>
                            <p class="mt-0.5 text-[10px] text-slate-400">{{ __('triproof.orders.created') }} {{ $order->created_at?->format('d M Y') }}</p>
                        </td>
                        @if ($showBuyer)
                            <td>
                                <p class="text-xs font-medium text-slate-700">{{ $order->buyer?->name ?? '—' }}</p>
                                <p class="text-[10px] text-slate-400">{{ $order->buyer?->email }}</p>
                            </td>
                        @endif
                        <td><span class="badge badge-neutral">{{ $order->factory_id }}</span></td>
                        <td>
                            <div class="flex items-center justify-between gap-2 text-[10px] font-medium text-slate-500">
                                <span>{{ __('triproof.orders.signed_of', ['signed' => $order->generated_count, 'authorized' => $order->authorized_quota]) }}</span>
                                <span class="font-bold text-slate-600">{{ $progress }}%</span>
                            </div>
                            <div class="progress-track mt-1.5"><div class="progress-bar" style="width: {{ $progress }}%"></div></div>
                            <p class="mt-1 text-[10px] text-slate-400">{{ __('triproof.orders.remaining', ['count' => $remaining]) }}</p>
                        </td>
                        <td>
                            <p class="text-xs font-medium {{ $overdue ? 'text-rose-600' : 'text-slate-600' }}">{{ $deadline->format('d M Y') }}</p>
                            <span class="badge mt-1 {{ $overdue ? 'badge-danger' : 'badge-neutral' }}">
                                {{ $overdue ? __('triproof.orders.overdue') : __('triproof.orders.days_left', ['count' => max(0, (int) ceil(now()->diffInDays($deadline, false)))]) }}
                            </span>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <span class="badge {{ $order->cloned_units > 0 ? 'badge-danger' : 'badge-success' }}">
                                    {{ __('triproof.orders.cloned') }} {{ $order->cloned_units }}
                                </span>
                                <span class="badge {{ $order->recalled_units > 0 ? 'badge-danger' : 'badge-neutral' }}">
                                    {{ __('triproof.orders.recalled') }} {{ $order->recalled_units }}
                                </span>
                            </div>
                            <p class="mt-1 text-[10px] text-slate-400">{{ __('triproof.orders.genuine') }} {{ $order->claimed_units }}</p>
                        </td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if ($canGenerate && $remaining > 0)
                                    <button
                                        type="button"
                                        class="btn btn-dark btn-xs"
                                        data-open-dialog="generate-dialog"
                                        data-action="{{ route('orders.generate', $order) }}"
                                        data-context="{{ $context }}"
                                        data-remaining="{{ $remaining }}"
                                    >
                                        <x-icon name="plus" class="h-3.5 w-3.5" />
                                        {{ __('triproof.orders.generate') }}
                                    </button>
                                @endif

                                @if ($canPack && $order->total_units > 0)
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-xs"
                                        data-open-dialog="pack-dialog"
                                        data-action="{{ route('orders.pack', $order) }}"
                                        data-context="{{ $context }}"
                                        data-order-id="{{ $order->id }}"
                                    >
                                        <x-icon name="upload" class="h-3.5 w-3.5" />
                                        {{ __('triproof.orders.pack') }}
                                    </button>
                                @endif

                                @if ($order->total_units > 0)
                                    <a
                                        href="{{ route('orders.qr-stickers', ['po' => $order, 'limit' => config('triproof.exports.qr_preview_pairs', 2)]) }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="btn btn-secondary btn-xs"
                                        title="{{ __('triproof.orders.qr_preview') }}"
                                        aria-label="{{ __('triproof.orders.qr_preview') }}"
                                    >
                                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path d="M3 3h7v7H3V3Zm2 2v3h3V5H5Zm9-2h7v7h-7V3Zm2 2v3h3V5h-3ZM3 14h7v7H3v-7Zm2 2v3h3v-3H5Zm9-2h2v2h-2v-2Zm4 0h2v2h-2v-2Zm-4 4h2v3h-2v-3Zm4 0h2v3h-2v-3Z" />
                                        </svg>
                                        QR
                                    </a>
                                    <a href="{{ route('orders.qr-stickers', ['po' => $order]) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-xs" title="{{ __('triproof.orders.qr_all') }}">
                                        {{ __('triproof.orders.qr_all') }}
                                    </a>
                                    <a href="{{ route('orders.export', ['po' => $order, 'format' => 'csv']) }}" class="btn btn-ghost btn-xs" title="{{ __('triproof.orders.export_csv') }}">
                                        <x-icon name="download" class="h-3.5 w-3.5" />
                                        {{ __('triproof.orders.export_csv') }}
                                    </a>
                                    <a href="{{ route('orders.export', ['po' => $order, 'format' => 'pdf']) }}" class="btn btn-ghost btn-xs" title="{{ __('triproof.orders.export_pdf') }}">
                                        {{ __('triproof.orders.export_pdf') }}
                                    </a>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-xs"
                                        data-open-dialog="merkle-dialog"
                                        data-context="{{ $context }}"
                                        data-merkle-url="{{ route('orders.merkle', $order) }}"
                                    >
                                        <x-icon name="database" class="h-3.5 w-3.5" />
                                        {{ __('triproof.orders.merkle') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-xs text-rose-600 hover:bg-rose-50"
                                        data-open-dialog="recall-dialog"
                                        data-factory="{{ $order->factory_id }}"
                                        data-reset-scope
                                        data-context="{{ $context }}"
                                    >
                                        <x-icon name="recall" class="h-3.5 w-3.5" />
                                        {{ __('triproof.orders.recall') }}
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
