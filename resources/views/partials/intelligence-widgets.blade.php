@php
    $lineage = $intelligence['lineage'] ?? ['nodes' => [], 'edges' => []];
    $serials = $intelligence['serials'] ?? ['estimate' => [], 'distribution' => [], 'scan_velocity' => []];
@endphp

<section class="mt-6 grid gap-4 lg:grid-cols-2" aria-label="Provenance and serial analytics">
    <div class="card p-4 sm:p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="section-kicker">D3.JS PROVENANCE</p>
                <h3 class="mt-1 text-sm font-semibold text-ink-950">Carton &amp; Rework Lineage</h3>
            </div>
            <span class="badge badge-info">{{ $lineage['node_count'] ?? 0 }} nodes</span>
        </div>
        <svg id="triproof-lineage-graph" class="mt-3 h-64 w-full rounded-xl border border-slate-200 bg-slate-50" role="img" aria-label="Interactive lineage graph"></svg>
        <script type="application/json" id="triproof-lineage-data">@json($lineage)</script>
    </div>

    <div class="card p-4 sm:p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="section-kicker">APACHE ECHARTS · GERMAN TANK</p>
                <h3 class="mt-1 text-sm font-semibold text-ink-950">Serial Distribution &amp; Scan Velocity</h3>
            </div>
            <span class="badge badge-success">N̂ {{ $serials['estimate']['estimate'] ?? '—' }}</span>
        </div>
        <div id="triproof-serial-chart" class="mt-3 h-64 w-full rounded-xl border border-slate-200 bg-slate-50" role="img" aria-label="Serial distribution histogram"></div>
        <script type="application/json" id="triproof-serial-analytics-data">@json($serials)</script>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
