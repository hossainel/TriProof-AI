@php
    $locale = app()->getLocale();
@endphp

<!DOCTYPE html>
<html lang="{{ $locale }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ __('triproof.app.description') }}">
    <title>@yield('page-title', __('triproof.auth.title')) · {{ __('triproof.app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-canvas">
    <div class="grid min-h-screen lg:grid-cols-[1.08fr_0.92fr]">
        <section class="auth-panel relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16">
            <div class="flex items-center gap-3">
                <span class="brand-mark h-11 w-11">
                    <x-icon name="shield" class="h-6 w-6" />
                </span>
                <div>
                    <p class="text-lg font-semibold tracking-tight text-white">{{ __('triproof.app.name') }}</p>
                    <p class="text-[10px] font-semibold tracking-[0.18em] text-brand-300/80 uppercase">{{ __('triproof.app.confidential') }}</p>
                </div>
            </div>

            <div class="relative mx-auto my-10 flex w-full max-w-xl flex-col items-center">
                <div class="auth-orbit flex items-center justify-center">
                    <div class="absolute inset-[1%] rounded-full border border-brand-400/20"></div>
                    <div class="relative flex h-40 w-40 items-center justify-center rounded-full border border-brand-400/30 bg-brand-400/10 text-brand-300 shadow-2xl">
                        <x-icon name="shield" class="h-16 w-16" />
                    </div>
                    <span class="absolute top-[16%] left-[8%] rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[10px] font-semibold tracking-wider text-white/60 uppercase">Ed25519</span>
                    <span class="absolute top-[48%] right-[2%] rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[10px] font-semibold tracking-wider text-white/60 uppercase">Offline</span>
                    <span class="absolute bottom-[12%] left-[20%] rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[10px] font-semibold tracking-wider text-white/60 uppercase">Quota-locked</span>
                </div>

                <h2 class="mt-8 max-w-lg text-center text-3xl font-semibold tracking-tight text-white xl:text-4xl">{{ __('triproof.app.tagline') }}</h2>
                <p class="mt-3 max-w-md text-center text-sm leading-6 text-white/55">{{ __('triproof.app.description') }}</p>
            </div>

            <div class="grid gap-3 xl:grid-cols-3">
                @foreach ([
                    ['icon' => 'lock', 'title' => __('triproof.auth.proof_1_title'), 'body' => __('triproof.auth.proof_1_body')],
                    ['icon' => 'verify', 'title' => __('triproof.auth.proof_2_title'), 'body' => __('triproof.auth.proof_2_body')],
                    ['icon' => 'recall', 'title' => __('triproof.auth.proof_3_title'), 'body' => __('triproof.auth.proof_3_body')],
                ] as $proof)
                    <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-4">
                        <x-icon :name="$proof['icon']" class="h-5 w-5 text-brand-300" />
                        <p class="mt-3 text-xs font-semibold text-white">{{ $proof['title'] }}</p>
                        <p class="mt-1 text-[11px] leading-5 text-white/45">{{ $proof['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="flex min-h-screen flex-col">
            <header class="flex items-center justify-between gap-4 px-5 py-5 sm:px-8 lg:px-10">
                <div class="flex items-center gap-3 lg:hidden">
                    <span class="brand-mark bg-ink-950 text-brand-300">
                        <x-icon name="shield" class="h-5 w-5" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold tracking-tight text-ink-950">{{ __('triproof.app.name') }}</p>
                        <p class="text-[9px] font-semibold tracking-[0.14em] text-slate-400 uppercase">{{ __('triproof.app.confidential') }}</p>
                    </div>
                </div>
                <div class="ml-auto">
                    <form method="POST" action="{{ route('language.update') }}">
                        @csrf
                        <label class="sr-only" for="locale">{{ __('triproof.nav.language') }}</label>
                        <select id="locale" name="locale" class="lang-select" data-locale-select>
                            <option value="en" @selected($locale === 'en')>English</option>
                            <option value="bn" @selected($locale === 'bn')>বাংলা</option>
                        </select>
                    </form>
                </div>
            </header>

            <main class="flex flex-1 items-center justify-center px-5 py-8 sm:px-8 lg:px-12">
                <div class="w-full max-w-md">
                    @yield('content')
                </div>
            </main>

            <footer class="flex flex-wrap items-center justify-between gap-2 px-5 py-5 text-[10px] text-slate-400 sm:px-8 lg:px-12">
                <span>{{ __('triproof.app.name') }} {{ __('triproof.app.version') }}</span>
                <span class="font-mono">Ed25519 · SHA-256 · {{ $locale === 'bn' ? 'বাংলা' : 'English' }}</span>
            </footer>
        </section>
    </div>
</body>
</html>
