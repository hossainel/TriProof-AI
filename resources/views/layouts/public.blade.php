<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('description', 'Trust and authenticity for every unit, from factory to buyer.')">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-white text-slate-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:rounded-md focus:bg-sky-600 focus:px-4 focus:py-2 focus:text-sm focus:text-white">
        Skip to content
    </a>

    <header class="border-b border-slate-200 bg-white/95 backdrop-blur">
        <nav class="mx-auto max-w-6xl px-4 py-3 sm:px-6 lg:px-8" aria-label="Main navigation">
            <input type="checkbox" id="mobile-menu" class="peer hidden">

            <div class="flex items-center justify-between">
                <a href="{{ route('about') }}" class="flex items-center gap-2.5 text-lg font-bold tracking-tight text-slate-900">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-600 text-sm font-bold text-white">Tri</span>
                    <span>TriProof AI</span>
                </a>

                <label for="mobile-menu" aria-label="Toggle navigation"
                    class="flex cursor-pointer items-center justify-center rounded-md px-3 py-2 text-slate-700 transition hover:bg-slate-100 focus:outline-offset-2 focus-visible:ring-2 focus-visible:ring-sky-600 sm:hidden">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </label>

                <div class="hidden items-center gap-1 sm:flex">
                    <a href="{{ route('about') }}" class="rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 hover:text-sky-700 {{ request()->routeIs('about') ? 'bg-slate-100 text-sky-700' : '' }}">
                        {{ trans('about.nav_about') }}
                    </a>
                    @auth
                        <a href="{{ url('/dashboard') }}" class="rounded-md px-3 py-2 text-sm font-medium text-sky-700 transition hover:bg-sky-50">
                            {{ __('triproof.nav.dashboard') }}
                        </a>
                    @else
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 hover:text-sky-700">
                                {{ trans('about.sign_in') }}
                            </a>
                        @endif
                    @endauth

                    @if (Route::has('locale.switch'))
                        <div class="ml-2 flex items-center gap-1 border-l border-slate-200 pl-2">
                            <a href="{{ route('locale.switch', ['locale' => 'en']) }}" class="rounded-md px-2 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 {{ app()->getLocale() === 'en' ? 'bg-slate-100 text-slate-900' : '' }}">
                                EN
                            </a>
                            <a href="{{ route('locale.switch', ['locale' => 'bn']) }}" class="rounded-md px-2 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 {{ app()->getLocale() === 'bn' ? 'bg-slate-100 text-slate-900' : '' }}">
                                বাংলা
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-3 hidden flex-col gap-1 border-t border-slate-200 pt-3 peer-checked:max-sm:flex">
                <a href="{{ route('about') }}" class="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-sky-700">{{ trans('about.nav_about') }}</a>
                @auth
                    <a href="{{ url('/dashboard') }}" class="rounded-md px-3 py-2 text-sm font-medium text-sky-700 hover:bg-sky-50">{{ __('triproof.nav.dashboard') }}</a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-sky-700">{{ trans('about.sign_in') }}</a>
                    @endif
                @endauth
                @if (Route::has('locale.switch'))
                    <div class="mt-2 flex items-center gap-2 border-t border-slate-200 px-3 pt-2">
                        <a href="{{ route('locale.switch', ['locale' => 'en']) }}" class="rounded-md px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 {{ app()->getLocale() === 'en' ? 'bg-slate-100 text-slate-900' : '' }}">EN</a>
                        <a href="{{ route('locale.switch', ['locale' => 'bn']) }}" class="rounded-md px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 {{ app()->getLocale() === 'bn' ? 'bg-slate-100 text-slate-900' : '' }}">বাংলা</a>
                    </div>
                @endif
            </div>
        </nav>
    </header>

    <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
        @yield('content')
    </main>

    <footer class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('about') }}" class="text-sm text-slate-600 transition hover:text-sky-700">{{ trans('about.nav_about') }}</a>
                <span class="text-slate-400">&middot;</span>
                <a href="{{ Route::has('privacy') ? route('privacy') : '#' }}" class="text-sm text-slate-600 transition hover:text-sky-700">{{ trans('about.privacy_title') }}</a>
                <span class="text-slate-400">&middot;</span>
                <a href="{{ Route::has('terms') ? route('terms') : '#' }}" class="text-sm text-slate-600 transition hover:text-sky-700">{{ trans('about.terms_title') }}</a>
            </div>
            @if (Route::has('locale.switch'))
                <div class="flex items-center gap-2">
                    <a href="{{ route('locale.switch', ['locale' => 'en']) }}" class="text-xs font-medium text-slate-500 transition hover:text-sky-700 {{ app()->getLocale() === 'en' ? 'font-bold text-slate-900' : '' }}">EN</a>
                    <span class="text-slate-300">|</span>
                    <a href="{{ route('locale.switch', ['locale' => 'bn']) }}" class="text-xs font-medium text-slate-500 transition hover:text-sky-700 {{ app()->getLocale() === 'bn' ? 'font-bold text-slate-900' : '' }}">বাংলা</a>
                </div>
            @endif
        </div>
    </footer>
</body>
</html>
