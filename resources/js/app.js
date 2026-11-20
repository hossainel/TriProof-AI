import './bootstrap';
import Chart from 'chart.js/auto';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const ready = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
};

function byId(rawId) {
    if (typeof rawId !== 'string') return null;
    const id = rawId.replace(/^#/, '').trim();
    return id.length > 0 ? document.getElementById(id) : null;
}

ready(() => {
    initSidebar();
    initNavigationAndTabs();
    initDialogs();
    initAutoOpenDialog();
    initLocaleSwitch();
    initChart();
    initMap();
    initCopyButtons();
    initFormSubmissions();
});

/* ---------- Sidebar drawer ---------- */
function openSidebar() {
    const sidebar = byId('app-sidebar');
    const overlay = byId('sidebar-overlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.remove('-translate-x-full');
    sidebar.classList.add('translate-x-0');
    overlay.classList.remove('hidden');
    if (window.innerWidth < 1024) {
        document.body.classList.add('overflow-hidden');
    }
}

function closeSidebar() {
    const sidebar = byId('app-sidebar');
    const overlay = byId('sidebar-overlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.add('-translate-x-full');
    sidebar.classList.remove('translate-x-0');
    overlay.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
}

function initSidebar() {
    const sidebar = byId('app-sidebar');
    const overlay = byId('sidebar-overlay');
    if (!sidebar || !overlay) return;

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024) {
            document.body.classList.remove('overflow-hidden');
            overlay.classList.add('hidden');
        }
    });

    sidebar.addEventListener('click', (event) => {
        const link = event.target.closest('#app-sidebar a');
        if (link && window.innerWidth < 1024) {
            closeSidebar();
        }
    });

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-sidebar-toggle]');
        if (toggle) {
            event.preventDefault();
            openSidebar();
            return;
        }
        const close = event.target.closest('[data-sidebar-close]') || event.target.closest('#sidebar-overlay');
        if (close) {
            event.preventDefault();
            closeSidebar();
        }
    });
}

