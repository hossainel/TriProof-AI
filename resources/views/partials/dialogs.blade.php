@php
    $user = auth()->user();
    $canCreate = $user->isBuyer() || $user->isAdmin();
    $createDisabled = $factories->isEmpty() || ($user->isAdmin() && $buyers->isEmpty());
    $maxGenerate = (int) config('triproof.exports.max_generate', 50000);
    $defaultFactoryDays = (int) ($platformSettings['default_factory_expiry_days'] ?? config('triproof.factory.default_expiry_days'));
    $defaultRetailDays = (int) ($platformSettings['default_retail_expiry_days'] ?? config('triproof.factory.default_retail_expiry_days'));
@endphp

@if ($canCreate)
    <dialog id="create-order-dialog" class="tp-dialog" aria-labelledby="create-order-title">
        <div class="tp-dialog-panel">
            <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>

            <div class="border-b border-slate-100 px-6 pt-6 pb-4">
                <span class="badge badge-success">{{ __('triproof.dashboard.create_order') }}</span>
                <h2 id="create-order-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.forms.create_order.title') }}</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('triproof.forms.create_order.subtitle') }}</p>
            </div>

            <form method="POST" action="{{ route('orders.store') }}" class="space-y-4 p-6">
                @csrf
                <input type="hidden" name="dialog_id" value="create-order-dialog">

                @php($isCurrentDialog = old('dialog_id') === 'create-order-dialog')

                @if ($isCurrentDialog && $errors->any())
                    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-5 text-rose-700">
                        {{ __('triproof.common.validation_errors') }}
                        <ul class="mt-1 list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($user->isAdmin())
                    <div>
                        <label for="create-buyer" class="label">{{ __('triproof.forms.create_order.buyer') }}</label>
                        <select id="create-buyer" name="buyer_id" class="select" required>
                            <option value="">{{ __('triproof.forms.create_order.select_buyer') }}</option>
                            @foreach ($buyers as $buyer)
                                <option value="{{ $buyer->id }}">{{ $buyer->name }} · {{ $buyer->email }}</option>
                            @endforeach
                        </select>
                        @if ($buyers->isEmpty())
                            <p class="hint mt-1 text-rose-600">{{ __('triproof.forms.create_order.no_buyers') }}</p>
                        @endif
                    </div>
                @endif

                <div>
                    <label for="create-factory" class="label">{{ __('triproof.forms.create_order.factory') }}</label>
                    <select id="create-factory" name="factory_id" class="select" required>
                        <option value="">{{ __('triproof.forms.create_order.select_factory') }}</option>
                        @foreach ($factories as $factory)
                            <option value="{{ $factory->code }}">{{ $factory->code }} · {{ $factory->name_en }}</option>
                        @endforeach
                    </select>
                    @if ($factories->isEmpty())
                        <p class="hint mt-1 text-rose-600">
                            {{ __('triproof.forms.create_order.no_factories') }}
                            <a href="{{ route('admin.manage') }}#admin-factories" class="font-medium hover:underline">
                                {{ __('triproof.forms.create_order.register_factory') }}
                            </a>
                        </p>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="create-po-number" class="label">{{ __('triproof.forms.create_order.po_number') }}</label>
                        <input id="create-po-number" name="po_number" type="text" class="input" maxlength="64" placeholder="PO-2026-001" value="{{ $isCurrentDialog ? old('po_number') : '' }}">
                        <p class="hint mt-1">{{ __('triproof.forms.create_order.po_number_hint') }}</p>
                    </div>
                    <div>
                        <label for="create-quota" class="label">{{ __('triproof.forms.create_order.authorized_quota') }}</label>
                        <input id="create-quota" name="authorized_quota" type="number" class="input" min="1" max="1000000" value="{{ $isCurrentDialog ? old('authorized_quota', 100) : 100 }}" required>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="create-factory-days" class="label">{{ __('triproof.forms.create_order.factory_window') }}</label>
                        <div class="relative">
                            <input id="create-factory-days" name="factory_expiry_days" type="number" class="input pr-12" min="1" max="365" value="{{ $isCurrentDialog ? old('factory_expiry_days', $defaultFactoryDays) : $defaultFactoryDays }}" required>
                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-slate-400">{{ __('triproof.forms.create_order.days') }}</span>
                        </div>
                    </div>
                    <div>
                        <label for="create-retail-days" class="label">{{ __('triproof.forms.create_order.retail_expiry') }}</label>
                        <div class="relative">
                            <input id="create-retail-days" name="retail_expiry_days" type="number" class="input pr-12" min="1" max="3650" value="{{ $isCurrentDialog ? old('retail_expiry_days', $defaultRetailDays) : $defaultRetailDays }}" required>
                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-slate-400">{{ __('triproof.forms.create_order.days') }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary" @disabled($createDisabled)>
                        {{ __('triproof.forms.create_order.submit') }}
                    </button>
                </div>
            </form>
        </div>
    </dialog>
