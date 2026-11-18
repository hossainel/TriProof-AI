@if (session('success') || session('error') || session('warning') || $errors->any())
    <div class="mb-5 space-y-3" aria-live="polite">
        @if (session('success'))
            <div class="flash flash-success" role="status">
                <x-icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-brand-700" />
                <div class="min-w-0">
                    <p class="font-semibold">{{ session('success') }}</p>
                </div>
            </div>
        @endif

        @if (session('error'))
            <div class="flash flash-danger" role="alert">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="min-w-0">
                    <p class="font-semibold">{{ session('error') }}</p>
                </div>
            </div>
        @endif

        @if (session('warning'))
            <div class="flash flash-info" role="status">
                <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0" />
                <p>{{ session('warning') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="flash flash-danger" role="alert">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="min-w-0">
                    <p class="font-semibold">{{ __('triproof.common.warning') }}</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5 text-xs sm:text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
@endif
