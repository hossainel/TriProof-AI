@extends('layouts.app')

@section('page-title', __('triproof.verify.title'))

@php
    $visionEnabled = in_array(config('triproof.ai.provider'), ['openai', 'gemini'], true)
        && filled(config('triproof.ai.api_key'));

    $printOrders = auth()->user()?->isAdmin()
        ? \App\Models\PurchaseOrder::query()->where('generated_count', '>', 0)->latest()->limit(4)->get(['id', 'po_number'])
        : \App\Models\PurchaseOrder::query()->visibleTo(auth()->user())->where('generated_count', '>', 0)->latest()->limit(4)->get(['id', 'po_number']);

    $verdict = $result['verdict'] ?? null;
    $positive = in_array($verdict, ['verified_clean', 'owner_repeat_ok', 'store_grace_ok'], true);
    $critical = in_array($verdict, ['clone_detected', 'forged_signature', 'recalled_batch'], true);
    $tone = $positive
        ? ['panel' => 'border-brand-200 bg-brand-50/70', 'icon' => 'bg-brand-100 text-brand-700', 'badge' => 'badge-success']
        : ($critical
            ? ['panel' => 'border-rose-200 bg-rose-50/70', 'icon' => 'bg-rose-100 text-rose-600', 'badge' => 'badge-danger']
            : ['panel' => 'border-amber-200 bg-amber-50/70', 'icon' => 'bg-amber-100 text-amber-700', 'badge' => 'badge-warning']);
@endphp