@endif

<dialog
    id="generate-dialog"
    class="tp-dialog"
    aria-labelledby="generate-title"
    data-count-template="{{ __('triproof.forms.generate.submit', ['count' => '__COUNT__']) }}"
    data-remaining-template="{{ __('triproof.forms.generate.remaining', ['count' => '__COUNT__']) }}"
>
    <div class="tp-dialog-panel">
        <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
            <x-icon name="close" class="h-5 w-5" />
        </button>

        <div class="border-b border-slate-100 px-6 pt-6 pb-4">
            <span class="badge badge-success">{{ __('triproof.orders.generate') }}</span>
            <h2 id="generate-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.forms.generate.title') }}</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('triproof.forms.generate.subtitle') }}</p>
            <div class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-[11px] text-slate-500">
                <span class="font-semibold text-slate-600">{{ __('triproof.forms.generate.context') }}:</span>
                <span class="font-mono" data-dialog-context>—</span>
            </div>
        </div>

        <form id="generate-form" method="POST" action="#" data-dynamic-action class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="dialog_id" value="generate-dialog">

            @php($isCurrentDialog = old('dialog_id') === 'generate-dialog')

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="generate-machine" class="label">{{ __('triproof.forms.generate.machine') }}</label>
                    <input id="generate-machine" name="machine_id" type="text" class="input" maxlength="16" placeholder="M12" required autocomplete="off">
                    <p class="hint mt-1">{{ __('triproof.forms.generate.machine_hint') }}</p>
                </div>
                <div>
                    <label for="generate-batch" class="label">{{ __('triproof.forms.generate.batch') }}</label>
                    <input id="generate-batch" name="batch_id" type="text" class="input" maxlength="16" placeholder="B09" required autocomplete="off">
                    <p class="hint mt-1">{{ __('triproof.forms.generate.batch_hint') }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="generate-start" class="label">{{ __('triproof.forms.generate.unit_start') }}</label>
                    <input id="generate-start" name="unit_start" type="number" class="input" min="1" max="999999" placeholder="1">
                    <p class="hint mt-1">{{ __('triproof.forms.generate.unit_start_hint') }}</p>
                </div>
                <div>
                    <label for="generate-count" class="label">{{ __('triproof.forms.generate.count') }}</label>
                    <input id="generate-count" name="count" type="number" class="input" min="1" max="{{ $maxGenerate }}" value="{{ $isCurrentDialog ? old('count', 1) : 1 }}" required>
                    <p class="hint mt-1">{{ __('triproof.forms.generate.count_hint') }}</p>
                </div>
                <div>
                    <label for="generate-date" class="label">{{ __('triproof.forms.generate.prod_date') }}</label>
                    <input id="generate-date" name="prod_date" type="date" class="input" value="{{ $isCurrentDialog ? old('prod_date') : now()->toDateString() }}">
                </div>
            </div>

            <p class="text-xs font-semibold text-brand-800" data-remaining-label></p>
            <p class="hint flex items-start gap-2">
                <x-icon name="lock" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                {{ __('triproof.forms.generate.quota_note') }}
            </p>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                <button type="submit" class="btn btn-primary" data-submit-label>
                    {{ __('triproof.forms.generate.submit', ['count' => 0]) }}
                </button>
            </div>
        </form>
    </div>
</dialog>

<dialog id="pack-dialog" class="tp-dialog" aria-labelledby="pack-title">
    <div class="tp-dialog-panel">
        <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
            <x-icon name="close" class="h-5 w-5" />
        </button>

        <div class="border-b border-slate-100 px-6 pt-6 pb-4">
            <span class="badge badge-info">{{ __('triproof.orders.pack') }}</span>
            <h2 id="pack-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.forms.pack.title') }}</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('triproof.forms.pack.subtitle') }}</p>
            <div class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-[11px] text-slate-500">
                <span class="font-semibold text-slate-600">{{ __('triproof.forms.pack.context') }}:</span>
                <span class="font-mono" data-dialog-context>—</span>
            </div>
        </div>

        <form method="POST" action="#" data-dynamic-action class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="dialog_id" value="pack-dialog">

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="pack-machine" class="label">{{ __('triproof.forms.pack.machine') }}</label>
                    <input id="pack-machine" name="machine_id" type="text" class="input" maxlength="32" required autocomplete="off">
                </div>
                <div>
                    <label for="pack-batch" class="label">{{ __('triproof.forms.pack.batch') }}</label>
                    <input id="pack-batch" name="batch_id" type="text" class="input" maxlength="32" required autocomplete="off">
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="pack-start" class="label">{{ __('triproof.forms.pack.start') }}</label>
                    <input id="pack-start" name="unit_start" type="number" class="input" min="1" max="999999" placeholder="1">
                </div>
                <div>
                    <label for="pack-end" class="label">{{ __('triproof.forms.pack.end') }}</label>
                    <input id="pack-end" name="unit_end" type="number" class="input" min="1" max="999999" placeholder="100">
                </div>
            </div>
            <p class="hint">{{ __('triproof.forms.pack.range_hint') }}</p>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('triproof.forms.pack.submit') }}</button>
            </div>
        </form>
    </div>
