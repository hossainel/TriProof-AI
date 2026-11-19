@extends('layouts.public')

@section('title', trans('about.title').' · '.config('app.name'))
@section('description', trans('about.tagline'))

@section('content')
    <section class="mx-auto max-w-4xl pb-16">
        <div class="relative overflow-hidden rounded-3xl bg-ink-950 px-6 py-12 text-white shadow-xl sm:px-10 sm:py-16">
            <div class="pointer-events-none absolute -top-24 -right-24 h-64 w-64 rounded-full bg-sky-500/20 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-24 -left-24 h-64 w-64 rounded-full bg-brand-400/10 blur-3xl" aria-hidden="true"></div>

            <div class="relative">
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-[11px] font-semibold tracking-[0.14em] text-sky-200 uppercase">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-400"></span>
                    {{ trans('about.eyebrow') }}
                </span>

                <h1 class="mt-5 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ trans('about.title') }}</h1>
                <p class="mt-4 max-w-3xl text-sm leading-7 text-white/65 sm:text-base">{{ trans('about.tagline') }}</p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 rounded-xl bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-400">
                            {{ __('triproof.nav.dashboard') }}
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    @else
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-xl bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-400">
                                {{ trans('about.cta_text') }}
                                <x-icon name="arrow-right" class="h-4 w-4" />
                            </a>
                        @endif
                    @endauth
                    <a href="#mission" class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                        {{ trans('about.mission_title') }}
                    </a>
                </div>
            </div>
        </div>

        <section id="mission" class="mt-14 scroll-mt-24">
            <div class="grid gap-8 lg:grid-cols-[1.15fr_0.85fr]">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">{{ trans('about.mission_title') }}</h2>
                    <p class="mt-4 text-sm leading-7 text-slate-600">{{ trans('about.mission_intro') }}</p>
                </div>

                <ul class="space-y-3">
                    @foreach (trans('about.mission_points') as $point)
                        <li class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                                <x-icon name="check" class="h-4 w-4" />
                            </span>
                            <span class="text-sm leading-6 text-slate-700">{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        <section id="how" class="mt-16 scroll-mt-24">
            <h2 class="text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">{{ trans('about.how_title') }}</h2>
            <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">{{ trans('about.how_intro') }}</p>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                @foreach (trans('about.how_steps') as $step)
                    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 font-mono text-sm font-bold text-sky-700">
                            {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <h3 class="mt-4 text-sm font-semibold text-ink-950">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $step['body'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section id="tech" class="mt-16 scroll-mt-24">
            <h2 class="text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">{{ trans('about.tech_title') }}</h2>
            <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">{{ trans('about.tech_description') }}</p>

            <ul class="mt-6 flex flex-wrap gap-2.5">
                @foreach (trans('about.tech') as $technology)
                    <li class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3.5 py-1.5 text-xs font-medium text-slate-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                        {{ $technology }}
                    </li>
                @endforeach
            </ul>
        </section>

        <section id="team" class="mt-16 scroll-mt-24">
            <h2 class="text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">{{ trans('about.team_title') }}</h2>
            <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">{{ trans('about.team_intro') }}</p>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($team as $member)
                    @php
                        $memberSlug = $member['slug'] ?? strtolower(str_replace(' ', '_', $member['name']));
                        $hasPhoto = ! empty($member['photo']) && is_file(public_path($member['photo']));
                    @endphp

                    <article @class([
                        'relative rounded-2xl border bg-white p-5 shadow-sm transition hover:shadow-md',
                        'border-sky-300 ring-1 ring-sky-200' => ! empty($member['lead']),
                        'border-slate-200' => empty($member['lead']),
                    ])>
                        <div class="flex items-start gap-4">
                            @if ($hasPhoto)
                                <div class="relative h-20 w-20 shrink-0">
                                    <img
                                        src="{{ asset($member['photo']) }}"
                                        alt="{{ $member['name'] }}"
                                        class="h-20 w-20 rounded-full object-cover"
                                        onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden'); this.nextElementSibling.classList.add('flex');"
                                    >
                                    <span class="hidden h-20 w-20 items-center justify-center rounded-full bg-sky-600 text-lg font-bold text-white">
                                        {{ $member['initials'] }}
                                    </span>
                                </div>
                            @else
                                <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-sky-600 text-lg font-bold text-white">
                                    {{ $member['initials'] }}
                                </span>
                            @endif

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-ink-950">{{ $member['name'] }}</h3>
                                    @if (! empty($member['lead']))
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-amber-700 uppercase ring-1 ring-amber-200">
                                            {{ trans('about.team_lead_badge') }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs font-medium text-sky-700">{{ trans('about.member_role_'.$memberSlug) }}</p>
                            </div>
                        </div>

                        <p class="mt-4 text-sm leading-6 text-slate-600">{{ trans('about.member_bio_'.$memberSlug) }}</p>

                        <div class="mt-4 flex flex-wrap items-center gap-3 text-xs font-medium">
                            @if (! empty($member['github']))
                                <a href="{{ $member['github'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-slate-500 transition hover:text-sky-700">
                                    GitHub
                                    <x-icon name="external" class="h-3 w-3" />
                                </a>
                            @endif
                            @if (! empty($member['linkedin']))
                                <a href="{{ $member['linkedin'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-slate-500 transition hover:text-sky-700">
                                    LinkedIn
                                    <x-icon name="external" class="h-3 w-3" />
                                </a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section id="proof" class="mt-16 scroll-mt-24 overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 to-[#1e3a5f] p-8 text-slate-200 shadow-xl sm:p-10">
            <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{{ trans('about.proof_title') }}</h2>
            <div class="mt-4 space-y-3 text-sm leading-7 text-slate-300">
                @foreach (trans('about.proof_text') as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>

            <div class="mt-8">
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 rounded-xl bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-600">
                        {{ __('triproof.nav.dashboard') }}
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-xl bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-600">
                            {{ trans('about.cta_text') }}
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    @endif
                @endauth
            </div>
        </section>
    </section>
@endsection
