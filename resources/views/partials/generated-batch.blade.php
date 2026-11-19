@if (session('generated'))
    @php $generated = session('generated'); @endphp
    <div class="card mb-5 overflow-hidden border-brand-200 bg-gradient-to-br from-brand-50 via-white to-emerald-50">
        <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
            <div>
                <span class="badge badge-success">
                    <x-icon name="check-circle" class="h-3.5 w-3.5" />
                    {{ __('triproof.dashboard.generated_title') }}
                </span>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-ink-950">
                    {{ __('triproof.dashboard.generated_body', ['count' => number_format($generated['count']), 'start' => $generated['unit_start'], 'end' => $generated['unit_end']]) }}
                </h2>
                <p class="mt-1 font-mono text-[11px] text-slate-500">{{ $generated['po_number'] }} · {{ $generated['batch'] }}</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('orders.export', ['po' => $generated['po_id'], 'format' => 'csv', 'batch' => $generated['batch']]) }}" class="btn btn-secondary btn-xs">
                        <x-icon name="download" class="h-3.5 w-3.5" />
                        CSV
                    </a>
                    <a href="{{ route('orders.export', ['po' => $generated['po_id'], 'format' => 'pdf', 'batch' => $generated['batch']]) }}" class="btn btn-secondary btn-xs">
                        <x-icon name="download" class="h-3.5 w-3.5" />
                        PDF
                    </a>
                    <a
                        href="{{ route('orders.qr-stickers', ['po' => $generated['po_id'], 'batch' => $generated['batch'], 'limit' => config('triproof.exports.qr_preview_pairs', 2)]) }}"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-primary btn-xs"
                    >
                        {{ __('triproof.orders.qr_preview_named') }}
                    </a>
                </div>
            </div>

            <div class="rounded-xl border border-brand-200/70 bg-white/70 p-3.5">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-[10px] font-bold tracking-[0.12em] text-slate-500 uppercase">{{ __('triproof.dashboard.generated_root') }}</p>
                    <button
                        type="button"
                        class="btn-icon h-7 w-7"
                        data-copy-target="#generated-merkle-root"
                        data-copied-label="{{ __('triproof.common.copied') }}"
                        title="{{ __('triproof.common.copy') }}"
                    >
                        <x-icon name="copy" class="h-3.5 w-3.5" />
                    </button>
                </div>
                <code id="generated-merkle-root" class="code-block mt-2 block">{{ $generated['merkle_root'] }}</code>
            </div>
        </div>
    </div>
@endif
