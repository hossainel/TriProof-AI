<dialog id="add-buyer-dialog" class="tp-dialog" aria-labelledby="add-buyer-title">
    <div class="tp-dialog-panel">
        <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
            <x-icon name="close" class="h-5 w-5" />
        </button>

        <div class="border-b border-slate-100 px-6 pt-6 pb-4">
            <span class="badge badge-success">{{ __('triproof.admin.buyers.add') }}</span>
            <h2 id="add-buyer-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.admin.buyers.add_title') }}</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('triproof.admin.buyers.subtitle') }}</p>
        </div>

        <form method="POST" action="{{ route('admin.buyers.store') }}" class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="dialog_id" value="add-buyer-dialog">
            <input type="hidden" name="active_tab" value="admin-buyers">

            @php($isCurrentDialog = old('dialog_id') === 'add-buyer-dialog')

            @if ($isCurrentDialog && $errors->any())
                <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-5 text-rose-700">
                    {{ __('triproof.common.validation_errors') }}
                    <ul class="mt-1 list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <label for="add-buyer-name" class="label">{{ __('triproof.admin.buyers.name') }}</label>
                <input id="add-buyer-name" name="name" type="text" class="input" value="{{ $isCurrentDialog ? old('name') : '' }}" required>
            </div>

            <div>
                <label for="add-buyer-email" class="label">{{ __('triproof.admin.buyers.email') }}</label>
                <input id="add-buyer-email" name="email" type="email" class="input" value="{{ $isCurrentDialog ? old('email') : '' }}" required>
            </div>

            <div>
                <label for="add-buyer-password" class="label">{{ __('triproof.admin.buyers.password') }}</label>
                <input id="add-buyer-password" name="password" type="password" class="input" required>
                <p class="hint mt-1">{{ __('triproof.admin.buyers.password_hint') }}</p>
            </div>

            <div>
                <label for="add-buyer-language" class="label">{{ __('triproof.admin.buyers.language') }}</label>
                <select id="add-buyer-language" name="language_pref" class="select">
                    <option value="en" {{ $isCurrentDialog ? (old('language_pref', 'en') === 'en' ? 'selected' : '') : ('en' === 'en' ? 'selected' : '') }}>English</option>
                    <option value="bn" {{ $isCurrentDialog ? (old('language_pref') === 'bn' ? 'selected' : '') : ('' === 'bn' ? 'selected' : '') }}>বাংলা</option>
                </select>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('triproof.admin.buyers.add') }}</button>
            </div>
        </form>
    </div>
</dialog>

@foreach ($buyers as $buyer)
    <dialog id="edit-buyer-{{ $buyer->id }}" class="tp-dialog" aria-labelledby="edit-buyer-{{ $buyer->id }}-title">
        <div class="tp-dialog-panel">
            <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>

            <div class="border-b border-slate-100 px-6 pt-6 pb-4">
                <span class="badge badge-info">{{ __('triproof.admin.buyers.edit') }}</span>
                <h2 id="edit-buyer-{{ $buyer->id }}-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.admin.buyers.edit_title') }}</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $buyer->name }}</p>
            </div>

            <form method="POST" action="{{ route('admin.buyers.update', $buyer) }}" class="space-y-4 p-6">
                @csrf @method('PUT')

                <input type="hidden" name="id" value="{{ $buyer->id }}">
                <input type="hidden" name="dialog_id" value="edit-buyer-{{ $buyer->id }}">
                <input type="hidden" name="active_tab" value="admin-buyers">

                @php($isCurrentDialog = old('dialog_id') === 'edit-buyer-' . $buyer->id)

                @if ($isCurrentDialog && $errors->any())
                    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-5 text-rose-700">
                        {{ __('triproof.common.validation_errors') }}
                        <ul class="mt-1 list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div>
                    <label for="edit-buyer-name-{{ $buyer->id }}" class="label">{{ __('triproof.admin.buyers.name') }}</label>
                    <input id="edit-buyer-name-{{ $buyer->id }}" name="name" type="text" class="input" value="{{ $isCurrentDialog ? old('name') : $buyer->name }}" required>
                </div>

                <div>
                    <label for="edit-buyer-email-{{ $buyer->id }}" class="label">{{ __('triproof.admin.buyers.email') }}</label>
                    <input id="edit-buyer-email-{{ $buyer->id }}" name="email" type="email" class="input" value="{{ $isCurrentDialog ? old('email') : $buyer->email }}" required>
                </div>

                <div>
                    <label for="edit-buyer-password-{{ $buyer->id }}" class="label">{{ __('triproof.admin.buyers.password') }}</label>
                    <input id="edit-buyer-password-{{ $buyer->id }}" name="password" type="password" class="input">
                    <p class="hint mt-1">{{ __('triproof.admin.buyers.password_keep') }}</p>
                </div>

                <div>
                    <label for="edit-buyer-language-{{ $buyer->id }}" class="label">{{ __('triproof.admin.buyers.language') }}</label>
                    <select id="edit-buyer-language-{{ $buyer->id }}" name="language_pref" class="select">
                        <option value="en" {{ $isCurrentDialog ? (old('language_pref', $buyer->language_pref ?? 'en') === 'en' ? 'selected' : '') : (($buyer->language_pref ?? 'en') === 'en' ? 'selected' : '') }}>English</option>
                        <option value="bn" {{ $isCurrentDialog ? (old('language_pref', $buyer->language_pref) === 'bn' ? 'selected' : '') : ((($buyer->language_pref ?? 'en') === 'bn') ? 'selected' : '') }}>বাংলা</option>
                    </select>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('triproof.admin.buyers.edit') }}</button>
                </div>
            </form>
        </div>
    </dialog>
@endforeach
