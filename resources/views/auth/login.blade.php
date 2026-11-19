@extends('layouts.guest')

@section('page-title', __('triproof.auth.title'))

@section('content')
    <div class="mb-8">
        <span class="badge badge-success">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
            {{ __('triproof.nav.secure') }}
        </span>
        <h1 class="mt-4 text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">{{ __('triproof.auth.title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('triproof.auth.subtitle') }}</p>
    </div>

    @include('partials.flash')

    <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="label">{{ __('triproof.auth.email') }}</label>
            <div class="relative mt-1.5">
                <x-icon name="user" class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    placeholder="{{ __('triproof.auth.email_placeholder') }}"
                    class="input mt-0 pl-10"
                    autocomplete="email"
                    autofocus
                    required
                    @class(['border-rose-400' => $errors->has('email')])
                >
            </div>
        </div>

        <div>
            <label for="password" class="label">{{ __('triproof.auth.password') }}</label>
            <div class="relative mt-1.5">
                <x-icon name="lock" class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                    id="password"
                    name="password"
                    type="password"
                    placeholder="{{ __('triproof.auth.password_placeholder') }}"
                    class="input mt-0 pl-10"
                    autocomplete="current-password"
                    required
                    @class(['border-rose-400' => $errors->has('password')])
                >
            </div>
        </div>

        <label class="flex cursor-pointer items-center gap-2.5 text-xs font-medium text-slate-600">
            <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('remember'))>
            {{ __('triproof.auth.remember') }}
        </label>

        <button type="submit" class="btn btn-primary w-full py-3">
            {{ __('triproof.auth.submit') }}
            <x-icon name="arrow-right" class="h-4 w-4" />
        </button>
    </form>

    <div class="mt-6 border-t border-slate-200 pt-5 text-center">
        <a
            href="{{ route('about') }}"
            class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-sky-700"
        >
            <span>{{ trans('about.title') }}</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
    </div>

    <div class="mt-6 rounded-2xl border border-slate-200 bg-white/60 p-4">
        <div class="flex items-start gap-3">
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-brand-700" />
            <p class="text-[11px] leading-5 text-slate-500">{{ __('triproof.auth.security_note') }}</p>
        </div>
    </div>

    @if (config('triproof.show_demo_hints'))
        <p class="mt-4 text-center text-[11px] text-slate-400">{{ __('triproof.auth.demo_note') }}</p>
    @endif

    <p class="mt-8 text-center text-[10px] tracking-[0.12em] text-slate-400 uppercase">{{ __('triproof.app.footer') }}</p>
@endsection
