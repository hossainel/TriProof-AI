@php
    $aiConfigured = in_array(config('triproof.ai.provider'), ['openai', 'gemini'], true)
        && filled(config('triproof.ai.api_key'));
    $batchOptions = $batches->unique(fn ($batch) => "{$batch->factory_id}|{$batch->machine_id}|{$batch->batch_id}");
@endphp

<form method="POST" action="{{ route('ai.analyze') }}" class="space-y-4">
    @csrf

    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-[220px] flex-1">
            <label for="ai-batch-filter" class="label">{{ __('triproof.forms.ai.batch_filter') }}</label>
            <select id="ai-batch-filter" name="batch_id" class="select">
                <option value="">{{ __('triproof.forms.ai.all_batches') }}</option>
                @foreach ($batchOptions as $batch)
                    <option value="{{ $batch->batch_id }}">
                        {{ $batch->factory_id }}-{{ $batch->machine_id }}-{{ $batch->batch_id }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">
            <x-icon name="sparkles" class="h-4 w-4" />
            {{ __('triproof.forms.ai.run') }}
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <span class="badge {{ $aiConfigured ? 'badge-success' : 'badge-neutral' }}">
            <x-icon :name="$aiConfigured ? 'sparkles' : 'lock'" class="h-3 w-3" />
            {{ $aiConfigured ? __('triproof.forms.ai.configured') : __('triproof.forms.ai.not_configured') }}
        </span>
        <span class="hint">{{ __('triproof.forms.ai.fallback') }}</span>
    </div>

    <p class="hint flex items-start gap-2">
        <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
        {{ __('triproof.forms.ai.note') }}
    </p>
</form>
