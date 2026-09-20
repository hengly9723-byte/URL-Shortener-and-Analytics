/**
 * assets/js/dashboard.js
 *
 * Interactive Visual Analytics & Link Management for SnapLink Dashboard.
 * Powered by Chart.js & Vanilla JavaScript.
 *
 * Capabilities:
 *  1. Chart.js visual analytics:
 *     - Line chart with gradient fill for "Clicks Over Time"
 *     - Horizontal bar chart for "Top Referrers" (with chart/list toggle)
 *     - Doughnut chart for "Devices" (Desktop, Mobile, Tablet, Other)
 *     - Doughnut chart for "Browsers" (Chrome, Safari, Firefox, Edge, Opera, Other)
 *  2. Real-time data fetching & filtering by specific URL and date range (7D / 30D / 90D)
 *  3. Link management operations:
 *     - 1-click clipboard copy with animated toast
 *     - Live active/paused status toggle
 *     - Edit destination URL & expiration date via modal
 *     - Delete link with confirmation modal & cascading UI removal
 *     - Inline creation of new short links
 *  4. Client-side search filter across shortened links table
 */

'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const config     = window.SnapDashboard || {};
    const apiUrl     = config.apiUrl     || 'api/analytics.php';
    const shortenApi = config.shortenApi || 'api/shorten.php';
    const csrfToken  = config.csrf       || '';

    // Active state
    let currentUrlId = 'all';
    let currentDays  = 30;

    // Chart instances
    let timelineChartInstance = null;
    let referrerChartInstance = null;
    let deviceChartInstance   = null;
    let browserChartInstance  = null;

    // ── Toast Notification Helper ────────────────────────────────────────────
    const toast = document.getElementById('dashboard-toast');
    const toastMsg = document.getElementById('toast-message');
    const toastSuccess = document.getElementById('toast-icon-success');
    const toastError   = document.getElementById('toast-icon-error');
    let toastTimer = null;

    function showToast(message, isError = false) {
        if (!toast || !toastMsg) return;
        clearTimeout(toastTimer);
        toastMsg.textContent = message;

        if (toastSuccess) toastSuccess.classList.toggle('hidden', isError);
        if (toastError)   toastError.classList.toggle('hidden', !isError);

        toast.classList.remove('hide');
        toast.classList.add('show');
        toastTimer = setTimeout(() => {
            toast.classList.remove('show');
            toast.classList.add('hide');
        }, 3200);
    }

    // ── Chart.js Global Theme Configuration ──────────────────────────────────
    if (typeof Chart !== 'undefined') {
        Chart.defaults.color = '#71717a';
        Chart.defaults.font.family = "'Inter', system-ui, -apple-system, sans-serif";
        Chart.defaults.font.size = 11;
    }

    // =========================================================================
    // CHART INITIALIZERS
    // =========================================================================

    /**
     * 1. Line Chart: Clicks Over Time
     */
    function initTimelineChart(labels = [], data = []) {
        const canvas = document.getElementById('timelineChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(99, 102, 241, 0.18)');
        gradient.addColorStop(0.7, 'rgba(99, 102, 241, 0.04)');
        gradient.addColorStop(1, 'rgba(99, 102, 241, 0.00)');

        timelineChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Clicks',
                    data: data,
                    fill: true,
                    backgroundColor: gradient,
                    borderColor: '#6366f1',
                    borderWidth: 2,
                    pointBackgroundColor: '#6366f1',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: labels.length > 25 ? 2 : 4,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#4f46e5',
                    tension: 0.35,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        titleColor: '#09090b',
                        bodyColor: '#6366f1',
                        borderColor: '#e4e4e7',
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: (ctx) => `${ctx.parsed.y} click${ctx.parsed.y === 1 ? '' : 's'}`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: '#f4f4f5' },
                        ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12, color: '#a1a1aa' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f4f4f5' },
                        ticks: { precision: 0, color: '#a1a1aa' }
                    }
                }
            }
        });
    }

    /**
     * 2. Horizontal Bar Chart: Top Referrers
     */
    function initReferrerChart(referrers = []) {
        const canvas = document.getElementById('referrerChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');

        // Top 6 referrers for clean bar representation
        const topSlice = referrers.slice(0, 6);
        const labels   = topSlice.map(r => r.referrer);
        const data     = topSlice.map(r => r.count);

        const hasData = data.some(v => v > 0);

        referrerChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: hasData ? labels : ['No referrers'],
                datasets: [{
                    label: 'Clicks',
                    data: hasData ? data : [0],
                    backgroundColor: [
                        'rgba(99, 102, 241, 0.80)',
                        'rgba(139, 92, 246, 0.80)',
                        'rgba(16, 185, 129, 0.80)',
                        'rgba(245, 158, 11, 0.80)',
                        'rgba(59, 130, 246, 0.80)',
                        'rgba(100, 116, 139, 0.80)',
                    ],
                    borderColor: 'transparent',
                    borderWidth: 0,
                    borderRadius: 4,
                    barThickness: 14,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        titleColor: '#09090b',
                        bodyColor: '#6366f1',
                        borderColor: '#e4e4e7',
                        borderWidth: 1,
                        padding: 8,
                        cornerRadius: 6,
                        callbacks: {
                            label: (ctx) => `${ctx.parsed.x} referral${ctx.parsed.x === 1 ? '' : 's'}`
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: '#f4f4f5' },
                        ticks: { precision: 0, color: '#a1a1aa' }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            color: '#71717a',
                            callback: function(val) {
                                const lbl = this.getLabelForValue(val);
                                return lbl.length > 16 ? lbl.substring(0, 14) + '...' : lbl;
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * 3. Doughnut Chart: Devices
     */
    function initDeviceChart(devices = {}) {
        const canvas = document.getElementById('deviceChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const labels = Object.keys(devices);
        const data   = Object.values(devices);
        const total  = data.reduce((a, b) => a + b, 0);

        const colors = ['#6366f1', '#8b5cf6', '#10b981', '#94a3b8'];

        deviceChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: total === 0 ? [1] : data,
                    backgroundColor: total === 0 ? ['#f4f4f5'] : colors,
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: total === 0 ? 0 : 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: total > 0,
                        backgroundColor: '#ffffff',
                        borderColor: '#e4e4e7',
                        borderWidth: 1,
                        padding: 8,
                        cornerRadius: 6,
                        titleColor: '#09090b',
                        bodyColor: '#6366f1',
                        callbacks: {
                            label: (ctx) => ` ${ctx.label}: ${ctx.raw} (${Math.round((ctx.raw / total) * 100)}%)`
                        }
                    }
                }
            }
        });

        renderDeviceLegend(labels, colors);
    }

    function renderDeviceLegend(labels, colors) {
        const legendEl = document.getElementById('device-legend');
        if (!legendEl) return;

        legendEl.innerHTML = labels.map((l, i) => `
            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: ${colors[i % colors.length]}"></span>
                <span>${l}</span>
            </div>
        `).join('');
    }

    /**
     * 4. Doughnut Chart: Browsers
     */
    function initBrowserChart(browsers = {}) {
        const canvas = document.getElementById('browserChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const labels = Object.keys(browsers);
        const data   = Object.values(browsers);
        const total  = data.reduce((a, b) => a + b, 0);

        const colors = ['#3b82f6', '#10b981', '#f97316', '#a855f7', '#06b6d4', '#94a3b8'];

        browserChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: total === 0 ? [1] : data,
                    backgroundColor: total === 0 ? ['#f4f4f5'] : colors,
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: total === 0 ? 0 : 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: total > 0,
                        backgroundColor: '#ffffff',
                        borderColor: '#e4e4e7',
                        borderWidth: 1,
                        padding: 8,
                        cornerRadius: 6,
                        titleColor: '#09090b',
                        bodyColor: '#3b82f6',
                        callbacks: {
                            label: (ctx) => ` ${ctx.label}: ${ctx.raw} (${Math.round((ctx.raw / total) * 100)}%)`
                        }
                    }
                }
            }
        });

        renderBrowserLegend(labels, colors);
    }

    function renderBrowserLegend(labels, colors) {
        const legendEl = document.getElementById('browser-legend');
        if (!legendEl) return;

        legendEl.innerHTML = labels.map((l, i) => `
            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: ${colors[i % colors.length]}"></span>
                <span>${l}</span>
            </div>
        `).join('');
    }

    // =========================================================================
    // AUXILIARY LIST RENDERERS
    // =========================================================================

    /**
     * Render Referrers detailed progress list
     */
    function renderReferrersList(referrers = []) {
        const container = document.getElementById('referrers-container');
        if (!container) return;

        if (!referrers || referrers.length === 0) {
            container.innerHTML = `
                <div class="py-8 text-center text-xs text-white/30">
                    No referrer traffic captured yet.
                </div>
            `;
            return;
        }

        const maxCount = Math.max(...referrers.map(r => r.count), 1);

        container.innerHTML = referrers.map(r => {
            const pct = Math.round((r.count / maxCount) * 100);
            return `
                <div class="space-y-1">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-medium text-white/80 truncate max-w-[170px]" title="${r.referrer}">${r.referrer}</span>
                        <span class="font-bold text-white/90">${r.count}</span>
                    </div>
                    <div class="w-full bg-white/5 h-1.5 rounded-full overflow-hidden">
                        <div class="bg-gradient-to-r from-brand-500 to-violet-500 h-full rounded-full transition-all duration-500" style="width: ${pct}%"></div>
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * Render Geographic countries breakdown
     */
    function renderCountries(countries = []) {
        const container = document.getElementById('countries-container');
        if (!container) return;

        if (!countries || countries.length === 0) {
            container.innerHTML = `
                <div class="py-8 text-center text-xs text-white/30">
                    No location data logged yet.
                </div>
            `;
            return;
        }

        container.innerHTML = countries.map(c => `
            <div class="flex items-center justify-between py-1.5 border-b border-white/5 text-xs">
                <div class="flex items-center gap-2">
                    <span class="w-5 h-3.5 bg-white/10 rounded-sm inline-flex items-center justify-center text-[9px] font-bold uppercase text-white/70 tracking-wider">
                        ${c.country}
                    </span>
                    <span class="font-medium text-white/80">${c.country}</span>
                </div>
                <span class="font-semibold text-white/60">${c.count} click${c.count === 1 ? '' : 's'}</span>
            </div>
        `).join('');
    }

    // =========================================================================
    // DYNAMIC STATS UPDATING & API CONSUMPTION
    // =========================================================================

    function updateDashboardData(data) {
        if (!data || !data.ok) return;

        // 1. Metric cards update
        if (data.summary) {
            const totalLinksEl  = document.getElementById('metric-total-links');
            const totalClicksEl = document.getElementById('metric-total-clicks');
            const activeLinksEl = document.getElementById('metric-active-links');
            const avgClicksEl   = document.getElementById('metric-avg-clicks');

            if (totalLinksEl)  totalLinksEl.textContent  = data.summary.total_links.toLocaleString();
            if (totalClicksEl) totalClicksEl.textContent = data.summary.total_clicks.toLocaleString();
            if (activeLinksEl) activeLinksEl.textContent = data.summary.active_links.toLocaleString();

            if (avgClicksEl) {
                const avg = data.summary.total_links > 0
                    ? (data.summary.total_clicks / data.summary.total_links).toFixed(1)
                    : 0;
                avgClicksEl.textContent = avg;
            }
        }

        // 2. Timeline chart update
        if (timelineChartInstance && data.timeline) {
            timelineChartInstance.data.labels = data.timeline.labels;
            timelineChartInstance.data.datasets[0].data = data.timeline.data;
            timelineChartInstance.update();
        }

        // 3. Top Referrers chart update
        if (referrerChartInstance && data.referrers) {
            const topSlice = data.referrers.slice(0, 6);
            const hasData  = topSlice.some(r => r.count > 0);
            referrerChartInstance.data.labels = hasData ? topSlice.map(r => r.referrer) : ['No referrers'];
            referrerChartInstance.data.datasets[0].data = hasData ? topSlice.map(r => r.count) : [0];
            referrerChartInstance.update();
        }

        // 4. Devices chart update
        if (deviceChartInstance && data.devices) {
            const devData  = Object.values(data.devices);
            const devTotal = devData.reduce((a, b) => a + b, 0);
            deviceChartInstance.data.datasets[0].data = devTotal === 0 ? [1] : devData;
            deviceChartInstance.update();
        }

        // 5. Browsers chart update
        if (browserChartInstance && data.browsers) {
            const bData  = Object.values(data.browsers);
            const bTotal = bData.reduce((a, b) => a + b, 0);
            browserChartInstance.data.datasets[0].data = bTotal === 0 ? [1] : bData;
            browserChartInstance.update();
        }

        // 6. Auxiliary lists
        renderReferrersList(data.referrers || []);
        renderCountries(data.countries || []);

        // 7. Chart scope badge
        const badge = document.getElementById('chart-scope-badge');
        if (badge) {
            badge.textContent = data.selected_url ? `/s/${data.selected_url.short_code}` : 'All Links';
        }
    }

    /**
     * Fetch fresh analytics payload from api/analytics.php
     */
    async function fetchStats() {
        const spinner = document.getElementById('chart-spinner');
        if (spinner) spinner.classList.remove('hidden');

        try {
            let query = `action=stats&days=${currentDays}`;
            if (currentUrlId !== 'all') {
                query += `&url_id=${encodeURIComponent(currentUrlId)}`;
            }

            const response = await fetch(`${apiUrl}?${query}`, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();

            if (response.ok && data.success) {
                updateDashboardData(data);
            } else {
                showToast(data.message || 'Failed to update stats', true);
            }
        } catch (err) {
            showToast('Unable to connect to analytics service', true);
        } finally {
            if (spinner) spinner.classList.add('hidden');
        }
    }

    // =========================================================================
    // EVENT HANDLERS: FILTERS & VIEW TOGGLES
    // =========================================================================

    // Link selector dropdown
    const filterSelect = document.getElementById('link-filter-select');
    if (filterSelect) {
        filterSelect.addEventListener('change', (e) => {
            currentUrlId = e.target.value;
            fetchStats();
        });
    }

    // Days buttons (7D, 30D, 90D)
    const dayButtons = document.querySelectorAll('.time-filter-btn');
    dayButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            dayButtons.forEach(b => {
                b.classList.remove('bg-brand-600', 'text-white', 'font-semibold');
                b.classList.add('text-white/60');
            });
            btn.classList.add('bg-brand-600', 'text-white', 'font-semibold');
            btn.classList.remove('text-white/60');

            currentDays = parseInt(btn.getAttribute('data-days') || '30', 10);
            fetchStats();
        });
    });

    // Referrers Card View Toggle: Chart vs List
    const refChartBtn  = document.getElementById('ref-view-chart-btn');
    const refListBtn   = document.getElementById('ref-view-list-btn');
    const refChartWrap = document.getElementById('referrer-chart-wrap');
    const refListWrap  = document.getElementById('referrers-container');

    if (refChartBtn && refListBtn && refChartWrap && refListWrap) {
        refChartBtn.addEventListener('click', () => {
            refChartBtn.className = 'px-2 py-0.5 rounded bg-brand-600 text-white font-medium transition-colors';
            refListBtn.className  = 'px-2 py-0.5 rounded text-white/50 hover:text-white transition-colors';
            refChartWrap.classList.remove('hidden');
            refListWrap.classList.add('hidden');
        });

        refListBtn.addEventListener('click', () => {
            refListBtn.className  = 'px-2 py-0.5 rounded bg-brand-600 text-white font-medium transition-colors';
            refChartBtn.className = 'px-2 py-0.5 rounded text-white/50 hover:text-white transition-colors';
            refListWrap.classList.remove('hidden');
            refChartWrap.classList.add('hidden');
        });
    }

    // Search filter across shortened links table
    const searchInput = document.getElementById('search-links-input');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.link-row');
            rows.forEach(row => {
                const code = (row.getAttribute('data-code') || '').toLowerCase();
                const url  = (row.getAttribute('data-url')  || '').toLowerCase();
                const match = code.includes(query) || url.includes(query);
                row.style.display = match ? '' : 'none';
            });
        });
    }

    // =========================================================================
    // LINK MANAGEMENT: ACTIONS & MUTATIONS
    // =========================================================================

    // 1. Copy Short Link with Toast Feedback
    document.addEventListener('click', (e) => {
        const copyBtn = e.target.closest('.copy-link-btn');
        if (copyBtn) {
            const textToCopy = copyBtn.getAttribute('data-copy');
            if (textToCopy) {
                navigator.clipboard.writeText(textToCopy).then(() => {
                    showToast('Short link copied to clipboard!');
                }).catch(() => {
                    // Fallback
                    const temp = document.createElement('input');
                    temp.value = textToCopy;
                    document.body.appendChild(temp);
                    temp.select();
                    document.execCommand('copy');
                    document.body.removeChild(temp);
                    showToast('Short link copied to clipboard!');
                });
            }
        }
    });

    // 2. View Individual Stats in Chart
    document.addEventListener('click', (e) => {
        const statsBtn = e.target.closest('.view-stats-btn');
        if (statsBtn) {
            const id = statsBtn.getAttribute('data-id');
            if (id && filterSelect) {
                filterSelect.value = id;
                currentUrlId = id;
                fetchStats();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }
    });

    // 3. Toggle Active/Paused Status
    document.addEventListener('click', async (e) => {
        const toggleBtn = e.target.closest('.toggle-status-btn');
        if (toggleBtn) {
            const id  = toggleBtn.getAttribute('data-id');
            const row = toggleBtn.closest('.link-row');
            if (!id || !row) return;

            toggleBtn.disabled = true;

            try {
                const res = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'toggle',
                        url_id: id,
                        _csrf:  csrfToken
                    })
                });
                const data = await res.json();

                if (res.ok && data.success) {
                    const isActive = data.is_active;
                    row.setAttribute('data-active', isActive ? '1' : '0');
                    toggleBtn.setAttribute('title', isActive ? 'Pause link' : 'Activate link');

                    // Update UI pill in row
                    const pill = row.querySelector('.status-pill');
                    if (pill) {
                        if (isActive) {
                            pill.className = 'inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-400 bg-emerald-400/10 border border-emerald-400/20 px-2 py-0.5 rounded-full status-pill';
                            pill.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Active';
                        } else {
                            pill.className = 'inline-flex items-center gap-1 text-[11px] font-semibold text-white/40 bg-white/5 border border-white/10 px-2 py-0.5 rounded-full status-pill';
                            pill.innerHTML = 'Paused';
                        }
                    }

                    showToast(data.message || (isActive ? 'Link activated' : 'Link paused'));
                    fetchStats();
                } else {
                    showToast(data.message || 'Status change failed', true);
                }
            } catch (err) {
                showToast('Network error while toggling link status', true);
            } finally {
                toggleBtn.disabled = false;
            }
        }
    });

    // =========================================================================
    // MODAL DIALOGS: CREATE, EDIT, DELETE
    // =========================================================================

    const createModal = document.getElementById('create-modal');
    const editModal   = document.getElementById('edit-modal');
    const deleteModal = document.getElementById('delete-modal');

    function closeAllModals() {
        [createModal, editModal, deleteModal].forEach(m => m && m.classList.add('hidden'));
    }

    document.querySelectorAll('.close-modal-btn').forEach(btn => {
        btn.addEventListener('click', closeAllModals);
    });

    // Dismiss on background backdrop click
    [createModal, editModal, deleteModal].forEach(modal => {
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeAllModals();
            });
        }
    });

    // Escape key closes modals
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAllModals();
    });

    // ── CREATE NEW LINK MODAL ────────────────────────────────────────────────
    document.getElementById('open-create-btn')?.addEventListener('click', () => {
        document.getElementById('create-form')?.reset();
        document.getElementById('create-error')?.classList.add('hidden');
        createModal?.classList.remove('hidden');
        document.getElementById('create-url')?.focus();
    });

    const createForm = document.getElementById('create-form');
    if (createForm) {
        createForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const urlInput    = document.getElementById('create-url');
            const expiryInput = document.getElementById('create-expiry');
            const errorBox    = document.getElementById('create-error');
            const submitBtn   = document.getElementById('create-submit-btn');
            const spinner     = document.getElementById('create-btn-spinner');

            const urlVal = urlInput.value.trim();
            const expVal = expiryInput.value;

            if (!urlVal) return;

            submitBtn.disabled = true;
            if (spinner) spinner.classList.remove('hidden');
            if (errorBox) errorBox.classList.add('hidden');

            try {
                const res = await fetch(shortenApi, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ url: urlVal, expiry_date: expVal })
                });
                const data = await res.json();

                if (res.ok && data.success) {
                    showToast('Short link created successfully!');
                    closeAllModals();
                    setTimeout(() => window.location.reload(), 450);
                } else {
                    if (errorBox) {
                        errorBox.textContent = data.message || 'Failed to shorten URL.';
                        errorBox.classList.remove('hidden');
                    }
                }
            } catch (err) {
                if (errorBox) {
                    errorBox.textContent = 'Network communication error.';
                    errorBox.classList.remove('hidden');
                }
            } finally {
                submitBtn.disabled = false;
                if (spinner) spinner.classList.add('hidden');
            }
        });
    }

    // ── EDIT LINK MODAL ──────────────────────────────────────────────────────
    document.addEventListener('click', (e) => {
        const editBtn = e.target.closest('.edit-link-btn');
        if (editBtn) {
            const id     = editBtn.getAttribute('data-id');
            const code   = editBtn.getAttribute('data-code');
            const url    = editBtn.getAttribute('data-url');
            const expiry = editBtn.getAttribute('data-expiry') || '';

            const editIdEl   = document.getElementById('edit-id');
            const editCodeEl = document.getElementById('edit-code');
            const editUrlEl  = document.getElementById('edit-url');
            const editExpEl  = document.getElementById('edit-expiry');

            if (editIdEl)   editIdEl.value   = id;
            if (editCodeEl) editCodeEl.value = '/s/' + code;
            if (editUrlEl)  editUrlEl.value  = url;

            if (editExpEl) {
                editExpEl.value = expiry ? expiry.split(' ')[0] : '';
            }

            document.getElementById('edit-error')?.classList.add('hidden');
            editModal?.classList.remove('hidden');
            if (editUrlEl) editUrlEl.focus();
        }
    });

    const editForm = document.getElementById('edit-form');
    if (editForm) {
        editForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const idVal     = document.getElementById('edit-id')?.value;
            const urlVal    = document.getElementById('edit-url')?.value.trim();
            const expVal    = document.getElementById('edit-expiry')?.value;
            const errorBox  = document.getElementById('edit-error');
            const submitBtn = document.getElementById('edit-submit-btn');
            const spinner   = document.getElementById('edit-btn-spinner');

            if (!idVal || !urlVal) return;

            submitBtn.disabled = true;
            if (spinner) spinner.classList.remove('hidden');
            if (errorBox) errorBox.classList.add('hidden');

            try {
                const res = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        action:       'update',
                        url_id:       idVal,
                        original_url: urlVal,
                        expiry_date:  expVal,
                        _csrf:        csrfToken
                    })
                });
                const data = await res.json();

                if (res.ok && data.success) {
                    showToast('Link updated successfully!');
                    closeAllModals();

                    // Update corresponding row in table
                    const row = document.querySelector(`.link-row[data-id="${idVal}"]`);
                    if (row) {
                        row.setAttribute('data-url', urlVal);
                        row.setAttribute('data-expiry', expVal);

                        const anchor = row.querySelector('td:nth-child(2) a');
                        if (anchor) {
                            anchor.textContent = urlVal;
                            anchor.href = urlVal;
                        }
                    }
                } else {
                    if (errorBox) {
                        errorBox.textContent = data.message || 'Update failed.';
                        errorBox.classList.remove('hidden');
                    }
                }
            } catch (err) {
                if (errorBox) {
                    errorBox.textContent = 'Network communication error.';
                    errorBox.classList.remove('hidden');
                }
            } finally {
                submitBtn.disabled = false;
                if (spinner) spinner.classList.add('hidden');
            }
        });
    }

    // ── DELETE LINK MODAL ────────────────────────────────────────────────────
    let pendingDeleteId = null;

    document.addEventListener('click', (e) => {
        const delBtn = e.target.closest('.delete-link-btn');
        if (delBtn) {
            pendingDeleteId = delBtn.getAttribute('data-id');
            const code = delBtn.getAttribute('data-code');
            const codeDisp = document.getElementById('delete-code-display');
            if (codeDisp) codeDisp.textContent = `/s/${code}`;
            deleteModal?.classList.remove('hidden');
        }
    });

    document.getElementById('confirm-delete-btn')?.addEventListener('click', async () => {
        if (!pendingDeleteId) return;

        const btn     = document.getElementById('confirm-delete-btn');
        const spinner = document.getElementById('delete-btn-spinner');

        btn.disabled = true;
        if (spinner) spinner.classList.remove('hidden');

        try {
            const res = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    url_id: pendingDeleteId,
                    _csrf:  csrfToken
                })
            });
            const data = await res.json();

            if (res.ok && data.success) {
                showToast('Link deleted successfully');
                closeAllModals();

                // Remove table row
                const row = document.querySelector(`.link-row[data-id="${pendingDeleteId}"]`);
                if (row) row.remove();

                // Remove from filter select
                const opt = filterSelect?.querySelector(`option[value="${pendingDeleteId}"]`);
                if (opt) opt.remove();

                // Re-evaluate empty state if no rows remain
                const remainingRows = document.querySelectorAll('.link-row');
                if (remainingRows.length === 0) {
                    const tbody = document.getElementById('links-table-body');
                    if (tbody) {
                        tbody.innerHTML = `
                            <tr id="empty-state-row">
                                <td colspan="6" class="py-12 text-center text-white/40">
                                    <p class="text-sm font-medium text-white/60">No shortened links yet</p>
                                    <p class="text-xs text-white/30 mt-1">Click "New Link" above to generate your first tracked short link.</p>
                                </td>
                            </tr>
                        `;
                    }
                }

                // If currently viewing deleted link in charts, switch back to 'all'
                if (currentUrlId === pendingDeleteId) {
                    currentUrlId = 'all';
                    if (filterSelect) filterSelect.value = 'all';
                }

                fetchStats();
            } else {
                showToast(data.message || 'Delete operation failed', true);
            }
        } catch (err) {
            showToast('Network error during deletion', true);
        } finally {
            btn.disabled = false;
            if (spinner) spinner.classList.add('hidden');
            pendingDeleteId = null;
        }
    });

    // =========================================================================
    // BOOTSTRAP CHARTS WITH INITIAL SERVER HYDRATED DATA
    // =========================================================================
    const initial = config.initial || {};
    initTimelineChart(initial.timeline?.labels || [], initial.timeline?.data || []);
    initReferrerChart(initial.referrers || []);
    initDeviceChart(initial.devices || {});
    initBrowserChart(initial.browsers || {});
    renderReferrersList(initial.referrers || []);
    renderCountries(initial.countries || []);
});
