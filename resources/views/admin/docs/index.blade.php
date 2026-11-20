@extends('layouts.app')

@section('page-title', 'Documentation Studio')

@section('content')
@php
    $pills = [
        'off' => 'OFFLINE',
        'scheduled' => 'SCHEDULED',
        'live' => 'PUBLIC LIVE',
    ];
    if (! $visibilityEnabled) {
        $statusKey = 'off';
    } elseif ($scheduleEnabled) {
        $statusKey = 'scheduled';
    } else {
        $statusKey = 'live';
    }
@endphp

<section class="card mb-6 p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="section-kicker">PUBLISHING CONTROL</p>
            <h2 class="mt-1 text-xl font-semibold tracking-tight text-ink-950">Documentation Visibility &amp; Scheduling</h2>
            <p class="mt-1 text-xs text-slate-500">Control public access to <code>/docs</code>, schedule showcase windows, and manage publishing.</p>
        </div>
        <span class="badge {{ $statusKey === 'live' ? 'badge-success' : ($statusKey === 'scheduled' ? 'badge-warning' : 'badge-danger') }}">{{ $pills[$statusKey] }}</span>
    </div>

    <form method="POST" action="{{ route('admin.docs.settings.update') }}" class="mt-6 grid gap-5 lg:grid-cols-2">
        @csrf
        @method('PUT')

        <div class="space-y-4">
            <label class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-3">
                <span class="text-sm font-medium text-ink-950">Public Visibility</span>
                <input type="hidden" name="docs_visibility_enabled" value="0">
                <input type="checkbox" name="docs_visibility_enabled" value="1" class="h-5 w-5" @checked($visibilityEnabled)>
            </label>

            <label class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-3">
                <span class="text-sm font-medium text-ink-950">Enforce Schedule Window</span>
                <input type="hidden" name="docs_schedule_enabled" value="0">
                <input type="checkbox" name="docs_schedule_enabled" value="1" class="h-5 w-5" @checked($scheduleEnabled)>
            </label>

            <div>
                <label class="input-label" for="docs_team_name">Team Display Name</label>
                <input id="docs_team_name" name="docs_team_name" class="input" value="{{ old('docs_team_name', $teamName) }}">
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-secondary text-xs" data-schedule-preset="24h">24 Hours</button>
                <button type="button" class="btn btn-secondary text-xs" data-schedule-preset="4d">4 Days</button>
                <button type="button" class="btn btn-secondary text-xs" data-schedule-preset="7d">7 Days</button>
                <button type="button" class="btn btn-secondary text-xs" data-schedule-preset="always">Always Open</button>
            </div>
        </div>

        <div class="space-y-4">
            <div>
                <label class="input-label" for="docs_available_from">Available From</label>
                <input id="docs_available_from" name="docs_available_from" type="datetime-local" class="input" value="{{ old('docs_available_from', $availableFrom ? \Illuminate\Support\Carbon::parse($availableFrom)->format('Y-m-d\TH:i') : '') }}">
            </div>
            <div>
                <label class="input-label" for="docs_available_until">Available Until</label>
                <input id="docs_available_until" name="docs_available_until" type="datetime-local" class="input" value="{{ old('docs_available_until', $availableUntil ? \Illuminate\Support\Carbon::parse($availableUntil)->format('Y-m-d\TH:i') : '') }}">
            </div>
            <div class="flex flex-wrap gap-2 pt-2">
                <button type="submit" name="override" value="publish" class="btn btn-primary text-xs">Publish Now</button>
                <button type="submit" name="override" value="disable" class="btn btn-danger text-xs">Disable Now</button>
                <button type="submit" class="btn btn-secondary text-xs">Save Schedule</button>
            </div>
            <p class="text-[11px] text-slate-400">Preview restricted pages as an admin with <code>/docs?preview=1</code>.</p>
        </div>
    </form>
</section>

