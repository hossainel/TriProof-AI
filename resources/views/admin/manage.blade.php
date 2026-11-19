@extends('layouts.app')

@section('page-title', __('triproof.admin.title'))

@section('content')

    {{-- Status / validation banner --}}
    @if (session('status') || $errors->any())
        <div class="mb-6 rounded-2xl border p-4 shadow-sm
            @if (session('status'))
                border-emerald-200 bg-emerald-50 text-emerald-800
            @else
                border-rose-200 bg-rose-50 text-rose-800
            @endif">
            @if (session('status'))
                <div class="flex items-start gap-3">
                    <span class="shrink-0 rounded-full bg-emerald-200 text-emerald-700 flex h-6 w-6 items-center justify-center">
                        <x-icon name="check-circle" class="h-3 w-3" />
                    </span>
                    <div>
                        <p class="font-semibold">{{ session('status') }}</p>
                    </div>
                </div>
            @endif
            @if ($errors->any())
                <div class="mt-3 flex items-start gap-3">
                    <span class="shrink-0 rounded-full bg-rose-200 text-rose-700 flex h-6 w-6 items-center justify-center">
                        <x-icon name="alert" class="h-3 w-3" />
                    </span>
                    <div>
                        <p class="font-semibold">{{ __('triproof.common.validation_errors') }}</p>
                        <ul class="mt-1 list-disc list-inside text-xs sm:text-sm space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <section class="relative mb-6 overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8 lg:p-9">
        <div class="relative flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="min-w-0">
                <p class="section-kicker">{{ __('triproof.admin.subtitle') }}</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-ink-950 md:text-3xl">
                    {{ __('triproof.admin.title') }}
                </h2>
            </div>

            <a href="{{ route('dashboard') }}" class="btn btn-secondary">
                <x-icon name="arrow-right" class="h-3.5 w-3.5 transform -rotate-90" />
                {{ __('triproof.admin.back') }}
            </a>
        </div>
    </section>

    <ul class="tabs" role="tablist" data-active-tab="{{ $activeTab ?? 'admin-buyers' }}">
        <li>
            <a href="#admin-buyers" class="tab tab-active" role="tab" aria-selected="true">
                {{ __('triproof.admin.buyers.title') }}
            </a>
        </li>
        <li>
            <a href="#admin-factories" class="tab" role="tab" aria-selected="false">
                {{ __('triproof.admin.factories.title') }}
            </a>
        </li>
        <li>
            <a href="#admin-operators" class="tab" role="tab" aria-selected="false">
                {{ __('triproof.admin.operators.title') }}
            </a>
        </li>
        <li>
            <a href="#admin-settings" class="tab" role="tab" aria-selected="false">
                {{ __('triproof.admin.settings.title') }}
            </a>
        </li>
    </ul>

    {{-- Global Buyers --}}
    <section id="admin-buyers" class="tab-panel mt-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="section-kicker">{{ __('triproof.admin.buyers.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.admin.buyers.title') }}</h2>
            </div>
            <button type="button" class="btn btn-primary" data-open-dialog="add-buyer-dialog">
                <x-icon name="plus" class="h-4 w-4" />
                {{ __('triproof.admin.buyers.add') }}
            </button>
        </div>

        <div class="overflow-x-auto border rounded-xl border-slate-200/80 mt-3">
            <table class="data-table w-full">
                <thead>
                    <tr>
                        <th>{{ __('triproof.admin.buyers.name') }}</th>
                        <th>{{ __('triproof.admin.buyers.email') }}</th>
                        <th class="text-center">{{ __('triproof.admin.buyers.language') }}</th>
                        <th class="text-center">{{ __('triproof.admin.buyers.orders') }}</th>
                        <th class="text-right pr-4">{{ __('triproof.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @if ($buyers->isEmpty())
                        <tr>
                            <td colspan="5" class="p-6 text-center text-slate-500">{{ __('triproof.admin.buyers.empty') }}</td>
                        </tr>
                    @else
                        @foreach ($buyers as $buyer)
                            <tr class="group">
                                <td class="font-medium text-ink-950">{{ $buyer->name }}</td>
                                <td class="font-mono text-xs text-slate-500">{{ $buyer->email }}</td>
                                <td class="text-center">
                                    <span class="badge badge-neutral">{{ strtoupper($buyer->language_pref ?? 'en') }}</span>
                                </td>
                                <td class="text-center font-mono text-sm {{ $buyer->purchase_orders_count > 0 ? 'text-rose-600 font-semibold' : 'text-slate-500' }}">{{ $buyer->purchase_orders_count }}</td>
                                <td class="text-right pr-4">
                                    <button type="button" class="btn btn-ghost btn-xs" data-open-dialog="edit-buyer-{{ $buyer->id }}">
                                        {{ __('triproof.admin.buyers.edit') }}
                                    </button>
                                    <form method="POST" action="{{ route('admin.buyers.destroy', $buyer) }}" class="inline ml-2" data-confirm="{{ __('triproof.admin.buyers.confirm_delete') }}">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="dialog_id" value="edit-buyer-{{ $buyer->id }}">
                                        <input type="hidden" name="active_tab" value="admin-buyers">
                                        <button type="submit" class="btn btn-danger btn-xs {{ $buyer->purchase_orders_count > 0 ? 'opacity-50 cursor-not-allowed' : '' }}" title="{{ $buyer->purchase_orders_count > 0 ? __('triproof.admin.buyers.cannot_delete') : '' }}" {{ $buyer->purchase_orders_count > 0 ? 'disabled' : '' }}>
                                            {{ __('triproof.admin.buyers.delete') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>

        @include('admin.partials.dialogs.buyers.add')
    </section>

    {{-- Factories --}}
    <section id="admin-factories" class="tab-panel hidden mt-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="section-kicker">{{ __('triproof.admin.factories.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.admin.factories.title') }}</h2>
            </div>
            <button type="button" class="btn btn-primary" data-open-dialog="add-factory-dialog">
                <x-icon name="plus" class="h-4 w-4" />
                {{ __('triproof.admin.factories.add') }}
            </button>
        </div>

        <div class="overflow-x-auto border rounded-xl border-slate-200/80 mt-3">
            <table class="data-table w-full">
                <thead>
                    <tr>
                        <th>{{ __('triproof.admin.factories.code') }}</th>
                        <th>{{ __('triproof.admin.factories.name_en') }}</th>
                        <th>{{ __('triproof.admin.factories.name_bn') }}</th>
                        <th class="text-center">{{ __('triproof.admin.factories.status') }}</th>
                        <th class="text-right pr-4">{{ __('triproof.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @if ($factories->isEmpty())
                        <tr>
                            <td colspan="5" class="p-6 text-center text-slate-500">{{ __('triproof.admin.factories.empty') }}</td>
                        </tr>
                    @else
                        @foreach ($factories as $factory)
                            <tr class="group">
                                <td class="font-mono font-semibold text-ink-950">{{ $factory->code }}</td>
                                <td class="font-medium text-ink-950">{{ $factory->name_en }}</td>
                                <td class="text-slate-500">{{ $factory->name_bn ?? __('triproof.common.not_set') }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $factory->is_active ? 'badge-success' : 'badge-neutral' }}">
                                        {{ $factory->is_active ? __('triproof.admin.factories.active') : __('triproof.admin.factories.inactive') }}
                                    </span>
                                </td>
                                <td class="text-right pr-4">
                                    <button type="button" class="btn btn-ghost btn-xs" data-open-dialog="edit-factory-{{ $factory->id }}">
                                        {{ __('triproof.admin.factories.edit') }}
                                    </button>
                                    @php
                                        $factoryBlocked = $factory->purchase_orders_count > 0 || $factory->serials_count > 0 || $factory->users_count > 0;
                                    @endphp
                                    <form method="POST" action="{{ route('admin.factories.destroy', $factory) }}" class="inline ml-2" data-confirm="{{ __('triproof.admin.factories.confirm_delete') }}">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="dialog_id" value="edit-factory-{{ $factory->id }}">
                                        <input type="hidden" name="active_tab" value="admin-factories">
                                        <button type="submit" class="btn btn-danger btn-xs {{ $factoryBlocked ? 'opacity-50 cursor-not-allowed' : '' }}" title="{{ $factoryBlocked ? __('triproof.admin.factories.cannot_delete') : '' }}" {{ $factoryBlocked ? 'disabled' : '' }}>
                                            {{ __('triproof.admin.factories.delete') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>

        @include('admin.partials.dialogs.factories.add')
    </section>

    {{-- Factory QA Operators --}}
    <section id="admin-operators" class="tab-panel hidden mt-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="section-kicker">{{ __('triproof.admin.operators.subtitle') }}</p>
                <h2 class="section-title">{{ __('triproof.admin.operators.title') }}</h2>
            </div>
            <button type="button" class="btn btn-primary" data-open-dialog="add-operator-dialog" {{ $factories->isEmpty() ? 'disabled' : '' }}>
                <x-icon name="plus" class="h-4 w-4" />
                {{ __('triproof.admin.operators.add') }}
            </button>
            @if ($factories->isEmpty())
                <p class="hint mt-1 text-xs text-rose-600">{{ __('triproof.admin.operators.no_factories') }}</p>
            @endif
        </div>

        <div class="overflow-x-auto border rounded-xl border-slate-200/80 mt-3">
            <table class="data-table w-full">
                <thead>
                    <tr>
                        <th>{{ __('triproof.admin.operators.name') }}</th>
                        <th>{{ __('triproof.admin.operators.factory') }}</th>
                        <th class="text-center">{{ __('triproof.admin.operators.language') }}</th>
                        <th class="text-right pr-4">{{ __('triproof.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @if ($operators->isEmpty())
                        <tr>
                            <td colspan="4" class="p-6 text-center text-slate-500">{{ __('triproof.admin.operators.empty') }}</td>
                        </tr>
                    @else
                        @foreach ($operators as $operator)
                            <tr class="group">
                                <td class="font-medium text-ink-950">{{ $operator->name }}</td>
                                <td class="font-mono text-xs text-ink-950">{{ $operator->factory_id ?? __('triproof.common.not_set') }}</td>
                                <td class="text-center">
                                    <span class="badge badge-neutral">{{ strtoupper($operator->language_pref ?? 'en') }}</span>
                                </td>
                                <td class="text-right pr-4">
                                    <button type="button" class="btn btn-ghost btn-xs" data-open-dialog="edit-operator-{{ $operator->id }}">
                                        {{ __('triproof.admin.operators.edit') }}
                                    </button>
                                    <form method="POST" action="{{ route('admin.operators.destroy', $operator) }}" class="inline ml-2" data-confirm="{{ __('triproof.admin.operators.confirm_delete') }}">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="dialog_id" value="edit-operator-{{ $operator->id }}">
                                        <input type="hidden" name="active_tab" value="admin-operators">
                                        <button type="submit" class="btn btn-danger btn-xs">
                                            {{ __('triproof.admin.operators.delete') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>

        @include('admin.partials.dialogs.operators.add', ['factories' => $factories])
    </section>

    {{-- Platform Settings --}}
    <section id="admin-settings" class="tab-panel hidden mt-6">
        <div class="max-w-2xl">
            <p class="section-kicker">{{ __('triproof.admin.settings.subtitle') }}</p>
            <h2 class="section-title">{{ __('triproof.admin.settings.title') }}</h2>
            <p class="mt-2 text-sm text-slate-500">{{ __('triproof.admin.settings.subtitle') }}</p>

            <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-5 mt-8">
                @csrf @method('PUT')
                <input type="hidden" name="active_tab" value="admin-settings">

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="settings-organization-name" class="label">
                            {{ __('triproof.admin.settings.organization_name') }}
                        </label>
                        <input id="settings-organization-name" name="organization_name" type="text" class="input"
                            value="{{ old('organization_name', $settings['organization_name'] ?? config('triproof.app.name')) }}" required>
                    </div>
                    <div>
                        <label for="settings-support-email" class="label">
                            {{ __('triproof.admin.settings.support_email') }}
                        </label>
                        <input id="settings-support-email" name="support_email" type="email" class="input"
                            value="{{ old('support_email', $settings['support_email'] ?? null) }}" required>
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="settings-default-factory-expiry" class="label">
                            {{ __('triproof.admin.settings.default_factory_expiry') }}
                        </label>
                        <div class="relative">
                            <input id="settings-default-factory-expiry" name="default_factory_expiry_days" type="number" class="input pr-12"
                                min="1" max="3650"
                                value="{{ old('default_factory_expiry_days', $settings['default_factory_expiry_days'] ?? SystemSetting::DEFAULTS['default_factory_expiry_days'] ?? 45) }}" required>
                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-slate-400">
                                {{ __('triproof.common.days') }}
                            </span>
                        </div>
                        <p class="hint mt-1">{{ __('triproof.admin.errors.factory_has_references') }}</p>
                    </div>
                    <div>
                        <label for="settings-default-retail-expiry" class="label">
                            {{ __('triproof.admin.settings.default_retail_expiry') }}
                        </label>
                        <div class="relative">
                            <input id="settings-default-retail-expiry" name="default_retail_expiry_days" type="number" class="input pr-12"
                                min="1" max="36500"
                                value="{{ old('default_retail_expiry_days', $settings['default_retail_expiry_days'] ?? SystemSetting::DEFAULTS['default_retail_expiry_days'] ?? 365) }}" required>
                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-slate-400">
                                {{ __('triproof.common.days') }}
                            </span>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="settings-compliance-notice-en" class="label">
                        {{ __('triproof.admin.settings.compliance_notice_en') }}
                    </label>
                    <textarea id="settings-compliance-notice-en" name="compliance_notice_en" rows="4" class="input"
                        >{{ old('compliance_notice_en', $settings['compliance_notice_en'] ?? null) }}</textarea>
                </div>
                <div>
                    <label for="settings-compliance-notice-bn" class="label">
                        {{ __('triproof.admin.settings.compliance_notice_bn') }}
                    </label>
                    <textarea id="settings-compliance-notice-bn" name="compliance_notice_bn" rows="4" class="input"
                        >{{ old('compliance_notice_bn', $settings['compliance_notice_bn'] ?? null) }}</textarea>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="btn btn-primary">
                        {{ __('triproof.admin.settings.save') }}
                    </button>
                </div>
            </form>
        </div>
    </section>
@endsection
