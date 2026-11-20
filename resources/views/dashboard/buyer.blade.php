@extends('layouts.app')

@section('page-title', __('triproof.dashboard.title'))

@php
    $user = auth()->user();
    $aiConfigured = in_array(config('triproof.ai.provider'), ['openai', 'gemini'], true)
        && filled(config('triproof.ai.api_key'));
@endphp

@section('content')
    <section class="relative mb-5 overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
        <div class="pointer-events-none absolute -top-16 -right-16 h-56 w-56 rounded-full bg-brand-100/60 blur-3xl"></div>
        <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="badge badge-success">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                        {{ __('triproof.dashboard.buyer_scope', ['name' => $user->name]) }}
                    </span>
                    <span class="badge badge-neutral">{{ __('triproof.roles.'.$user->role->value) }}</span>
                    <span class="badge {{ $aiConfigured ? 'badge-info' : 'badge-neutral' }}">
                        <x-icon name="sparkles" class="h-3 w-3" />
                        {{ $aiConfigured ? __('triproof.ai.configured') : __('triproof.ai.fallback') }}
                    </span>
                </div>
                <h2 class="mt-3 text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">
                    {{ __('triproof.dashboard.welcome', ['name' => $user->name]) }}
                </h2>
                <p class="mt-1.5 max-w-2xl text-sm leading-6 text-slate-500">{{ __('triproof.dashboard.subtitle_buyer') }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" data-open-dialog="create-order-dialog">
                    <x-icon name="plus" class="h-4 w-4" />
                    {{ __('triproof.dashboard.create_order') }}
                </button>
                <button
                    type="button"
                    class="btn btn-secondary"
                    data-open-dialog="recall-dialog"
                    data-factory="{{ $accessibleFactories[0] ?? '' }}"
                    data-reset-scope
                    data-context="{{ __('triproof.dashboard.all_orders') }}"
                >
                    <x-icon name="recall" class="h-4 w-4" />
                    {{ __('triproof.dashboard.recall') }}
                </button>
                <a href="{{ route('verify.index') }}" class="btn btn-secondary">
                    <x-icon name="verify" class="h-4 w-4" />
                    {{ __('triproof.dashboard.verify') }}
                </a>
            </div>
        </div>
    </section>

    @include('partials.generated-batch')
    @include('partials.stats')

    <section id="orders" class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.orders.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.orders.title') }}</h2>
            </div>
            <button type="button" class="btn btn-primary btn-xs" data-open-dialog="create-order-dialog">
                <x-icon name="plus" class="h-3.5 w-3.5" />
                {{ __('triproof.dashboard.create_order') }}
            </button>
        </div>
        @include('partials.orders-table', [
            'orders' => $orders,
            'showBuyer' => false,
            'canGenerate' => true,
            'canPack' => false,
            'emptyMessage' => __('triproof.orders.empty_buyer'),
        ])
    </section>

    <section id="batches" class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.dashboard.batches.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.dashboard.batches.title') }}</h2>
            </div>
        </div>
        @include('partials.batches-table', [
            'batches' => $batches,
            'canPack' => false,
        ])
    </section>

    <div class="mt-6">
        @include('partials.chart-map')
    @include('partials.intelligence-widgets')
    </div>

    <section id="anomalies" class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.dashboard.anomalies.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.dashboard.anomalies.title') }}</h2>
            </div>
            <span class="badge {{ $anomalies->isNotEmpty() ? 'badge-danger' : 'badge-success' }}">
                {{ $anomalies->count() }} {{ __('triproof.ai.open') }}
            </span>
        </div>
        <div class="border-b border-slate-100 p-5">
            @include('partials.ai-form')
        </div>
        <div class="p-5">
            @include('partials.anomalies')
        </div>
    </section>

    <section id="recalls" class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.dashboard.recalls.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.dashboard.recalls.title') }}</h2>
            </div>
            <button
                type="button"
                class="btn btn-secondary btn-xs"
                data-open-dialog="recall-dialog"
                data-factory="{{ $accessibleFactories[0] ?? '' }}"
                data-reset-scope
                data-context="{{ __('triproof.dashboard.all_orders') }}"
            >
                <x-icon name="recall" class="h-3.5 w-3.5" />
                {{ __('triproof.dashboard.recall') }}
            </button>
        </div>
        @include('partials.recalls')
    </section>

    @include('partials.dialogs')
@endsection
