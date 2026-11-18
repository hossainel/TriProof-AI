@extends('layouts.app')

@section('page-title', __('triproof.dashboard.title'))

@php
    $user = auth()->user();
    $aiConfigured = in_array(config('triproof.ai.provider'), ['openai', 'gemini'], true)
        && filled(config('triproof.ai.api_key'));
@endphp

@section('content')
    <section class="relative mb-5 overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
        <div class="pointer-events-none absolute -top-16 -right-16 h-56 w-56 rounded-full bg-blue-100/60 blur-3xl"></div>
        <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="badge badge-info">
                        <x-icon name="factory" class="h-3 w-3" />
                        {{ __('triproof.dashboard.factory_scope', ['factory' => $user->factory_id ?: __('triproof.common.unknown')]) }}
                    </span>
                    <span class="badge badge-neutral">{{ __('triproof.roles.'.$user->role->value) }}</span>
                    <span class="badge {{ $aiConfigured ? 'badge-success' : 'badge-neutral' }}">
                        <x-icon name="sparkles" class="h-3 w-3" />
                        {{ $aiConfigured ? __('triproof.ai.configured') : __('triproof.ai.fallback') }}
                    </span>
                </div>
                <h2 class="mt-3 text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">
                    {{ __('triproof.dashboard.welcome', ['name' => $user->name]) }}
                </h2>
                <p class="mt-1.5 max-w-2xl text-sm leading-6 text-slate-500">{{ __('triproof.dashboard.subtitle_factory') }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="#rework" class="btn btn-primary">
                    <x-icon name="upload" class="h-4 w-4" />
                    {{ __('triproof.dashboard.rework') }}
                </a>
                <button
                    type="button"
                    class="btn btn-secondary"
                    data-open-dialog="recall-dialog"
                    data-factory="{{ $user->factory_id ?? '' }}"
                    data-reset-scope
                    data-context="{{ __('triproof.dashboard.factory_scope', ['factory' => $user->factory_id ?: __('triproof.common.unknown')]) }}"
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

    <section id="batches" class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.dashboard.batches.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.dashboard.batches.title') }}</h2>
            </div>
            <span class="badge badge-neutral">{{ __('triproof.orders.factory') }} {{ $user->factory_id }}</span>
        </div>
        @include('partials.batches-table', [
            'batches' => $batches,
            'canPack' => true,
        ])
    </section>

    <div class="mt-6">
        @include('partials.chart-map')
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

    <section id="rework" class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.forms.rework.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.forms.rework.title') }}</h2>
            </div>
            <span class="badge badge-info">{{ __('triproof.nav.rework') }}</span>
        </div>
        <div class="p-5 sm:p-6">
            @include('partials.rework-form')
        </div>
        @include('partials.rework-result')
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
                data-factory="{{ $user->factory_id ?? '' }}"
                data-reset-scope
                data-context="{{ __('triproof.dashboard.factory_scope', ['factory' => $user->factory_id ?: __('triproof.common.unknown')]) }}"
            >
                <x-icon name="recall" class="h-3.5 w-3.5" />
                {{ __('triproof.dashboard.recall') }}
            </button>
        </div>
        @include('partials.recalls')
    </section>

    @include('partials.dialogs')
@endsection
