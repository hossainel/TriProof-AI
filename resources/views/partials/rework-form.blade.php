@if ($errors->has('rework'))
    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-5 text-rose-700">
        {{ $errors->first('rework') }}
    </div>
@endif

<form method="POST" action="{{ route('rework.reconcile') }}" enctype="multipart/form-data" class="space-y-4">
    @csrf

    <div>
        <label for="rework-file" class="label">{{ __('triproof.forms.rework.file') }}</label>
        <input
            id="rework-file"
            name="file"
            type="file"
            accept=".csv,.json,.txt,text/csv,application/json,text/plain"
            class="input file:mr-3 file:rounded-lg file:border-0 file:bg-ink-900 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white"
            required
        >
        <p class="hint mt-1">{{ __('triproof.forms.rework.file_hint') }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button type="submit" class="btn btn-primary">
            <x-icon name="upload" class="h-4 w-4" />
            {{ __('triproof.forms.rework.submit') }}
        </button>
        <a href="{{ route('rework.template') }}" class="btn btn-secondary">
            <x-icon name="download" class="h-4 w-4" />
            {{ __('triproof.forms.rework.template') }}
        </a>
    </div>

    <p class="hint flex items-start gap-2">
        <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
        {{ __('triproof.forms.rework.note') }}
    </p>
</form>
