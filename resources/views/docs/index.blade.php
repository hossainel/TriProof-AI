@extends('layouts.public')

@section('title', 'TriProof AI · Documentation & Pitch Deck')

@section('content')
<div class="mx-auto max-w-6xl">
    @if($previewActive)
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
            Admin Preview Mode — Public Access is Currently Restricted
        </div>
    @endif

    <div class="mb-8 border-b border-slate-200 pb-6">
        <p class="section-kicker">SIGNED AT SOURCE. VERIFIED EVERYWHERE.</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-900">TriProof AI Documentation &amp; Pitch Deck</h1>
        <p class="mt-2 text-sm text-slate-600">Understand the business in 2 minutes, evaluate the engineering in 10, and verify live system numbers.</p>
    </div>

    <div class="mb-8 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" id="live-kpis">
        @foreach([
            ['label' => 'Active Factories', 'value' => $telemetry['factories_active']],
            ['label' => 'Purchase Orders', 'value' => $telemetry['purchase_orders_count']],
            ['label' => 'Signed Serials', 'value' => $telemetry['signed_serials_count']],
            ['label' => 'Verified Scans', 'value' => $telemetry['verified_scans_count']],
            ['label' => 'Active Signing Keys', 'value' => $telemetry['active_signing_keys_count']],
        ] as $kpi)
            <div class="stat-card" data-kpi>
                <p class="text-[11px] font-semibold tracking-wide text-slate-500 uppercase">{{ $kpi['label'] }}</p>
                <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($kpi['value']) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-8 flex flex-wrap items-center gap-2" role="tablist" aria-label="Documentation mode">
        <button type="button" class="btn btn-primary text-xs" data-mode="all" role="tab" aria-selected="true">All-in-One View</button>
        <button type="button" class="btn btn-secondary text-xs" data-mode="pitch_deck" role="tab" aria-selected="false">YC Pitch Deck</button>
        <button type="button" class="btn btn-secondary text-xs" data-mode="technical" role="tab" aria-selected="false">Engineering Whitepaper</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <input id="docs-search" type="search" placeholder="Search docs (Ctrl+K or /)" class="input text-xs" aria-label="Search documentation">
            <button type="button" id="copy-link" class="btn btn-secondary text-xs" data-copied-label="Copied!">Copy Shareable Link</button>
            <a href="{{ route('docs.export.pdf') }}" class="btn btn-secondary text-xs">PDF</a>
            <a href="{{ route('docs.export.markdown') }}" class="btn btn-secondary text-xs">Markdown</a>
        </div>
    </div>

    <div id="search-results" class="mb-6 hidden rounded-xl border border-slate-200 bg-white p-4 text-sm"></div>
    <div id="docs-toast" class="fixed bottom-6 right-6 z-50 hidden rounded-lg bg-ink-950 px-4 py-2 text-sm text-white shadow-lg">Link copied</div>

    <div class="space-y-16" id="docs-content">
        @foreach($sections as $section)
            <section id="{{ $section->slug }}" data-category="{{ $section->category }}" data-search="{{ e(strip_tags($section->title_en.' '.$section->subtitle_en.' '.$section->liveMarkdown())) }}" class="doc-section prose prose-slate max-w-none">
                <h2 class="text-2xl font-bold text-slate-900">{{ $section->displayTitle(app()->getLocale()) }}</h2>
                @if($section->subtitle_en)
                    <p class="-mt-2 text-lg text-slate-500">{{ $section->displaySubtitle(app()->getLocale()) }}</p>
                @endif

                @if($section->diagram_mermaid)
                    <div class="my-6 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <pre class="mermaid">{{ $section->diagram_mermaid }}</pre>
                    </div>
                @endif

                <div class="mt-4">{!! \Illuminate\Support\Str::markdown($section->liveMarkdown()) !!}</div>
            </section>
        @endforeach

        <section id="live-feature-matrix" data-category="operations" data-search="live feature matrix subsystems telemetry" class="doc-section prose prose-slate max-w-none">
            <h2 class="text-2xl font-bold text-slate-900">Live Feature &amp; Endpoint Health Matrix</h2>
            <div class="not-prose mt-4 overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Subsystem</th><th>Status</th><th>Description</th></tr></thead>
                    <tbody>
                        @foreach($telemetry['subsystems'] as $subsystem)
                            <tr>
                                <td class="font-semibold">{{ $subsystem['name'] }}</td>
                                <td><span class="badge badge-success">{{ $subsystem['status'] }}</span></td>
                                <td class="text-slate-500">{{ $subsystem['description'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-sm text-slate-500">Endpoint inventory: {{ $telemetry['api_endpoints_count'] }} API routes · {{ $telemetry['web_routes_count'] }} web routes · last synced {{ $telemetry['timestamp'] }}</p>
        </section>

        <section id="team" data-category="pitch_deck" data-search="team contributors {{ e($teamName) }}" class="doc-section not-prose border-t border-slate-200 pt-16">
            <h2 class="text-2xl font-bold text-slate-900">{{ $teamName }}</h2>
            <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($team as $member)
                    <div class="flex flex-col items-center rounded-2xl border border-slate-200 bg-white p-5 text-center shadow-sm">
                        @if($member->photo_path)
                            <img src="{{ $member->photoUrl() }}" alt="{{ $member->full_name }}" class="h-28 w-28 rounded-2xl object-cover ring-2 ring-slate-100">
                        @else
                            <div class="flex h-28 w-28 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-100 to-sky-100 text-3xl font-bold text-emerald-800 ring-2 ring-slate-100">
                                {{ $member->initials() }}
                            </div>
                        @endif
                        <p class="mt-4 font-semibold text-slate-900">{{ $member->full_name }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $member->role_title }}</p>
                        <a href="mailto:{{ $member->email }}" class="mt-2 text-xs font-medium text-emerald-700 hover:underline">{{ $member->email }}</a>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
<script>
mermaid.initialize({ startOnLoad: true });

(function () {
    const sections = Array.from(document.querySelectorAll('.doc-section'));
    const search = document.getElementById('docs-search');
    const modeButtons = Array.from(document.querySelectorAll('[data-mode]'));

    function applyMode(mode) {
        sections.forEach((section) => {
            section.classList.toggle('hidden', mode !== 'all' && section.dataset.category !== mode);
        });
        modeButtons.forEach((button) => {
            const active = button.dataset.mode === mode;
            button.classList.toggle('btn-primary', active);
            button.classList.toggle('btn-secondary', !active);
            button.setAttribute('aria-selected', String(active));
        });
    }

    modeButtons.forEach((button) => button.addEventListener('click', () => applyMode(button.dataset.mode)));

    function runSearch(term) {
        const query = term.trim().toLowerCase();
        if (query.length < 2) return;
        const results = sections.filter((section) => (section.dataset.search || '').toLowerCase().includes(query));
        const panel = document.getElementById('search-results');
        panel.innerHTML = results.length
            ? results.map((section) => '<a class="block rounded px-2 py-1 hover:bg-slate-100" href="#' + section.id + '">' + (section.querySelector('h2')?.textContent || section.id) + '</a>').join('')
            : '<p class="text-slate-500">No matching sections.</p>';
        panel.classList.remove('hidden');
    }

    search?.addEventListener('input', (event) => runSearch(event.target.value));
    document.addEventListener('keydown', (event) => {
        const tag = document.activeElement?.tagName;
        if ((event.ctrlKey && event.key.toLowerCase() === 'k') || (event.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA')) {
            event.preventDefault();
            search?.focus();
        }
        if (event.key === 'Escape') document.getElementById('search-results').classList.add('hidden');
    });

    document.getElementById('copy-link')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        try {
            await navigator.clipboard.writeText(window.location.href.split('#')[0]);
            button.textContent = button.dataset.copiedLabel;
            document.getElementById('docs-toast').classList.remove('hidden');
            setTimeout(() => {
                button.textContent = 'Copy Shareable Link';
                document.getElementById('docs-toast').classList.add('hidden');
            }, 1500);
        } catch {
            // Clipboard permissions may be unavailable.
        }
    });
})();
</script>
@endsection
