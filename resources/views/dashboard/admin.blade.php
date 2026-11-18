@extends('layouts.app')

@section('page-title', __('triproof.dashboard.title'))

@php
    $user = auth()->user();
    $aiConfigured = in_array(config('triproof.ai.provider'), ['openai', 'gemini'], true)
        && filled(config('triproof.ai.api_key'));
    $publicKey = (string) config('triproof.crypto.public_key');
@endphp

@section('content')
    <section class="relative mb-5 overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
        <div class="pointer-events-none absolute -top-16 -right-16 h-56 w-56 rounded-full bg-brand-100/60 blur-3xl"></div>
        <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="badge badge-success">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                        {{ __('triproof.dashboard.admin_scope') }}
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
                <p class="mt-1.5 max-w-2xl text-sm leading-6 text-slate-500">{{ __('triproof.dashboard.subtitle_admin') }}</p>
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
                    data-factory=""
                    data-reset-scope
                    data-context="{{ __('triproof.dashboard.admin_scope') }}"
                >
                    <x-icon name="recall" class="h-4 w-4" />
                    {{ __('triproof.dashboard.recall') }}
                </button>
                <a href="#rework" class="btn btn-secondary">
                    <x-icon name="upload" class="h-4 w-4" />
                    {{ __('triproof.dashboard.rework') }}
                </a>
            </div>
        </div>
    </section>

    @include('partials.generated-batch')
    @include('partials.stats')

    <section id="orders" class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.orders.subtitle_admin') }}</p>
                <h2 class="section-title">{{ __('triproof.orders.title_admin') }}</h2>
            </div>
            <button type="button" class="btn btn-primary btn-xs" data-open-dialog="create-order-dialog">
                <x-icon name="plus" class="h-3.5 w-3.5" />
                {{ __('triproof.dashboard.create_order') }}
            </button>
        </div>
        @include('partials.orders-table', [
            'orders' => $orders,
            'showBuyer' => true,
            'canGenerate' => true,
            'canPack' => true,
            'emptyMessage' => __('triproof.orders.empty_admin'),
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

    <section id="batches" class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.dashboard.batches.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.dashboard.batches.title') }}</h2>
            </div>
        </div>
        @include('partials.batches-table', [
            'batches' => $batches,
            'canPack' => true,
        ])
    </section>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <section id="rework" class="card overflow-hidden lg:col-span-2">
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

        <section class="card overflow-hidden">
            <div class="card-header">
                <div>
                    <p class="section-kicker">{{ __('triproof.dashboard.admin_health_body') }}</p>
                    <h2 class="section-title">{{ __('triproof.dashboard.admin_health') }}</h2>
                </div>
                <span class="stat-icon"><x-icon name="health" class="h-4 w-4" /></span>
            </div>
            <div class="space-y-4 p-5">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p class="text-[10px] font-bold tracking-[0.12em] text-slate-500 uppercase">{{ __('triproof.dashboard.public_key') }}</p>
                    <code class="mt-1.5 block truncate font-mono text-[10px] text-slate-600">{{ $publicKey ?: '—' }}</code>
                </div>
                <div class="flex flex-col gap-2">
                    <a href="{{ url('/api/v1/health') }}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-xs justify-between">
                        {{ __('triproof.dashboard.open_health') }}
                        <x-icon name="external" class="h-3.5 w-3.5" />
                    </a>
                    <a href="{{ route('well-known.key') }}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-xs justify-between">
                        {{ __('triproof.nav.public_key') }}
                        <x-icon name="external" class="h-3.5 w-3.5" />
                    </a>
                    <a href="{{ route('verify.index') }}" class="btn btn-secondary btn-xs justify-between">
                        {{ __('triproof.dashboard.verify') }}
                        <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </div>
                <p class="hint">{{ __('triproof.app.footer') }}</p>
            </div>
        </section>
    </div>

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
                data-factory=""
                data-reset-scope
                data-context="{{ __('triproof.dashboard.admin_scope') }}"
            >
                <x-icon name="recall" class="h-3.5 w-3.5" />
                {{ __('triproof.dashboard.recall') }}
            </button>
        </div>
        @include('partials.recalls')
    </section>

    <section class="card mt-6 overflow-hidden">
        <div class="card-header">
            <div>
                <p class="section-kicker">{{ __('triproof.dashboard.users.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.dashboard.users.title') }}</h2>
            </div>
            <span class="badge badge-neutral">{{ $users->count() }}</span>
        </div>
        @if ($users->isEmpty())
            <div class="empty-state">
                <span class="empty-icon"><x-icon name="users" class="h-6 w-6" /></span>
                <p class="text-xs text-slate-500">{{ __('triproof.dashboard.users.empty') }}</p>
            </div>
        @else
            <div class="table-shell">
                <table class="data-table min-w-[760px]">
                    <thead>
                        <tr>
                            <th>{{ __('triproof.dashboard.users.name') }}</th>
                            <th>{{ __('triproof.dashboard.users.email') }}</th>
                            <th>{{ __('triproof.dashboard.users.role') }}</th>
                            <th>{{ __('triproof.dashboard.users.factory') }}</th>
                            <th>{{ __('triproof.dashboard.users.language') }}</th>
                            <th>{{ __('triproof.dashboard.users.created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $account)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        <span class="avatar h-8 w-8 rounded-lg">{{ mb_strtoupper(mb_substr($account->name, 0, 2)) }}</span>
                                        <span class="text-xs font-semibold text-ink-950">{{ $account->name }}</span>
                                    </div>
                                </td>
                                <td class="text-xs text-slate-500">{{ $account->email }}</td>
                                <td>
                                    <span class="badge {{ $account->role === \App\Enums\UserRole::Admin ? 'badge-danger' : ($account->role === \App\Enums\UserRole::FactoryQa ? 'badge-info' : 'badge-success') }}">
                                        {{ __('triproof.roles.'.$account->role->value) }}
                                    </span>
                                </td>
                                <td class="font-mono text-[11px] text-slate-500">{{ $account->factory_id ?: '—' }}</td>
                                <td>
                                    <span class="badge badge-neutral">{{ strtoupper($account->language_pref ?? 'en') }}</span>
                                </td>
                                <td class="text-[11px] text-slate-500">{{ $account->created_at?->format('d M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @include('partials.dialogs')
@endsection
