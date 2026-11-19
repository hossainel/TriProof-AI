<dialog id="add-factory-dialog" class="tp-dialog" aria-labelledby="add-factory-title">
    <div class="tp-dialog-panel">
        <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
            <x-icon name="close" class="h-5 w-5" />
        </button>

        <div class="border-b border-slate-100 px-6 pt-6 pb-4">
            <span class="badge badge-success">{{ __('triproof.admin.factories.add') }}</span>
            <h2 id="add-factory-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.admin.factories.add_title') }}</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('triproof.admin.factories.subtitle') }}</p>
        </div>

        <form method="POST" action="{{ route('admin.factories.store') }}" class="space-y-4 p-6">
            @csrf

            <div>
                <label for="add-factory-code" class="label">{{ __('triproof.admin.factories.code') }}</label>
                <input id="add-factory-code" name="code" type="text" class="input input-uppercase" maxlength="32" placeholder="BD06" value="{{ old('code') }}" required>
                <p class="hint mt-1">{{ __('triproof.admin.factories.code_hint') }}</p>
            </div>

            <div>
                <label for="add-factory-name-en" class="label">{{ __('triproof.admin.factories.name_en') }}</label>
                <input id="add-factory-name-en" name="name_en" type="text" class="input" value="{{ old('name_en') }}" required>
            </div>

            <div>
                <label for="add-factory-name-bn" class="label">{{ __('triproof.admin.factories.name_bn') }}</label>
                <input id="add-factory-name-bn" name="name_bn" type="text" class="input" value="{{ old('name_bn') }}">
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="add-factory-location" class="label">{{ __('triproof.admin.factories.location') }}</label>
                    <input id="add-factory-location" name="location" type="text" class="input" value="{{ old('location') }}">
                </div>
                <div>
                    <label for="add-factory-contact-email" class="label">{{ __('triproof.admin.factories.contact_email') }}</label>
                    <input id="add-factory-contact-email" name="contact_email" type="email" class="input" value="{{ old('contact_email') }}">
                </div>
            </div>

            <div class="flex items-center gap-2">
                <input id="add-factory-is-active" name="is_active" type="checkbox" class="checkbox" {{ old('is_active', true) ? 'checked' : '' }}>
                <label for="add-factory-is-active" class="label">{{ __('triproof.admin.factories.active') }}</label>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('triproof.admin.factories.add') }}</button>
            </div>
        </form>
    </div>
</dialog>

@foreach ($factories as $factory)
    <dialog id="edit-factory-{{ $factory->id }}" class="tp-dialog" aria-labelledby="edit-factory-{{ $factory->id }}-title">
        <div class="tp-dialog-panel">
            <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>

            <div class="border-b border-slate-100 px-6 pt-6 pb-4">
                <span class="badge badge-info">{{ __('triproof.admin.factories.edit') }}</span>
                <h2 id="edit-factory-{{ $factory->id }}-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.admin.factories.edit_title') }}</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $factory->code }} · {{ $factory->name_en }}</p>
            </div>

            <form method="POST" action="{{ route('admin.factories.update', $factory) }}" class="space-y-4 p-6">
                @csrf @method('PUT')

                <input type="hidden" name="id" value="{{ $factory->id }}">

                <div>
                    <label for="edit-factory-code-{{ $factory->id }}" class="label">{{ __('triproof.admin.factories.code') }}</label>
                    <input id="edit-factory-code-{{ $factory->id }}" name="code" type="text" class="input input-uppercase" maxlength="32" value="{{ old('code', $factory->code) }}" required>
                </div>

                <div>
                    <label for="edit-factory-name-en-{{ $factory->id }}" class="label">{{ __('triproof.admin.factories.name_en') }}</label>
                    <input id="edit-factory-name-en-{{ $factory->id }}" name="name_en" type="text" class="input" value="{{ old('name_en', $factory->name_en) }}" required>
                </div>

                <div>
                    <label for="edit-factory-name-bn-{{ $factory->id }}" class="label">{{ __('triproof.admin.factories.name_bn') }}</label>
                    <input id="edit-factory-name-bn-{{ $factory->id }}" name="name_bn" type="text" class="input" value="{{ old('name_bn', $factory->name_bn) }}">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="edit-factory-location-{{ $factory->id }}" class="label">{{ __('triproof.admin.factories.location') }}</label>
                        <input id="edit-factory-location-{{ $factory->id }}" name="location" type="text" class="input" value="{{ old('location', $factory->location) }}">
                    </div>
                    <div>
                        <label for="edit-factory-contact-email-{{ $factory->id }}" class="label">{{ __('triproof.admin.factories.contact_email') }}</label>
                        <input id="edit-factory-contact-email-{{ $factory->id }}" name="contact_email" type="email" class="input" value="{{ old('contact_email', $factory->contact_email) }}">
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input id="edit-factory-is-active-{{ $factory->id }}" name="is_active" type="checkbox" class="checkbox" {{ old('is_active', $factory->is_active) ? 'checked' : '' }}>
                    <label for="edit-factory-is-active-{{ $factory->id }}" class="label">{{ __('triproof.admin.factories.active') }}</label>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('triproof.admin.factories.edit') }}</button>
                </div>
            </form>
        </div>
    </dialog>
@endforeach