<section class="card mb-6 p-5 sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="section-kicker">CONTENT</p>
            <h2 class="mt-1 text-xl font-semibold tracking-tight text-ink-950">Sections &amp; Drag-and-Drop Order</h2>
        </div>
        <a href="{{ route('docs.index') }}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary text-xs">View Public Docs</a>
    </div>

    <div id="docs-sections" class="mt-5 space-y-3">
        @foreach($sections as $section)
            <article draggable="true" data-section-id="{{ $section->id }}" class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="cursor-grab text-slate-400" title="Drag to reorder" aria-hidden="true">⠿</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-ink-950">{{ $section->title_en }}</p>
                        <p class="truncate text-[11px] text-slate-500">{{ $section->category }} · #{{ $section->slug }} · {{ $section->status }}</p>
                    </div>
                    <span class="badge {{ $section->is_visible ? 'badge-success' : 'badge-danger' }}">{{ $section->is_visible ? 'VISIBLE' : 'HIDDEN' }}</span>
                    <button type="button" class="btn-icon" data-move="up" title="Move up" aria-label="Move up">↑</button>
                    <button type="button" class="btn-icon" data-move="down" title="Move down" aria-label="Move down">↓</button>
                    <button type="button" class="btn btn-secondary text-xs" data-edit-toggle="{{ $section->id }}">Edit</button>
                </div>

                <div id="editor-{{ $section->id }}" class="mt-4 hidden border-t border-slate-200 pt-4">
                    <form method="POST" action="{{ route('admin.docs.sections.update', $section) }}" class="grid gap-4 lg:grid-cols-2" data-section-form>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="action" value="save_draft" data-action-input>

                        <div class="space-y-3">
                            <div>
                                <label class="input-label">Title (EN)</label>
                                <input name="title_en" class="input" value="{{ old('title_en', $section->title_en) }}">
                            </div>
                            <div>
                                <label class="input-label">Title (BN)</label>
                                <input name="title_bn" class="input" value="{{ old('title_bn', $section->title_bn) }}">
                            </div>
                            <div>
                                <label class="input-label">Subtitle (EN)</label>
                                <input name="subtitle_en" class="input" value="{{ old('subtitle_en', $section->subtitle_en) }}">
                            </div>
                            <div>
                                <label class="input-label">Category</label>
                                <select name="category" class="input">
                                    @foreach(['pitch_deck', 'technical', 'operations'] as $cat)
                                        <option value="{{ $cat }}" @selected($section->category === $cat)>{{ $cat }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                <input type="hidden" name="is_visible" value="0">
                                <input type="checkbox" name="is_visible" value="1" @checked($section->is_visible)> Visible on public docs
                            </label>
                            <div>
                                <label class="input-label">Mermaid Diagram Source</label>
                                <textarea name="diagram_mermaid" rows="5" class="input font-mono text-xs" data-mermaid-source>{{ $section->diagram_mermaid }}</textarea>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label class="input-label">Published Markdown</label>
                                <textarea name="content_markdown" rows="9" class="input font-mono text-xs" data-markdown-source>{{ $section->content_markdown }}</textarea>
                            </div>
                            <div>
                                <label class="input-label">Draft Markdown</label>
                                <textarea name="draft_markdown" rows="5" class="input font-mono text-xs" data-markdown-draft>{{ $section->draft_markdown }}</textarea>
                            </div>
                            <div>
                                <label class="input-label">Change Note</label>
                                <input name="change_note" class="input" placeholder="What changed?">
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-white p-3">
                                <p class="mb-2 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Live Preview</p>
                                <div class="prose prose-sm max-w-none" data-markdown-preview>{!! \Illuminate\Support\Str::markdown($section->liveMarkdown()) !!}</div>
                                <pre class="mermaid mt-3" data-mermaid-preview>{{ $section->diagram_mermaid }}</pre>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-secondary text-xs" data-publish-action="save_draft">Save Draft</button>
                                <button type="submit" class="btn btn-primary text-xs" data-publish-action="publish">Publish Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </article>
        @endforeach
    </div>
    <button type="button" id="save-order" data-reorder-url="{{ route('admin.docs.reorder') }}" class="btn btn-primary mt-4 text-xs">Save Section Order</button>
</section>

<section class="card mb-6 p-5 sm:p-6">
    <p class="section-kicker">GOVERNANCE</p>
    <h2 class="mt-1 text-xl font-semibold tracking-tight text-ink-950">Version History</h2>
    <div class="mt-4 overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Version</th><th>Section</th><th>Note</th><th>Created</th><th></th></tr></thead>
            <tbody>
                @forelse($revisions as $revision)
                    <tr>
                        <td class="font-mono text-xs">{{ $revision->version_label }}</td>
                        <td>{{ $revision->section?->title_en ?? 'Full snapshot' }}</td>
                        <td>{{ $revision->change_note ?: '—' }}</td>
                        <td>{{ $revision->created_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.docs.revisions.restore', $revision) }}">
                                @csrf
                                <button type="submit" class="btn btn-secondary text-xs" onclick="return confirm('Restore this revision? Current published content will be replaced.')">Restore</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-slate-500">No published revisions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card p-5 sm:p-6">
    <p class="section-kicker">PEOPLE</p>
    <h2 class="mt-1 text-xl font-semibold tracking-tight text-ink-950">Team Member Manager</h2>

    <div class="mt-5 grid gap-6 lg:grid-cols-2">
        <div class="space-y-3">
            @foreach($team as $member)
                <div class="flex items-center gap-3 rounded-xl border border-slate-200 p-3">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 text-sm font-bold text-slate-600">
                        @if($member->photo_path)
                            <img src="{{ $member->photoUrl() }}" alt="{{ $member->full_name }}" class="h-full w-full object-cover">
                        @else
                            {{ $member->initials() }}
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-ink-950">{{ $member->full_name }}</p>
                        <p class="truncate text-[11px] text-slate-500">{{ $member->role_title }}</p>
                    </div>
                    <button type="button" class="btn btn-secondary text-xs" data-team-edit-toggle="{{ $member->id }}">Edit</button>
                    <form method="POST" action="{{ route('admin.docs.team.destroy', $member) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger text-xs" onclick="return confirm('Remove this member?')">Delete</button>
                    </form>
                </div>

                <form id="team-{{ $member->id }}" method="POST" action="{{ route('admin.docs.team.update', $member) }}" enctype="multipart/form-data" class="hidden grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    @csrf
                    @method('PUT')
                    <input name="full_name" class="input" value="{{ $member->full_name }}" placeholder="Full name">
                    <input name="role_title" class="input" value="{{ $member->role_title }}" placeholder="Role title">
                    <input name="email" type="email" class="input" value="{{ $member->email }}" placeholder="Email">
                    <input name="bio_short" class="input" value="{{ $member->bio_short }}" placeholder="Short bio">
                    <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" class="input text-xs">
                    <input name="sort_order" type="number" class="input" value="{{ $member->sort_order }}">
                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($member->is_active)> Active
                    </label>
                    <button type="submit" class="btn btn-primary text-xs">Save Member</button>
                </form>
            @endforeach
        </div>

        <form method="POST" action="{{ route('admin.docs.team.store') }}" enctype="multipart/form-data" class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
            @csrf
            <p class="text-sm font-semibold text-ink-950">Add Team Member</p>
            <input name="full_name" class="input" placeholder="Full name" required>
            <input name="role_title" class="input" placeholder="Role title" required>
            <input name="email" type="email" class="input" placeholder="Email" required>
            <input name="bio_short" class="input" placeholder="Short bio">
            <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" class="input text-xs">
            <label class="flex items-center gap-2 text-xs text-slate-600">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" checked> Active
            </label>
            <button type="submit" class="btn btn-primary text-xs">Add Member</button>
        </form>
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const list = document.getElementById('docs-sections');
    const saveOrder = document.getElementById('save-order');

    function orderedIds() {
        return Array.from(list.querySelectorAll('[data-section-id]')).map((el) => el.dataset.sectionId);
    }

    async function persistOrder() {
        const response = await fetch(saveOrder?.dataset.reorderUrl || '', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ order: orderedIds() }),
        });
        if (response.ok) {
            saveOrder.textContent = 'Order Saved';
            setTimeout(() => { saveOrder.textContent = 'Save Section Order'; }, 1600);
        }
    }

    saveOrder?.addEventListener('click', persistOrder);

    list?.addEventListener('click', (event) => {
        const moveButton = event.target.closest('[data-move]');
        if (!moveButton) return;
        const card = moveButton.closest('[data-section-id]');
        if (!card) return;
        if (moveButton.dataset.move === 'up' && card.previousElementSibling) {
            list.insertBefore(card, card.previousElementSibling);
        } else if (moveButton.dataset.move === 'down' && card.nextElementSibling) {
            list.insertBefore(card.nextElementSibling, card);
        }
        persistOrder();
    });

    list?.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-edit-toggle]');
        if (!toggle) return;
        const editor = document.getElementById('editor-' + toggle.dataset.editToggle);
        if (editor) editor.classList.toggle('hidden');
    });

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-team-edit-toggle]');
        if (!toggle) return;
        const form = document.getElementById('team-' + toggle.dataset.teamEditToggle);
        if (form) form.classList.toggle('hidden');
    });

    document.querySelectorAll('[data-publish-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = button.closest('form');
            if (form) form.querySelector('[data-action-input]').value = button.dataset.publishAction;
        });
    });

    document.querySelectorAll('[data-markdown-source]').forEach((textarea) => {
        textarea.addEventListener('input', () => {
            const preview = textarea.closest('form').querySelector('[data-markdown-preview]');
            if (!preview) return;
            const lines = textarea.value.split('\n');
            preview.innerHTML = lines.map((line) => {
                if (line.startsWith('### ')) return '<h4>' + line.slice(4) + '</h4>';
                if (line.startsWith('## ')) return '<h3>' + line.slice(3) + '</h3>';
                if (line.startsWith('# ')) return '<h2>' + line.slice(2) + '</h2>';
                if (line.trim() === '') return '<br>';
                return '<p>' + line.replace(/[&<>]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</p>';
            }).join('');
        });
    });

    document.querySelectorAll('[data-schedule-preset]').forEach((button) => {
        button.addEventListener('click', () => {
            const from = document.getElementById('docs_available_from');
            const until = document.getElementById('docs_available_until');
            const start = new Date();
            let end = new Date(start);
            const preset = button.dataset.schedulePreset;
            if (preset === '24h') end.setHours(end.getHours() + 24);
            else if (preset === '4d') end.setDate(end.getDate() + 4);
            else if (preset === '7d') end.setDate(end.getDate() + 7);
            else {
                document.querySelector('[name="docs_schedule_enabled"][value="1"]').checked = false;
                return;
            }
            const format = (d) => d.toISOString().slice(0, 16);
            from.value = format(start);
            until.value = format(end);
            document.querySelector('[name="docs_schedule_enabled"][value="1"]').checked = true;
        });
    });

    document.querySelectorAll('form').forEach((form) => {
        const override = form.querySelector('[name="override"]:focus');
        if (!override) return;
    });
})();
</script>
@if(config('app.debug'))
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
<script>mermaid.initialize({ startOnLoad: true });</script>
@endif
@endpush
