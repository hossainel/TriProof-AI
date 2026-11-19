@extends('layouts.public')

@section('title', $title.' · '.config('app.name'))
@section('description', $description)

@section('content')
    <section class="mx-auto max-w-4xl pb-16">
        <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm sm:p-10">
            <a href="{{ route('about') }}" class="inline-flex items-center gap-2 text-xs font-semibold tracking-[0.14em] text-sky-700 uppercase transition hover:text-sky-600">
                <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
                {{ trans('about.nav_about') }}
            </a>

            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-ink-950">{{ $title }}</h1>

            <div class="mt-6 space-y-4 text-sm leading-7 text-slate-600">
                @foreach ($paragraphs as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>
        </div>
    </section>
@endsection