</dialog>

<dialog id="recall-dialog" class="tp-dialog" aria-labelledby="recall-title">
    <div class="tp-dialog-panel">
        <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
            <x-icon name="close" class="h-5 w-5" />
        </button>

        <div class="border-b border-slate-100 px-6 pt-6 pb-4">
            <span class="badge badge-danger">{{ __('triproof.dashboard.recall') }}</span>
            <h2 id="recall-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.forms.recall.title') }}</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('triproof.forms.recall.subtitle') }}</p>
            <div class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-[11px] text-slate-500">
                <span class="font-semibold text-slate-600">{{ __('triproof.common.details') }}:</span>
                <span class="font-mono" data-dialog-context>—</span>
            </div>
        </div>

        <form method="POST" action="{{ route('recall.trigger') }}" class="space-y-4 p-6" data-confirm="{{ __('triproof.forms.recall.confirm') }}">
            @csrf
            <input type="hidden" name="dialog_id" value="recall-dialog">

            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-5 text-rose-700">
                {{ __('triproof.forms.recall.warning') }}
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="recall-factory" class="label">{{ __('triproof.forms.recall.factory') }}</label>
                    <input id="recall-factory" name="factory_id" type="text" class="input" maxlength="32" required autocomplete="off">
                </div>
                <div>
                    <label for="recall-machine" class="label">{{ __('triproof.forms.recall.machine') }}</label>
                    <input id="recall-machine" name="machine_id" type="text" class="input" maxlength="32" required autocomplete="off">
                </div>
                <div>
                    <label for="recall-batch" class="label">{{ __('triproof.forms.recall.batch') }}</label>
                    <input id="recall-batch" name="batch_id" type="text" class="input" maxlength="32" required autocomplete="off">
                </div>
            </div>

            <div>
                <label for="recall-reason" class="label">{{ __('triproof.forms.recall.reason') }}</label>
                <textarea id="recall-reason" name="reason" class="textarea" maxlength="1000" rows="3"></textarea>
                <p class="hint mt-1">{{ __('triproof.forms.recall.reason_hint') }}</p>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                <button type="submit" class="btn btn-danger">{{ __('triproof.forms.recall.submit') }}</button>
            </div>
        </form>
    </div>
</dialog>

