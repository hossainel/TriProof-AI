<dialog id="add-operator-dialog" class="tp-dialog" aria-labelledby="add-operator-title">
    <div class="tp-dialog-panel">
        <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
            <x-icon name="close" class="h-5 w-5" />
        </button>

        <div class="border-b border-slate-100 px-6 pt-6 pb-4">
            <span class="badge badge-success">{{ __('triproof.admin.operators.add') }}</span>
            <h2 id="add-operator-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.admin.operators.add_title') }}</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('triproof.admin.operators.subtitle') }}</p>
        </div>

        <form method="POST" action="{{ route('admin.operators.store') }}" class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="dialog_id" value="add-operator-dialog">

            @php($isCurrentDialog = old('dialog_id') === 'add-operator-dialog')

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
                <label for="add-operator-name" class="label">{{ __('triproof.admin.operators.name') }}</label>
                <input id="add-operator-name" name="name" type="text" class="input" value="{{ $isCurrentDialog ? old('name') : '' }}" required>
            </div>

            <div>
                <label for="add-operator-email" class="label">{{ __('triproof.admin.operators.factory') }}</label>
                <input id="add-operator-email" name="email" type="email" class="input" value="{{ $isCurrentDialog ? old('email') : '' }}" required>
            </div>

            <div>
                <label for="add-operator-password" class="label">{{ __('triproof.admin.buyers.password') }}</label>
                <input id="add-operator-password" name="password" type="password" class="input" required>
                <p class="hint mt-1">{{ __('triproof.admin.buyers.password_hint') }}</p>
            </div>

            <div>
                <label for="add-operator-factory" class="label">{{ __('triproof.admin.operators.factory') }}</label>
                <select id="add-operator-factory" name="factory_id" class="select" required>
                    <option value="">{{ __('triproof.forms.create_order.select_factory') }}</option>
                    @foreach ($factories as $factory)
                        <option value="{{ $factory->code }}" {{ $isCurrentDialog ? (old('factory_id') === $factory->code ? 'selected' : '') : (($operator->factory_id ?? null) === $factory->code ? 'selected' : '') }}>
                            {{ $factory->code }} · {{ $factory->name_en }}
                        </option>
                    @endforeach
                </select>
                @if ($factories->isEmpty())
                    <p class="hint mt-1 text-rose-600">{{ __('triproof.admin.operators.no_factories') }}</p>
                @endif
            </div>

            <div>
                <label for="add-operator-language" class="label">{{ __('triproof.admin.buyers.language') }}</label>
                <select id="add-operator-language" name="language_pref" class="select">
                    <option value="en" {{ $isCurrentDialog ? (old('language_pref', 'en') === 'en' ? 'selected' : '') : ('en' === 'en' ? 'selected' : '') }}>English</option>
                    <option value="bn" {{ $isCurrentDialog ? (old('language_pref') === 'bn' ? 'selected' : '') : ('' === 'bn' ? 'selected' : '') }}>বাংলা</option>
                </select>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('triproof.admin.operators.add') }}</button>
            </div>
        </form>
    </div>
</dialog>

@foreach ($operators as $operator)
    <dialog id="edit-operator-{{ $operator->id }}" class="tp-dialog" aria-labelledby="edit-operator-{{ $operator->id }}-title">
        <div class="tp-dialog-panel">
            <button type="button" class="dialog-close" data-close-dialog aria-label="{{ __('triproof.common.close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>

            <div class="border-b border-slate-100 px-6 pt-6 pb-4">
                <span class="badge badge-info">{{ __('triproof.admin.operators.edit') }}</span>
                <h2 id="edit-operator-{{ $operator->id }}-title" class="mt-3 text-xl font-semibold tracking-tight text-ink-950">{{ __('triproof.admin.operators.edit_title') }}</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $operator->name }}</p>
            </div>

            <form method="POST" action="{{ route('admin.operators.update', $operator) }}" class="space-y-4 p-6">
                @csrf @method('PUT')

                <input type="hidden" name="id" value="{{ $operator->id }}">
                <input type="hidden" name="dialog_id" value="edit-operator-{{ $operator->id }}">

                @php($isCurrentDialog = old('dialog_id') === 'edit-operator-' . $operator->id)

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
                    <label for="edit-operator-name-{{ $operator->id }}" class="label">{{ __('triproof.admin.operators.name') }}</label>
                    <input id="edit-operator-name-{{ $operator->id }}" name="name" type="text" class="input" value="{{ $isCurrentDialog ? old('name') : $operator->name }}" required>
                </div>

                <div>
                    <label for="edit-operator-email-{{ $operator->id }}" class="label">{{ __('triproof.admin.operators.factory') }}</label>
                    <input id="edit-operator-email-{{ $operator->id }}" name="email" type="email" class="input" value="{{ $isCurrentDialog ? old('email') : $operator->email }}" required>
                </div>

                <div>
                    <label for="edit-operator-password-{{ $operator->id }}" class="label">{{ __('triproof.admin.buyers.password') }}</label>
                    <input id="edit-operator-password-{{ $operator->id }}" name="password" type="password" class="input">
                    <p class="hint mt-1">{{ __('triproof.admin.buyers.password_keep') }}</p>
                </div>

                <div>
                    <label for="edit-operator-factory-{{ $operator->id }}" class="label">{{ __('triproof.admin.operators.factory') }}</label>
                    <select id="edit-operator-factory-{{ $operator->id }}" name="factory_id" class="select" required>
                        <option value="">{{ __('triproof.forms.create_order.select_factory') }}</option>
                        @foreach ($factories as $factory)
                            <option value="{{ $factory->code }}" {{ $isCurrentDialog ? (old('factory_id') === $factory->code ? 'selected' : '') : (($operator->factory_id ?? null) === $factory->code ? 'selected' : '') }}>
                                {{ $factory->code }} · {{ $factory->name_en }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="edit-operator-language-{{ $operator->id }}" class="label">{{ __('triproof.admin.buyers.language') }}</label>
                    <select id="edit-operator-language-{{ $operator->id }}" name="language_pref" class="select">
                        <option value="en" {{ $isCurrentDialog ? (old('language_pref', $operator->language_pref ?? 'en') === 'en' ? 'selected' : '') : ((($operator->language_pref ?? 'en') === 'en') ? 'selected' : '') }}>English</option>
                        <option value="bn" {{ $isCurrentDialog ? (old('language_pref', $operator->language_pref) === 'bn' ? 'selected' : '') : (((($operator->language_pref ?? 'en') === 'bn')) ? 'selected' : '') }}>বাংলা</option>
                    </select>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('triproof.common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('triproof.admin.operators.edit') }}</button>
                </div>
            </form>
        </div>
    </dialog>
@endforeach
