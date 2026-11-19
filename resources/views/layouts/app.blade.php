@php
    $user = auth()->user();
    $locale = app()->getLocale();
    $workspace = [
        ['label' => __('triproof.nav.dashboard'), 'url' => route('dashboard'), 'icon' => 'dashboard', 'active' => request()->routeIs('dashboard')],
        ['label' => __('triproof.nav.verify'), 'url' => route('verify.index'), 'icon' => 'verify', 'active' => request()->routeIs('verify.*')],
    ];
    $operations = [
        ['label' => __('triproof.nav.batches'), 'url' => route('dashboard').'#batches', 'icon' => 'batches'],
        ['label' => __('triproof.nav.anomalies'), 'url' => route('dashboard').'#anomalies', 'icon' => 'anomalies'],
        ['label' => __('triproof.nav.recall'), 'url' => route('dashboard').'#recalls', 'icon' => 'recall'],
    ];
    if ($user->isBuyer() || $user->isAdmin()) {
        array_unshift($operations, ['label' => __('triproof.nav.orders'), 'url' => route('dashboard').'#orders', 'icon' => 'orders']);
    }
    if ($user->isAdmin()) {
        $operations[] = ['label' => __('triproof.admin.manage_button'), 'url' => route('admin.manage'), 'icon' => 'users'];
    }
    if (! $user->isBuyer()) {
        $operations[] = ['label' => __('triproof.nav.rework'), 'url' => route('dashboard').'#rework', 'icon' => 'rework'];
    }
    $trust = [
        ['label' => __('triproof.nav.public_key'), 'url' => route('well-known.key'), 'icon' => 'key', 'external' => true],
        ['label' => __('triproof.nav.health'), 'url' => url('/api/v1/health'), 'icon' => 'health', 'external' => true],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ $locale }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ __('triproof.app.description') }}">
    <title>@yield('page-title', __('triproof.dashboard.title')) · {{ __('triproof.app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full">
    <a class="skip-link" href="#main-content">{{ __('triproof.nav.dashboard') }}</a>

    <div id="sidebar-overlay" class="fixed inset-0 z-40 hidden bg-ink-950/70 backdrop-blur-sm lg:hidden" aria-hidden="true"></div>

    <aside id="app-sidebar" class="app-sidebar fixed inset-y-0 left-0 z-50 flex w-[280px] -translate-x-full flex-col border-r border-white/10 transition-transform duration-200 ease-out lg:translate-x-0">
        <div class="flex items-center gap-3 px-5 pt-5 pb-2">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                <span class="brand-mark">
                    <x-icon name="shield" class="h-5 w-5" />
                </span>
                <span>
                    <span class="block text-[15px] font-semibold tracking-tight text-white">{{ __('triproof.app.name') }}</span>
                    <span class="block text-[10px] font-semibold tracking-[0.16em] text-brand-300/80 uppercase">{{ __('triproof.app.confidential') }}</span>
                </span>
            </a>
            <button type="button" class="btn-icon ml-auto text-white/50 hover:bg-white/10 hover:text-white lg:hidden" data-sidebar-close aria-label="{{ __('triproof.nav.close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        <div class="mx-5 mt-4 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5">
            <div class="flex items-center gap-2">
                <span class="h-1.5 w-1.5 rounded-full bg-brand-400"></span>
                <span class="text-[10px] font-semibold tracking-[0.12em] text-white/60 uppercase">{{ __('triproof.nav.secure') }}</span>
            </div>
            <p class="mt-1 text-[11px] leading-4 text-white/45">{{ __('triproof.app.tagline') }}</p>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 pb-6" aria-label="{{ __('triproof.nav.main') }}">
            <p class="nav-group-title">{{ __('triproof.nav.account') }}</p>
            @foreach ($workspace as $item)
                <a href="{{ $item['url'] }}" class="nav-link {{ $item['active'] ? 'nav-link-active' : '' }}">
                    <x-icon :name="$item['icon']" class="h-[18px] w-[18px]" />
                    <span>{{ $item['label'] }}</span>
                    @if ($item['active'])<span class="nav-dot ml-auto"></span>@endif
                </a>
            @endforeach

            <p class="nav-group-title">{{ __('triproof.app.name') }}</p>
            @foreach ($operations as $item)
                <a href="{{ $item['url'] }}" class="nav-link">
                    <x-icon :name="$item['icon']" class="h-[18px] w-[18px]" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach

            <p class="nav-group-title">{{ __('triproof.nav.api') }}</p>
            @foreach ($trust as $item)
                <a href="{{ $item['url'] }}" class="nav-link" @if (! empty($item['external'])) target="_blank" rel="noopener noreferrer" @endif>
                    <x-icon :name="$item['icon']" class="h-[18px] w-[18px]" />
                    <span>{{ $item['label'] }}</span>
                    @if (! empty($item['external']))
                        <x-icon name="external" class="ml-auto h-3.5 w-3.5 text-white/30" />
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="border-t border-white/10 px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="avatar border border-white/10 bg-white/10 text-brand-200">{{ mb_strtoupper(mb_substr($user->name, 0, 2)) }}</span>
                <div class="min-w-0">
                    <p class="truncate text-xs font-semibold text-white">{{ $user->name }}</p>
                    <p class="truncate text-[10px] text-white/45">{{ __('triproof.roles.'.$user->role->value) }}</p>
                </div>
            </div>
            <p class="mt-4 text-[10px] leading-4 text-white/30">{{ __('triproof.app.footer') }} · {{ __('triproof.app.version') }}</p>
        </div>
    </aside>

    <div class="min-h-screen lg:pl-[280px]">
        <header class="topbar">
            <div class="mx-auto flex w-full max-w-[1500px] items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
                <button type="button" class="btn-icon lg:hidden" data-sidebar-toggle aria-label="{{ __('triproof.nav.menu') }}">
                    <x-icon name="menu" class="h-5 w-5" />
                </button>

                <div class="min-w-0">
                    <p class="section-kicker">{{ __('triproof.roles.'.$user->role->value) }} · {{ __('triproof.app.name') }}</p>
                    <h1 class="mt-0.5 truncate text-base font-semibold tracking-tight text-ink-950 sm:text-lg">@yield('page-title', __('triproof.dashboard.title'))</h1>
                </div>

                <div class="ml-auto flex items-center gap-2 sm:gap-3">
                    <form method="POST" action="{{ route('language.update') }}">
                        @csrf
                        <label class="sr-only" for="locale">{{ __('triproof.nav.language') }}</label>
                        <select id="locale" name="locale" class="lang-select" data-locale-select>
                            <option value="en" @selected($locale === 'en')>EN</option>
                            <option value="bn" @selected($locale === 'bn')>বাংলা</option>
                        </select>
                    </form>

                    <div class="hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 shadow-sm sm:flex">
                        <span class="avatar h-7 w-7 rounded-lg">{{ mb_strtoupper(mb_substr($user->name, 0, 2)) }}</span>
                        <div class="max-w-32">
                            <p class="truncate text-[11px] font-semibold text-ink-950">{{ $user->name }}</p>
                            <p class="truncate text-[9px] text-slate-500">{{ __('triproof.roles.'.$user->role->value) }}</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-icon" title="{{ __('triproof.nav.logout') }}" aria-label="{{ __('triproof.nav.logout') }}">
                            <x-icon name="logout" class="h-[18px] w-[18px]" />
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main id="main-content" class="mx-auto w-full max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            @include('partials.flash')
            @yield('content')

            <footer class="mt-10 flex flex-wrap items-center justify-between gap-2 border-t border-slate-200/80 pt-5 text-[11px] text-slate-400">
                <span class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('about') }}" class="font-medium text-slate-500 transition hover:text-brand-700">{{ trans('about.nav_about') }}</a>
                    <span aria-hidden="true">·</span>
                    <span>{{ __('triproof.app.name') }} {{ __('triproof.app.version') }} · {{ __('triproof.app.footer') }}</span>
                </span>
                <span class="font-mono">Ed25519 · SHA-256 Merkle · {{ $locale === 'bn' ? 'বাংলা' : 'English' }}</span>
            </footer>
        </main>
    </div>
</body>
</html>