@section('content')
    <section class="mb-5 overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <span class="badge badge-success">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                    {{ __('triproof.nav.secure') }}
                </span>
                <h2 class="mt-3 text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">{{ __('triproof.verify.title') }}</h2>
                <p class="mt-1.5 max-w-2xl text-sm leading-6 text-slate-500">{{ __('triproof.verify.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($printOrders as $printOrder)
                    <a href="{{ route('orders.qr-stickers', ['po' => $printOrder, 'limit' => config('triproof.exports.qr_preview_pairs', 2)]) }}" target="_blank" rel="noopener" class="btn btn-primary">
                        <x-icon name="download" class="h-4 w-4" />
                        {{ __('triproof.orders.qr_verifier_short', ['po' => $printOrder->po_number]) }}
                    </a>
                @endforeach
                <a href="{{ route('well-known.key') }}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">
                    <x-icon name="key" class="h-4 w-4" />
                    {{ __('triproof.nav.public_key') }}
                </a>
            </div>
        </div>
    </section>

    <div class="grid gap-5 xl:grid-cols-[0.82fr_1.18fr]">
        <section class="card overflow-hidden">
            <div class="card-header">
                <div>
                    <p class="section-kicker">{{ __('triproof.verify.outer_hint') }}</p>
                    <h2 class="section-title">{{ __('triproof.verify.result_title') }}</h2>
                </div>
                <span class="stat-icon"><x-icon name="search" class="h-4 w-4" /></span>
            </div>

            <form method="POST" action="{{ route('verify.check') }}" enctype="multipart/form-data" class="space-y-5 p-5 sm:p-6">
                @csrf

                <div>
                    <label for="verify-outer" class="label">{{ __('triproof.verify.outer') }}</label>
                    <input
                        id="verify-outer"
                        name="outer_serial"
                        type="text"
                        class="input font-mono text-[11px]"
                        value="{{ old('outer_serial', $outer) }}"
                        placeholder="FAC-01-LINE01-000001|20260101|20270101"
                        autocomplete="off"
                        spellcheck="false"
                    >
                    <p class="hint mt-1">{{ __('triproof.verify.outer_hint') }}</p>
                </div>

                <div>
                    <label for="verify-inner" class="label">{{ __('triproof.verify.inner') }}</label>
                    <textarea
                        id="verify-inner"
                        name="inner_serial"
                        class="textarea font-mono text-[10px]"
                        rows="4"
                        placeholder="Base64 signature"
                        spellcheck="false"
                    >{{ old('inner_serial', $inner) }}</textarea>
                    <p class="hint mt-1">{{ __('triproof.verify.inner_hint') }}</p>
                </div>

                <div class="relative rounded-2xl border border-dashed border-slate-300 bg-slate-50/70 p-4">
                    <div class="flex items-start gap-3">
                        <span class="stat-icon h-8 w-8 bg-white"><x-icon name="upload" class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1">
                            <label for="verify-image" class="label">{{ __('triproof.verify.image') }}</label>
                            <input
                                id="verify-image"
                                name="label_image"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="mt-1.5 block w-full text-[11px] text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-ink-900 file:px-3 file:py-1.5 file:text-[11px] file:font-semibold file:text-white"
                                @disabled(! $visionEnabled)
                            >
                            <p class="hint mt-1">{{ __('triproof.verify.image_hint') }}</p>
                            @unless ($visionEnabled)
                                <p class="mt-1 text-[11px] font-medium text-amber-700">{{ __('triproof.verify.ocr_unavailable') }}</p>
                            @endunless
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <x-icon name="verify" class="h-4 w-4" />
                    {{ __('triproof.verify.submit') }}
                </button>

                <p class="hint flex items-start gap-2 rounded-xl border border-slate-200 bg-white p-3">
                    <x-icon name="lock" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-700" />
                    {{ __('triproof.verify.readonly') }}
                </p>
            </form>
        </section>

        <section class="space-y-4">
            @if ($result)
                <div class="card overflow-hidden border {{ $tone['panel'] }}">
                    <div class="p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex items-start gap-3">
                                <span class="flex h-11 w-11 items-center justify-center rounded-2xl {{ $tone['icon'] }}">
                                    <x-icon :name="$positive ? 'check-circle' : ($critical ? 'alert' : 'clock')" class="h-6 w-6" />
                                </span>
                                <div>
                                    <p class="text-[10px] font-bold tracking-[0.14em] text-slate-500 uppercase">{{ __('triproof.verify.verdict') }}</p>
                                    <h3 class="mt-1 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.verdict.'.$verdict) }}</h3>
                                    <p class="mt-1 font-mono text-[10px] text-slate-500">{{ $result['outer_serial'] }}</p>
                                </div>
                            </div>
                            <span class="badge {{ $tone['badge'] }}">{{ $result['authentic'] ? __('triproof.verify.authentic') : __('triproof.verify.not_authentic') }}</span>
                        </div>

                        <div class="mt-4 space-y-2">
                            <p class="text-sm leading-6 font-medium text-slate-700">{{ $result['message_en'] }}</p>
                            <p class="font-bengali text-sm leading-7 text-slate-600">{{ $result['message_bn'] }}</p>
                        </div>
                    </div>

                    <div class="grid gap-2 border-t border-black/5 p-5 sm:grid-cols-2 sm:p-6">
                        @foreach ([
                            ['label' => __('triproof.verify.signature_valid'), 'value' => $result['signature_valid']],
                            ['label' => __('triproof.verify.hash_match'), 'value' => $result['hash_matches']],
                            ['label' => __('triproof.verify.registered'), 'value' => $result['registered']],
                            ['label' => __('triproof.verify.claimed'), 'value' => $result['claimed']],
                        ] as $check)
                            <div class="flex items-center justify-between gap-3 rounded-xl bg-white/70 px-3 py-2.5">
                                <span class="text-xs font-medium text-slate-600">{{ $check['label'] }}</span>
                                <span class="badge {{ $check['value'] ? 'badge-success' : 'badge-neutral' }}">
                                    <x-icon :name="$check['value'] ? 'check' : 'x'" class="h-3 w-3" />
                                    {{ $check['value'] ? __('triproof.common.yes') : __('triproof.common.no') }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <div class="border-t border-black/5 p-5 sm:p-6">
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                ['label' => __('triproof.verify.factory'), 'value' => $result['factory_id']],
                                ['label' => __('triproof.verify.machine'), 'value' => $result['machine_id']],
                                ['label' => __('triproof.verify.batch'), 'value' => $result['batch_id']],
                                ['label' => __('triproof.verify.unit'), 'value' => $result['unit_number'] !== null ? str_pad((string) $result['unit_number'], 6, '0', STR_PAD_LEFT) : null],
                                ['label' => __('triproof.verify.prod_date'), 'value' => $result['prod_date'] ? \Illuminate\Support\Carbon::parse($result['prod_date'])->format('d M Y') : null],
                                ['label' => __('triproof.verify.exp_date'), 'value' => $result['exp_date'] ? \Illuminate\Support\Carbon::parse($result['exp_date'])->format('d M Y') : null],
                                ['label' => __('triproof.verify.status'), 'value' => $result['status'] ? __('triproof.status.'.$result['status']) : null],
                                ['label' => __('triproof.verify.claimed_by'), 'value' => $result['claimed_device']],
                            ] as $detail)
                                <div>
                                    <p class="text-[10px] font-bold tracking-[0.1em] text-slate-400 uppercase">{{ $detail['label'] }}</p>
                                    <p class="mt-0.5 font-mono text-[11px] text-slate-700">{{ $detail['value'] ?? '—' }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                @if ($extraction)
                    <div class="card p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="section-kicker">{{ __('triproof.verify.ocr_used') }}</p>
                            <span class="badge badge-info">{{ __('triproof.verify.ocr_confidence') }}: {{ round(((float) ($extraction['confidence'] ?? 0)) * 100) }}%</span>
                        </div>
                        <div class="mt-3 space-y-2">
                            <code class="code-block block">{{ $extraction['outer_serial'] ?: '—' }}</code>
                            @if ($extraction['notes'])
                                <p class="hint">{{ $extraction['notes'] }}</p>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('verify.index') }}" class="btn btn-secondary">
                        <x-icon name="refresh" class="h-4 w-4" />
                        {{ __('triproof.verify.try_again') }}
                    </a>
                    <a href="{{ route('dashboard') }}" class="btn btn-ghost">{{ __('triproof.nav.dashboard') }}</a>
                </div>
            @else
                <div class="card overflow-hidden">
                    <div class="auth-panel relative flex min-h-[280px] items-center justify-center overflow-hidden p-8">
                        <div class="auth-orbit absolute inset-0 max-w-none opacity-70">
                            <div class="absolute inset-[14%] rounded-full border border-white/10"></div>
                            <div class="absolute inset-[30%] rounded-full border border-white/10"></div>
                        </div>
                        <div class="relative text-center">
                            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl border border-brand-400/25 bg-brand-400/10 text-brand-300">
                                <x-icon name="verify" class="h-8 w-8" />
                            </span>
                            <h3 class="mt-4 text-lg font-semibold text-white">{{ __('triproof.verify.result_title') }}</h3>
                            <p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-white/50">{{ __('triproof.verify.security_note') }}</p>
                        </div>
                    </div>

                    <div class="grid gap-3 p-5 sm:grid-cols-3">
                        @foreach ([
                            ['icon' => 'lock', 'title' => __('triproof.verify.signature_valid'), 'body' => __('triproof.verify.readonly')],
                            ['icon' => 'database', 'title' => __('triproof.verify.registered'), 'body' => __('triproof.verify.security_note')],
                            ['icon' => 'user', 'title' => __('triproof.verify.claimed'), 'body' => __('triproof.verify.owner_repeat_info')],
                        ] as $step)
                            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <x-icon :name="$step['icon']" class="h-4 w-4 text-brand-700" />
                                <p class="mt-2 text-xs font-semibold text-ink-950">{{ $step['title'] }}</p>
                                <p class="mt-1 text-[10px] leading-4 text-slate-500">{{ $step['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flash flash-info">
                    <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0" />
                    <p class="text-xs leading-5">{{ __('triproof.verify.security_note') }}</p>
                </div>
            @endif
        </section>
    </div>
@endsection