<dialog
    id="merkle-dialog"
    class="tp-dialog"
    aria-labelledby="merkle-title"
    data-no-root-label="{{ __('triproof.forms.merkle.no_root') }}"
    data-match-label="{{ __('triproof.forms.merkle.matches') }}"
    data-mismatch-label="{{ __('triproof.forms.merkle.mismatch') }}"
    data-consistent-label="{{ __('triproof.forms.merkle.consistent') }}"
    data-inconsistent-label="{{ __('triproof.forms.merkle.inconsistent') }}"
    data-error-label="{{ __('triproof.forms.merkle.error') }}"
>
    <div class="tp-dialog-panel">
        <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
            <x-icon name="close" class="h-5 w-5" />
        </button>

        <div class="border-b border-slate-100 px-6 pt-6 pb-4">
            <span class="badge badge-info">{{ __('triproof.orders.merkle') }}</span>
            <h2 id="merkle-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.forms.merkle.title') }}</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('triproof.forms.merkle.subtitle') }}</p>
            <div class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-[11px] text-slate-500">
                <span class="font-mono" data-dialog-context>—</span>
            </div>
        </div>

        <div class="p-6">
            <div data-merkle-loading class="hidden">
                <div class="flex items-center justify-center gap-2 py-8 text-xs text-slate-500">
                    <span class="h-4 w-4 animate-spin rounded-full border-2 border-slate-200 border-t-brand-600"></span>
                    {{ __('triproof.forms.merkle.checking') }}
                </div>
            </div>

            <div data-merkle-error class="hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700"></div>

            <div data-merkle-body class="hidden space-y-4">
                <div class="flex flex-wrap gap-2">
                    <span class="badge badge-neutral" data-merkle-matches>—</span>
                    <span class="badge badge-neutral" data-merkle-consistent>—</span>
                </div>

                <div>
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[10px] font-bold tracking-[0.12em] text-slate-500 uppercase">{{ __('triproof.forms.merkle.stored') }}</p>
                        <button type="button" class="btn btn-ghost btn-xs" data-copy-target="#merkle-stored" data-copied-label="{{ __('triproof.common.copied') }}">
                            {{ __('triproof.common.copy') }}
                        </button>
                    </div>
                    <code id="merkle-stored" class="code-block mt-1.5 block" data-merkle-stored>—</code>
                </div>

                <div>
                    <p class="text-[10px] font-bold tracking-[0.12em] text-slate-500 uppercase">{{ __('triproof.forms.merkle.recomputed') }}</p>
                    <code class="code-block mt-1.5 block" data-merkle-recomputed>—</code>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-[10px] text-slate-400 uppercase">{{ __('triproof.forms.merkle.leaf_count') }}</p>
                        <p class="mt-1 text-sm font-semibold text-ink-950 tabular-nums" data-merkle-leaves>0</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-[10px] text-slate-400 uppercase">{{ __('triproof.forms.merkle.generated_count') }}</p>
                        <p class="mt-1 text-sm font-semibold text-ink-950 tabular-nums" data-merkle-generated>0</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-[10px] text-slate-400 uppercase">{{ __('triproof.forms.merkle.algorithm') }}</p>
                        <p class="mt-1 text-[11px] leading-4 text-slate-600" data-merkle-algorithm>—</p>
                    </div>
                </div>

                @if (! empty($anchors) && $anchors->isNotEmpty())
                    <div>
                        <p class="text-[10px] font-bold tracking-[0.12em] text-slate-500 uppercase">External anchor receipts</p>
                        <div class="mt-2 space-y-2">
                            @foreach ($anchors as $anchor)
                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="badge {{ $anchor->status === 'verified' ? 'badge-success' : 'badge-neutral' }}">{{ $anchor->provider }} · {{ $anchor->status }}</span>
                                        <span class="text-[10px] text-slate-400">{{ $anchor->anchor_date?->format('d M Y') }}</span>
                                    </div>
                                    <code class="code-block mt-2 block break-all">{{ $anchor->tx_hash_or_receipt }}</code>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-4 flex justify-end border-t border-slate-100 pt-4">
                <button type="button" class="btn btn-secondary" data-close-dialog>{{ __('triproof.common.close') }}</button>
            </div>
        </div>
    </div>
</dialog>
