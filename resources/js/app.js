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

ready(() => {
    initSidebar();
    initTabs();
    initDialogs();
    initAutoOpenDialog();
    initLocaleSwitch();
    initChart();
    initMap();
    initCopyButtons();
    initConfirmation();
});

function initSidebar() {
    const sidebar = document.getElementById('app-sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    if (!sidebar || !overlay) return;

    const open = () => {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    };

    const close = () => {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    };

    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', open);
    });

    document.querySelectorAll('[data-sidebar-close]').forEach((button) => {
        button.addEventListener('click', close);
    });

    overlay.addEventListener('click', close);
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
    });
}

function initTabs() {
    const tablist = document.querySelector('ul[tabs]') || document.querySelector('ul[role="tablist"]');
    if (!tablist) return;

    const tabs = Array.from(tablist.querySelectorAll('a[role="tab"]'));
    const hashMap = new Map();
    const dataActiveTab = tablist.dataset.activeTab || '';

    tabs.forEach((tab) => {
        const href = tab.getAttribute('href') || '';
        const hash = href.replace(/^#/, '');
        if (hash) hashMap.set(hash, tab);
    });

    const activeFromHash = window.location.hash.replace(/^#/, '');
    const activeFromData = dataActiveTab;
    const targetHash = activeFromHash || activeFromData || (tabs[0]?.getAttribute('href') || '').replace(/^#/, '');

    function activate(hash) {
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

        // Sync sidebar active state
        document.querySelectorAll('[data-sidebar-hash]').forEach((link) => {
            link.classList.toggle('nav-link-active', link.dataset.sidebarHash === hash);
        });

        // Update URL hash without scrolling
        if (window.location.hash.replace(/^#/, '') !== hash) {
            window.history.replaceState(null, '', '#' + hash);
        }
    }

    activate(targetHash);

    tablist.addEventListener('click', (event) => {
        const link = event.target.closest('a[role="tab"]');
        if (!link) return;
        event.preventDefault();
        const hash = (link.getAttribute('href') || '').replace(/^#/, '');
        if (hash) activate(hash);
    });

    window.addEventListener('hashchange', () => {
        const hash = window.location.hash.replace(/^#/, '');
        if (hash && hashMap.has(hash)) activate(hash);
    });
}

function initDialogs() {
    // Document-level delegation so buttons inside tabs / dynamic sections work.
    document.addEventListener('click', (event) => {
        const openTrigger = event.target.closest('[data-open-dialog]');
        if (openTrigger) {
            const targetId = openTrigger.dataset.openDialog;
            const dialog = document.getElementById(targetId);
            if (!dialog) return;

            const form = dialog.querySelector('form[data-dynamic-action]') || dialog.querySelector('form');

            if (form && openTrigger.dataset.action) {
                form.setAttribute('action', openTrigger.dataset.action);
            }

            dialog.querySelectorAll('[data-dialog-context]').forEach((element) => {
                element.textContent = openTrigger.dataset.context || '';
            });

            if (form) {
                ['factory', 'machine', 'batch'].forEach((field) => {
                    const value = openTrigger.dataset[field];
                    if (value === undefined) return;
                    const input = form.elements.namedItem(field + '_id');
                    if (input) input.value = value;
                });

                if ('resetScope' in openTrigger.dataset) {
                    ['machine_id', 'batch_id'].forEach((name) => {
                        const input = form.elements.namedItem(name);
                        if (input) input.value = '';
                    });
                }

                if (openTrigger.dataset.remaining !== undefined && form) {
                    const remaining = Math.max(0, Number(openTrigger.dataset.remaining) || 0);
                    const count = form.elements.namedItem('count');

                    if (count instanceof HTMLInputElement) {
                        count.max = String(remaining);
                        count.value = String(Math.min(remaining, 100));
                    }

                    const remainingLabel = dialog.querySelector('[data-remaining-label]');
                    if (remainingLabel && dialog.dataset.remainingTemplate) {
                        remainingLabel.textContent = dialog.dataset.remainingTemplate.replaceAll(
                            '__COUNT__',
                            remaining.toLocaleString()
                        );
                    }

                    syncGenerateLabels(dialog, form);
                }
            }

            if (openTrigger.dataset.merkleUrl) {
                loadMerkle(dialog, openTrigger.dataset.merkleUrl);
            }

            dialog.removeAttribute('hidden');
            dialog.showModal();
            dialog.setAttribute('open', '');
            return;
        }

        const closeTrigger = event.target.closest('[data-close-dialog]');
        if (closeTrigger) {
            closeTrigger.closest('dialog')?.close();
            return;
        }

        if (event.target instanceof HTMLDialogElement && event.target.classList.contains('tp-dialog')) {
            event.target.close();
        }
    });

    // Keep existing per-dialog form listeners (generate count sync, etc.).
    document.querySelectorAll('dialog.tp-dialog').forEach((dialog) => {
        const form = dialog.querySelector('#generate-form');
        if (form) {
            form.addEventListener('input', () => syncGenerateLabels(dialog, form));
        }
    });
}

function initAutoOpenDialog() {
    const body = document.body;
    const targetId = body.dataset.openDialog || '';
    if (!targetId) return;

    const dialog = document.getElementById(targetId);
    if (!dialog) return;

    // Activate the parent tab panel + matching tab first.
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

        // Sync sidebar active state via data-sidebar-hash.
        document.querySelectorAll('[data-sidebar-hash]').forEach((link) => {
            link.classList.toggle('nav-link-active', link.dataset.sidebarHash === targetId);
        });

        // Update URL hash without scrolling.
        if (window.location.hash.replace(/^#/, '') !== targetId) {
            window.history.replaceState(null, '', '#' + targetId);
        }
    }

    dialog.removeAttribute('hidden');
    dialog.showModal();
    dialog.setAttribute('open', '');
}

function syncGenerateLabels(dialog, form) {
    const count = form.elements.namedItem('count');
    const submit = dialog.querySelector('[data-submit-label]');
    if (!submit || !(count instanceof HTMLInputElement)) return;

    const value = Math.max(0, Number(count.value) || 0);
    const template = dialog.dataset.countTemplate || 'Sign __COUNT__ units';
    submit.textContent = template.replaceAll('__COUNT__', value.toLocaleString());
}

async function loadMerkle(dialog, url) {
    const loading = dialog.querySelector('[data-merkle-loading]');
    const body = dialog.querySelector('[data-merkle-body]');
    const error = dialog.querySelector('[data-merkle-error]');

    loading?.classList.remove('hidden');
    body?.classList.add('hidden');
    error?.classList.add('hidden');

    try {
        const response = await window.axios.get(url, { headers: { Accept: 'application/json' } });
        const payload = response.data?.data;
        if (!payload) throw new Error('Invalid Merkle response');

        const set = (selector, value) => {
            const element = dialog.querySelector(selector);
            if (element) element.textContent = value;
        };

        set('[data-merkle-stored]', payload.stored_root || dialog.dataset.noRootLabel || '—');
        set('[data-merkle-recomputed]', payload.recomputed_root || dialog.dataset.noRootLabel || '—');
        set('[data-merkle-leaves]', Number(payload.leaf_count || 0).toLocaleString());
        set('[data-merkle-generated]', Number(payload.generated_count || 0).toLocaleString());
        set('[data-merkle-algorithm]', payload.algorithm || '—');

        const matches = dialog.querySelector('[data-merkle-matches]');
        if (matches) {
            matches.textContent = payload.matches
                ? dialog.dataset.matchLabel || 'Roots match'
                : dialog.dataset.mismatchLabel || 'Roots differ';
            matches.className = `badge ${payload.matches ? 'badge-success' : 'badge-danger'}`;
        }

        const consistent = dialog.querySelector('[data-merkle-consistent]');
        if (consistent) {
            consistent.textContent = payload.consistent
                ? dialog.dataset.consistentLabel || 'Registry consistent'
                : dialog.dataset.inconsistentLabel || 'Registry mismatch';
            consistent.className = `badge ${payload.consistent ? 'badge-info' : 'badge-warning'}`;
        }

        body?.classList.remove('hidden');
    } catch (exception) {
        if (error) {
            error.textContent =
                exception.response?.data?.error?.message ||
                dialog.dataset.errorLabel ||
                'The Merkle proof could not be loaded.';
            error.classList.remove('hidden');
        }
    } finally {
        loading?.classList.add('hidden');
    }
}

function initLocaleSwitch() {
    document.querySelectorAll('[data-locale-select]').forEach((select) => {
        select.addEventListener('change', () => select.form?.submit());
    });
}

function initChart() {
    const canvas = document.getElementById('triproof-chart');
    const dataElement = document.getElementById('triproof-chart-data');
    if (!canvas || !dataElement) return;

    const data = JSON.parse(dataElement.textContent || '{}');
    const values = Array.isArray(data.values) ? data.values : [];
    const total = values.reduce((sum, value) => sum + Number(value || 0), 0);

    if (total === 0) return;

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: data.labels || [],
            datasets: [
                {
                    data: values,
                    backgroundColor: data.colors || [],
                    borderWidth: 0,
                    hoverOffset: 4,
                },
            ],
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
}

function initMap() {
    const element = document.getElementById('triproof-map');
    const dataElement = document.getElementById('triproof-map-markers');
    if (!element || !dataElement) return;

    const payload = JSON.parse(dataElement.textContent || '{}');
    const markers = Array.isArray(payload.markers) ? payload.markers : [];
    const labels = payload.labels || {};

    const map = L.map(element, {
        zoomControl: false,
        scrollWheelZoom: false,
        attributionControl: true,
    }).setView([23.685, 90.3563], 6);

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
}

async function initCopyButtons() {
    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.querySelector(button.dataset.copyTarget);
            if (!target) return;

            try {
                await navigator.clipboard.writeText(target.textContent.trim());
                const original = button.dataset.originalLabel || button.textContent;
                button.dataset.originalLabel = original;
                button.textContent = button.dataset.copiedLabel || 'Copied';
                window.setTimeout(() => {
                    button.textContent = original;
                }, 1400);
            } catch {
                // Clipboard permissions can be denied; leave the button unchanged.
            }
        });
    });
}

function initConfirmation() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
}
