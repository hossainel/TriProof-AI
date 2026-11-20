@extends('layouts.public')

@section('title', __('Documentation Unavailable'))

@section('content')
<div class="mx-auto max-w-2xl py-16 text-center">
    <x-icon name="shield-lock" class="mx-auto h-16 w-16 text-slate-300" />
    <h1 class="mt-6 text-3xl font-bold tracking-tight text-slate-900">{{ __('Documentation Unavailable') }}</h1>
    <p class="mt-4 text-slate-600">{{ $reason }}</p>
    @if(isset($previewActive) && $previewActive)
        <div class="mt-8 rounded-lg bg-amber-50 p-4 text-amber-800">
            <strong>{{ __('Admin Preview Mode') }}</strong> — {{ __('Public Access is Currently Restricted') }}
        </div>
    @endif
</div>
@endsection