/* ---------- Navigation & tab sync ---------- */
function initNavigationAndTabs() {
    const tablist = document.querySelector('ul[tabs]') || document.querySelector('ul[role="tablist"]');
    if (tablist) {
        tablist.addEventListener('click', (event) => {
            const link = event.target.closest('a[role="tab"]');
            if (!link) return;
            event.preventDefault();
            const hash = (link.getAttribute('href') || '').replace(/^#/, '');
            syncNavigationAndTabs(hash);
        });
    }
    window.addEventListener('hashchange', () => {
        const hash = window.location.hash.replace(/^#/, '').trim();
        if (!hash && !document.body.dataset.activeTab) return;
        syncNavigationAndTabs(hash);
    });
    syncNavigationAndTabs();
}

function syncNavigationAndTabs(hashOverride) {
    const tablist = document.querySelector('ul[tabs]') || document.querySelector('ul[role="tablist"]');
    const hashFromUrl = window.location.hash.replace(/^#/, '');
    const hashFromBody = document.body.dataset.activeTab || '';
    const targetHash = hashOverride || hashFromUrl || hashFromBody || '';

    if (tablist) {
        const tabs = Array.from(tablist.querySelectorAll('a[role="tab"]'));
        const dataActiveTab = tablist.dataset.activeTab || '';
        const resolveHash = () => {
            if (targetHash) return targetHash;
            if (dataActiveTab) return dataActiveTab;
            if (tabs[0]) return (tabs[0].getAttribute('href') || '').replace(/^#/, '');
            return '';
        };
        const hash = resolveHash();

        tabs.forEach((t) => {
            const h = (t.getAttribute('href') || '').replace(/^#/, '');
            const isActive = h === hash;
            t.classList.toggle('tab-active', isActive);
            t.setAttribute('aria-selected', String(isActive));
        });
        document.querySelectorAll('.tab-panel').forEach((panel) => {
            const isActive = panel.id === hash;
            panel.classList.toggle('hidden', !isActive);
        });
        document.querySelectorAll('[data-sidebar-hash]').forEach((link) => {
            link.classList.toggle('nav-link-active', link.dataset.sidebarHash === hash);
        });
        if (window.location.hash.replace(/^#/, '') !== hash) {
            window.history.replaceState(null, '', '#' + hash);
        }
    }

    document.querySelectorAll('#app-sidebar .nav-link').forEach((link) => {
        const href = link.getAttribute('href') || '';
        const linkHash = href.replace(/^#/, '');
        if (targetHash && linkHash === targetHash && window.innerWidth >= 1024) {
            link.classList.add('nav-link-active');
            const target = byId(targetHash);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        } else if (href.includes('#') && !href.includes('admin.manage')) {
            link.classList.remove('nav-link-active');
        }
    });
    document.querySelectorAll('#app-sidebar .nav-link[data-sidebar-hash]').forEach((link) => {
        link.classList.toggle('nav-link-active', link.dataset.sidebarHash === targetHash);
    });
}

/* ---------- Dialog open/close helpers ---------- */
function openTpDialog(dialog, triggerBtn = null) {
    if (!dialog) return;
    dialog.classList.remove('hidden');

    if (triggerBtn) {
        const form = dialog.querySelector('form[data-dynamic-action]') || dialog.querySelector('form');
        if (form && triggerBtn.dataset.action) {
            form.setAttribute('action', triggerBtn.dataset.action);
        }
        dialog.querySelectorAll('[data-dialog-context]').forEach((el) => {
            el.textContent = triggerBtn.dataset.context || '';
        });

        if (triggerBtn.dataset.remaining !== undefined && form) {
            const remaining = Math.max(0, Number(triggerBtn.dataset.remaining) || 0);
            const count = form.elements.namedItem('count');
            if (count instanceof HTMLInputElement) {
                count.max = String(remaining);
                count.value = String(Math.min(Math.max(1, Number(count.value || 1)), remaining));
            }
            const remainingLabel = dialog.querySelector('[data-remaining-label]');
            if (remainingLabel && dialog.dataset.remainingTemplate) {
                remainingLabel.textContent = dialog.dataset.remainingTemplate.replaceAll('__COUNT__', remaining);
            }
            const submit = dialog.querySelector('[data-submit-label]');
            if (submit && dialog.dataset.countTemplate && count) {
                submit.textContent = dialog.dataset.countTemplate.replaceAll('__COUNT__', count.value);
                count.addEventListener('input', () => {
                    submit.textContent = dialog.dataset.countTemplate.replaceAll('__COUNT__', count.value);
                });
            }
        }

        if (dialog.id === 'recall-dialog') {
            if (triggerBtn.dataset.resetScope) {
                ['recall-factory', 'recall-machine', 'recall-batch'].forEach((id) => {
                    const el = dialog.querySelector('#' + id);
                    if (el) el.value = '';
                });
            }

            const scopeValues = {
                'recall-factory': triggerBtn.dataset.factory,
                'recall-machine': triggerBtn.dataset.machine,
                'recall-batch': triggerBtn.dataset.batch,
            };

            Object.entries(scopeValues).forEach(([id, value]) => {
                if (!value) return;
                const el = dialog.querySelector('#' + id);
                if (el) el.value = value;
            });
        }

        if (triggerBtn.dataset.merkleUrl) {
            const loading = dialog.querySelector('[data-merkle-loading]');
            const body = dialog.querySelector('[data-merkle-body]');
            const error = dialog.querySelector('[data-merkle-error]');
            if (loading) loading.classList.remove('hidden');
            if (body) body.classList.add('hidden');
            if (error) error.classList.add('hidden');
            fetch(triggerBtn.dataset.merkleUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((resp) => resp.json())
                .then((payload) => {
                    const data = payload?.data || {};
                    const set = (selector, value) => {
                        const el = dialog.querySelector(selector);
                        if (el) el.textContent = value;
                    };
                    set('[data-merkle-stored]', data.stored_root || dialog.dataset.noRootLabel || '—');
                    set('[data-merkle-recomputed]', data.recomputed_root || dialog.dataset.noRootLabel || '—');
                    set('[data-merkle-leaves]', Number(data.leaf_count || 0).toLocaleString());
                    set('[data-merkle-generated]', Number(data.generated_count || 0).toLocaleString());
                    set('[data-merkle-algorithm]', data.algorithm || '—');
                    const matches = dialog.querySelector('[data-merkle-matches]');
                    if (matches) {
                        matches.textContent = data.matches
                            ? dialog.dataset.matchLabel || 'Roots match'
                            : dialog.dataset.mismatchLabel || 'Roots differ';
                        matches.className = `badge ${data.matches ? 'badge-success' : 'badge-danger'}`;
                    }
                    const consistent = dialog.querySelector('[data-merkle-consistent]');
                    if (consistent) {
                        consistent.textContent = data.consistent
                            ? dialog.dataset.consistentLabel || 'Registry consistent'
                            : dialog.dataset.inconsistentLabel || 'Registry mismatch';
                        consistent.className = `badge ${data.consistent ? 'badge-info' : 'badge-warning'}`;
                    }
                    if (body) body.classList.remove('hidden');
                })
                .catch((exception) => {
                    if (error) {
                        error.textContent = exception.response?.data?.error?.message || dialog.dataset.errorLabel || 'The Merkle proof could not be loaded.';
                        error.classList.remove('hidden');
                    }
                })
                .finally(() => {
                    if (loading) loading.classList.add('hidden');
                });
        }
    }

    if (dialog.id === 'pack-dialog') {
        initPackDialog(dialog, triggerBtn);
    }

    if (dialog.hasAttribute('open')) {
        dialog.removeAttribute('open');
    }

    if (!dialog.open) {
        if (typeof dialog.showModal === 'function') {
            try {
                dialog.showModal();
            } catch {
                dialog.setAttribute('open', '');
            }
        } else {
            dialog.setAttribute('open', '');
        }
    }
}

function closeTpDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === 'function' && dialog.open) {
        dialog.close();
    }
    dialog.removeAttribute('open');
}

/* ---------- Pack dialog suggestions ---------- */
let packableDataCache = null;

function getPackableData() {
    if (packableDataCache !== null) return packableDataCache;

    const dataElement = byId('triproof-packable-data');
    if (!dataElement) {
        packableDataCache = {};
        return packableDataCache;
    }

    try {
        packableDataCache = JSON.parse(dataElement.textContent || '{}');
    } catch {
        packableDataCache = {};
    }

    return packableDataCache;
}

function uppercaseTrim(value) {
    return String(value || '').trim().toUpperCase();
}

function fillTemplate(template, replacements) {
    let output = String(template || '');
    Object.entries(replacements).forEach(([key, value]) => {
        output = output.replaceAll(`__${key}__`, String(value));
    });
    return output;
}

function findPackableBatch(batches, machine, batch) {
    const normalizedMachine = uppercaseTrim(machine);
    const normalizedBatch = uppercaseTrim(batch);

    if (!normalizedMachine && !normalizedBatch) return null;

    const matches = batches.filter((entry) => {
        const entryMachine = uppercaseTrim(entry.machine_id);
        const entryBatch = uppercaseTrim(entry.batch_id);
        return (!normalizedMachine || entryMachine === normalizedMachine)
            && (!normalizedBatch || entryBatch === normalizedBatch);
    });

    return matches.find((entry) => Number(entry.remaining_count) > 0) || matches[0] || null;
}

function initPackDialog(dialog, triggerBtn) {
    const machineInput = dialog.querySelector('#pack-machine');
    const batchInput = dialog.querySelector('#pack-batch');
    const startInput = dialog.querySelector('#pack-start');
    const endInput = dialog.querySelector('#pack-end');

    if (dialog.dataset.packListenersBound !== '1') {
        const updateSuggestions = () => updatePackDialogSuggestions(dialog);

        machineInput?.addEventListener('input', updateSuggestions);
        batchInput?.addEventListener('input', updateSuggestions);

        dialog.querySelector('[data-pack-use-range]')?.addEventListener('click', (event) => {
            event.preventDefault();
            const min = dialog.dataset.packMin || '';
            const max = dialog.dataset.packMax || '';
            if (min && startInput) startInput.value = min;
            if (max && endInput) endInput.value = max;
        });

        dialog.addEventListener('click', (event) => {
            const chip = event.target.closest('[data-pack-chip]');
            if (!chip) return;

            event.preventDefault();
            if (machineInput) machineInput.value = chip.dataset.machine || '';
            if (batchInput) batchInput.value = chip.dataset.batch || '';
            if (startInput) startInput.value = '';
            if (endInput) endInput.value = '';
            updateSuggestions();
        });

        dialog.dataset.packListenersBound = '1';
    }

    if (triggerBtn) {
        const action = dialog.querySelector('form[data-dynamic-action]')?.getAttribute('action') || '';
        const actionOrderId = action.match(/\/orders\/(\d+)\/pack(?:\?|$)/)?.[1] || '';
        const orderId = triggerBtn.dataset.orderId || actionOrderId;

        if (orderId) {
            dialog.dataset.packOrderId = orderId;
        } else {
            delete dialog.dataset.packOrderId;
        }

        if (startInput) startInput.value = '';
        if (endInput) endInput.value = '';

        if (triggerBtn.dataset.machine || triggerBtn.dataset.batch) {
            if (machineInput) machineInput.value = triggerBtn.dataset.machine || '';
            if (batchInput) batchInput.value = triggerBtn.dataset.batch || '';
        } else {
            if (machineInput) machineInput.value = '';
            if (batchInput) batchInput.value = '';

            const summary = getPackableData()[orderId];
            const batches = Array.isArray(summary?.batches) ? summary.batches : [];
            if (batches.length === 1) {
                if (machineInput) machineInput.value = batches[0].machine_id || '';
                if (batchInput) batchInput.value = batches[0].batch_id || '';
            }
        }
    }

    updatePackDialogSuggestions(dialog);
}

function updatePackDialogSuggestions(dialog) {
    const panel = dialog.querySelector('[data-pack-suggestion]');
    const remainingLabel = dialog.querySelector('[data-pack-remaining]');
    const rangeLabel = dialog.querySelector('[data-pack-range]');
    const emptyNotice = dialog.querySelector('[data-pack-empty]');
    const chipsContainer = dialog.querySelector('[data-pack-chips]');
    const rangeButton = dialog.querySelector('[data-pack-use-range]');
    const machineInput = dialog.querySelector('#pack-machine');
    const batchInput = dialog.querySelector('#pack-batch');
    const startInput = dialog.querySelector('#pack-start');
    const endInput = dialog.querySelector('#pack-end');
    const orderId = dialog.dataset.packOrderId || '';
    const summary = getPackableData()[orderId];

    if (!orderId || !summary) {
        panel?.classList.add('hidden');
        rangeButton?.classList.add('hidden');
        chipsContainer?.replaceChildren();
        return;
    }

    panel?.classList.remove('hidden');

    const availableBatches = Array.isArray(summary.batches) ? summary.batches : [];
    const allBatches = Array.isArray(summary.all_batches) ? summary.all_batches : availableBatches;
    const totalRemaining = Number(summary.total_remaining || 0);
    const machine = machineInput?.value || '';
    const batch = batchInput?.value || '';
    const matched = findPackableBatch(allBatches, machine, batch);
    const matchedRemaining = matched ? Number(matched.remaining_count || 0) : 0;
    const scopeEntered = Boolean(uppercaseTrim(machine) || uppercaseTrim(batch));
    const remainingCount = matched ? matchedRemaining : totalRemaining;
    const showEmpty = totalRemaining === 0;
    const hasRange = Boolean(
        matched
        && matchedRemaining > 0
        && matched.min_unit !== null
        && matched.min_unit !== undefined
        && matched.max_unit !== null
        && matched.max_unit !== undefined
    );
    const rangeMin = hasRange ? Number(matched.min_unit) : null;
    const rangeMax = hasRange ? Number(matched.max_unit) : null;
    const validRange = hasRange && Number.isFinite(rangeMin) && Number.isFinite(rangeMax);

    if (remainingLabel) {
        remainingLabel.textContent = fillTemplate(dialog.dataset.remainingLabelTemplate, {
            COUNT: remainingCount,
        });
    }

    if (emptyNotice) {
        if (dialog.dataset.allPackedLabel) {
            emptyNotice.textContent = dialog.dataset.allPackedLabel;
        }
        emptyNotice.classList.toggle('hidden', !showEmpty);
    }

    let rangeText = '';
    if (!showEmpty) {
        if (validRange) {
            rangeText = fillTemplate(dialog.dataset.suggestedRangeTemplate, {
                MIN: rangeMin,
                MAX: rangeMax,
            });
        } else if (matched && matchedRemaining === 0) {
            rangeText = dialog.dataset.batchEmptyLabel || '';
        } else if (scopeEntered) {
            rangeText = dialog.dataset.noMatchLabel || '';
        } else if (availableBatches.length > 0) {
            rangeText = dialog.dataset.selectBatchLabel || '';
        }
    }

    if (rangeLabel) {
        rangeLabel.textContent = rangeText;
        rangeLabel.classList.toggle('hidden', rangeText === '');
    }

    if (startInput) startInput.placeholder = validRange ? String(rangeMin) : '1';
    if (endInput) endInput.placeholder = validRange ? String(rangeMax) : '100';

    if (rangeButton) {
        rangeButton.classList.toggle('hidden', !validRange);
        if (validRange) {
            rangeButton.textContent = fillTemplate(dialog.dataset.useRangeTemplate, {
                MIN: rangeMin,
                MAX: rangeMax,
            });
        }
    }

    dialog.dataset.packMin = validRange ? String(rangeMin) : '';
    dialog.dataset.packMax = validRange ? String(rangeMax) : '';

    if (!chipsContainer) return;
    chipsContainer.replaceChildren();

    allBatches.forEach((entry) => {
        const count = Number(entry.remaining_count || 0);
        const isActive = Boolean(
            matched
            && uppercaseTrim(entry.machine_id) === uppercaseTrim(matched.machine_id)
            && uppercaseTrim(entry.batch_id) === uppercaseTrim(matched.batch_id)
        );

        const chip = document.createElement(count > 0 ? 'button' : 'span');
        if (count > 0) {
            chip.type = 'button';
            chip.dataset.packChip = '1';
            chip.dataset.machine = entry.machine_id || '';
            chip.dataset.batch = entry.batch_id || '';
            chip.setAttribute('aria-pressed', String(isActive));
            chip.className = isActive
                ? 'inline-flex items-center rounded-full border border-emerald-400 bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold text-emerald-800 transition'
                : 'inline-flex items-center rounded-full border border-emerald-200 bg-white px-2.5 py-1 text-[10px] font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-50';
            chip.textContent = fillTemplate(dialog.dataset.batchTemplate, {
                MACHINE: entry.machine_id,
                BATCH: entry.batch_id,
                COUNT: count,
                MIN: entry.min_unit,
                MAX: entry.max_unit,
            });
        } else {
            chip.className = 'inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-medium text-slate-400';
            chip.textContent = fillTemplate(dialog.dataset.batchZeroTemplate, {
                MACHINE: entry.machine_id,
                BATCH: entry.batch_id,
            });
        }

        chipsContainer.append(chip);
    });
}

/* ---------- Dialog delegation ---------- */
function initDialogs() {
    document.addEventListener('click', (event) => {
        const openBtn = event.target.closest('[data-open-dialog]');
        if (openBtn) {
            event.preventDefault();
            const targetId = openBtn.getAttribute('data-open-dialog');
            if (!targetId || !targetId.trim()) return;
            openTpDialog(byId(targetId), openBtn);
            return;
        }
        const closeBtn = event.target.closest('[data-close-dialog]');
        if (closeBtn) {
            event.preventDefault();
            closeTpDialog(closeBtn.closest('dialog'));
            return;
        }
        if (event.target instanceof HTMLDialogElement && event.target.classList.contains('tp-dialog')) {
            closeTpDialog(event.target);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const openDialog = document.querySelector('dialog.tp-dialog[open]');
            if (openDialog) {
                event.preventDefault();
                closeTpDialog(openDialog);
                return;
            }
            const sidebar = byId('app-sidebar');
            const overlay = byId('sidebar-overlay');
            if (sidebar && !sidebar.classList.contains('-translate-x-full')) {
                event.preventDefault();
                closeSidebar();
            }
        }
    });
}

/* ---------- Auto reopen modal on validation error ---------- */
function initAutoOpenDialog() {
    const body = document.body;
    const targetId = body.dataset.openDialog || '';
    if (!targetId || !targetId.trim()) return;
    const dialog = byId(targetId);
    if (!dialog) return;
    const panel = dialog.closest('.tab-panel');
    if (panel) {
        panel.classList.remove('hidden');
        const tablist = panel.closest('section')?.querySelector('ul[role="tablist"]') || document.querySelector('ul[role="tablist"]');
        if (tablist) {
            const hash = '#' + targetId;
            tablist.querySelectorAll('a[role="tab"]').forEach((tab) => {
                const isMatch = tab.getAttribute('href') === hash;
                tab.classList.toggle('tab-active', isMatch);
                tab.setAttribute('aria-selected', String(isMatch));
            });
        }
        document.querySelectorAll('[data-sidebar-hash]').forEach((link) => {
            link.classList.toggle('nav-link-active', link.dataset.sidebarHash === targetId);
        });
        if (window.location.hash.replace(/^#/, '') !== targetId) {
            window.history.replaceState(null, '', '#' + targetId);
        }
    }
    openTpDialog(dialog);
}

/* ---------- Chart.js ---------- */
function initChart() {
    try {
        const canvas = byId('triproof-chart');
        const dataElement = byId('triproof-chart-data');
        if (!canvas || !dataElement) return;
        const data = JSON.parse(dataElement.textContent || '{}');
        const values = Array.isArray(data.values) ? data.values : [];
        const total = values.reduce((sum, value) => sum + Number(value || 0), 0);
        if (total === 0) return;
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels || [],
                datasets: [{
                    data: values,
                    backgroundColor: data.colors || [],
                    borderWidth: 0,
                    hoverOffset: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 7,
                            boxHeight: 7,
                            padding: 18,
                            color: '#475569',
                            font: { size: 11, weight: '600' },
                        },
                    },
                    tooltip: {
                        backgroundColor: '#06110e',
                        titleColor: '#d0f6e6',
                        bodyColor: '#ffffff',
                        padding: 10,
                        cornerRadius: 10,
                        displayColors: false,
                    },
                },
            },
        });
    } catch {
        // Chart rendering failure must not break the rest of the page.
    }
}

/* ---------- Leaflet map ---------- */
function initMap() {
    try {
        const element = byId('triproof-map');
        const dataElement = byId('triproof-map-markers');
        if (!element || !dataElement) return;
        const payload = JSON.parse(dataElement.textContent || '{}');
        const markers = Array.isArray(payload.markers) ? payload.markers : [];
        const labels = payload.labels || {};
        const map = L.map(element, {
            zoomControl: false,
            scrollWheelZoom: false,
            attributionControl: true,
        }).setView([23.8103, 90.4125], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);
        L.control.zoom({ position: 'bottomright' }).addTo(map);
        const colors = {
            clone_detected: '#ef6464',
            forged_signature: '#f0b84b',
            recalled_batch: '#c084fc',
            expired: '#64748b',
        };
        const bounds = [];
        markers.forEach((marker) => {
            const lat = Number(marker.lat);
            const lng = Number(marker.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
            const color = colors[marker.verdict] || '#5b9cf5';
            const point = L.circleMarker([lat, lng], {
                radius: 7,
                color: '#ffffff',
                weight: 2,
                fillColor: color,
                fillOpacity: 0.95,
            }).addTo(map);
            const popup = document.createElement('div');
            popup.className = 'space-y-1.5 py-1 text-xs';
            const rows = [
                [labels.serial || 'Serial', marker.serial],
                [labels.batch || 'Batch', marker.batch || '—'],
                ['Verdict', labels.verdicts?.[marker.verdict] || marker.verdict],
                [labels.device || 'Device', marker.device || '—'],
                [labels.scanned || 'Scanned', marker.scanned_at || '—'],
            ];
            rows.forEach(([label, value]) => {
                const row = document.createElement('div');
                const strong = document.createElement('span');
                strong.className = 'font-semibold text-slate-700';
                strong.textContent = `${label}: `;
                const span = document.createElement('span');
                span.className = 'text-slate-500';
                span.textContent = String(value ?? '—');
                row.append(strong, span);
                popup.append(row);
            });
            point.bindPopup(popup);
            bounds.push([lat, lng]);
        });
        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [36, 36], maxZoom: 10 });
        }
        window.setTimeout(() => map.invalidateSize(), 250);
    } catch {
        // Map rendering failure must not break the rest of the page.
    }
}

/* ---------- Copy-to-clipboard ---------- */
function initCopyButtons() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-target]');
        if (!button) return;
        const target = document.querySelector(button.dataset.copyTarget);
        if (!target) return;
        try {
            await navigator.clipboard.writeText(target.textContent.trim());
            button.dataset.originalLabel = button.textContent;
            button.textContent = button.dataset.copiedLabel || 'Copied';
            setTimeout(() => {
                button.textContent = button.dataset.originalLabel;
            }, 1400);
        } catch {
            // Clipboard permission denied; leave button unchanged.
        }
    });
}

/* ---------- Locale switcher ---------- */
function initLocaleSwitch() {
    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-locale-select]')) {
            event.target.closest('form')?.submit();
        }
    });
}

/* ---------- Form confirmation & dialog submission lifecycle ---------- */
function initFormSubmissions() {
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;

        if (form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
            event.preventDefault();
            return;
        }

        const dialog = form.closest('dialog.tp-dialog');
        if (!dialog) return;

        const submitter = event.submitter instanceof HTMLElement
            ? event.submitter
            : form.querySelector('button[type="submit"], input[type="submit"]');

        if (submitter && 'disabled' in submitter) {
            window.setTimeout(() => {
                submitter.disabled = true;
            }, 0);
        }

        closeTpDialog(dialog);
    });
}
