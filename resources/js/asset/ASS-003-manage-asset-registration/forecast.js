import { initSearchableSelects } from '../../components/searchable-select.js';

const sidebarStorageKey = 'gujajob.sidebar.groupState';

/** Dropdown value for "ทั้งหมด" (see forecast.blade.php); sent to the server as no filter. */
const ALL_OPTION_VALUE = '__all__';

function getSidebarState() {
    try {
        const storedState = localStorage.getItem(sidebarStorageKey);

        if (!storedState) {
            return {
                material: true,
                asset: true,
            };
        }

        return {
            material: true,
            asset: true,
            ...JSON.parse(storedState),
        };
    } catch (error) {
        return {
            material: true,
            asset: true,
        };
    }
}

function saveSidebarState(state) {
    try {
        localStorage.setItem(sidebarStorageKey, JSON.stringify(state));
    } catch (error) {
        console.warn('Cannot save sidebar state.');
    }
}

function applySidebarGroupState(groupElement, isOpen) {
    const toggleButton = groupElement.querySelector('.menu-group-toggle');

    groupElement.classList.toggle('is-collapsed', !isOpen);

    if (toggleButton) {
        toggleButton.setAttribute('aria-expanded', String(isOpen));
    }
}

function initializeSidebarGroups() {
    const state = getSidebarState();
    const groups = document.querySelectorAll('[data-sidebar-group]');

    groups.forEach((groupElement) => {
        const groupName = groupElement.dataset.sidebarGroup;
        const toggleButton = groupElement.querySelector('.menu-group-toggle');
        const isOpen = state[groupName] !== false;

        applySidebarGroupState(groupElement, isOpen);

        if (!toggleButton) {
            return;
        }

        toggleButton.addEventListener('click', () => {
            const currentState = getSidebarState();
            const nextIsOpen = groupElement.classList.contains('is-collapsed');

            currentState[groupName] = nextIsOpen;

            applySidebarGroupState(groupElement, nextIsOpen);
            saveSidebarState(currentState);
        });
    });
}

function preventDisabledMenuReload() {
    document.querySelectorAll('.disabled-link').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
        });
    });
}

// ── Formatting ──────────────────────────────────────────────────────────────

const moneyFormatter = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const textCollator = new Intl.Collator('th', { numeric: true, sensitivity: 'base' });

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
}

/** 1234567.891 → "1,234,567.89" */
function formatMoney(value) {
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
        return '-';
    }
    return moneyFormatter.format(Number(value));
}

function formatCount(value) {
    return Number(value ?? 0).toLocaleString('en-US');
}

/** Buddhist Era year, for display only. */
function thaiYear(ceYear) {
    return Number(ceYear) + 543;
}

function toNumber(value) {
    return value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? null : Number(value);
}

function filterValue(element) {
    const value = element?.value ?? '';
    return value === '' || value === ALL_OPTION_VALUE ? null : value;
}

function forecastErrorMessage(status, json) {
    if (status === 419) return 'เซสชันหมดอายุ กรุณารีเฟรชหน้าเว็บแล้วลองอีกครั้ง';
    if (status === 401) return 'กรุณาเข้าสู่ระบบใหม่อีกครั้ง';

    if (status === 422 && json?.errors) {
        const first = Object.values(json.errors).flat()[0];
        if (first) return first;
    }

    // Controlled errors from the forecast endpoint carry a code; any other message may be internal.
    if (json?.code && json?.message) return json.message;

    return 'เกิดข้อผิดพลาดในการพยากรณ์ กรุณาลองอีกครั้ง';
}

function forecastPeriodLabel(json) {
    if (!json.start_year || !json.end_year) return '';
    return json.start_year === json.end_year
        ? `พ.ศ. ${thaiYear(json.start_year)}`
        : `พ.ศ. ${thaiYear(json.start_year)} – ${thaiYear(json.end_year)}`;
}

// ── Sorting ─────────────────────────────────────────────────────────────────
//
// Same behaviour, markup and icons as resources/views/components/sortable-th.blade.php with
// resources/css/components/sort-icon.css: the first click sorts ascending, clicking the active
// ascending column sorts descending, and a new sort returns to page 1. Rows are sorted as a complete
// list before pagination, so sorting never changes which assets or totals are included.

const YEAR_COLUMNS = [
    { key: 'year',            label: 'ปี',                          type: 'number',                               value: (y) => toNumber(y.year) },
    { key: 'asset_count',     label: 'จำนวนครุภัณฑ์',                type: 'number', className: 'forecast-center', value: (y) => toNumber(y.asset_count) },
    { key: 'forecast_budget', label: 'งบประมาณที่คาดการณ์ (บาท)',    type: 'number', className: 'forecast-num',    value: (y) => toNumber(y.forecast_budget) },
];

function noSort() {
    return { key: null, direction: 'asc' };
}

function nextSort(current, key) {
    return { key, direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc' };
}

function isBlank(value) {
    return value === null || value === undefined || value === '';
}

/** Sorted copy of the complete row list. Empty values stay last; equal values keep the original order. */
function sortRows(rows, columns, sort) {
    const column = columns.find((c) => c.key === sort.key);
    if (!column) return rows.slice();

    const factor = sort.direction === 'desc' ? -1 : 1;

    return rows
        .map((row, index) => ({ row, index, value: column.value(row) }))
        .sort((x, y) => {
            const xBlank = isBlank(x.value);
            const yBlank = isBlank(y.value);
            if (xBlank || yBlank) {
                return xBlank === yBlank ? x.index - y.index : (xBlank ? 1 : -1);
            }

            const order = column.type === 'number'
                ? x.value - y.value
                : textCollator.compare(String(x.value), String(y.value));

            return order * factor || x.index - y.index;
        })
        .map((entry) => entry.row);
}

function sortableHeader(column, sort, table) {
    const isActive = sort.key === column.key;
    const iconClass = isActive ? (sort.direction === 'asc' ? 'sort-asc' : 'sort-desc') : 'sort-neutral';
    const iconText = isActive ? (sort.direction === 'asc' ? '↑' : '↓') : '⇅';
    const tooltip = isActive
        ? (sort.direction === 'asc' ? 'คลิกเพื่อเรียงจากมากไปน้อย' : 'คลิกเพื่อเรียงจากน้อยไปมาก')
        : 'เรียงจากน้อยไปมาก';
    const ariaSort = isActive ? `${sort.direction}ending` : 'none';

    return `<th class="${column.className ?? ''}" aria-sort="${ariaSort}">`
        + `<button type="button" class="sort-link" data-sort-table="${table}" data-sort-key="${column.key}" title="${tooltip}" aria-label="${escapeHtml(column.label)} — ${tooltip}">`
        + `<span class="sort-label">${escapeHtml(column.label)}</span><span class="sort-icon ${iconClass}" aria-hidden="true">${iconText}</span>`
        + '</button></th>';
}

// ── Rendering ───────────────────────────────────────────────────────────────

function renderSummary(json) {
    const period = forecastPeriodLabel(json);

    return `
    <div class="forecast-ai-summary">
        <div class="ai-summary-row">
            <div class="ai-summary-item">
                <span>ระยะเวลาพยากรณ์</span>
                <strong>${escapeHtml(json.forecast_years)} ปี</strong>
                ${period ? `<small class="forecast-period">${period}</small>` : ''}
            </div>
            <div class="ai-summary-item">
                <span>จำนวนครุภัณฑ์ที่คาดว่าจะทดแทน</span>
                <strong>${formatCount(json.total_assets)} รายการ</strong>
            </div>
            <div class="ai-summary-item highlight">
                <span>งบประมาณรวมที่คาดการณ์</span>
                <strong>${formatMoney(json.total_forecast_budget)} บาท</strong>
                <small class="forecast-period">มูลค่าตอนซื้อ + ${escapeHtml(json.increase_percent)}%</small>
            </div>
        </div>
    </div>`;
}

/**
 * Yearly table. Each year links to its assets and "แสดงทั้งหมด" to every asset of the forecast period
 * (forecast-assets.blade.php, in this tab; its ย้อนกลับ and the browser's back button show this result again).
 * The total row always comes last.
 */
function renderYearTable(json, sort, assetsUrl) {
    const years = sortRows(Array.isArray(json.years) ? json.years : [], YEAR_COLUMNS, sort);

    let html = `<div class="forecast-section-title">ผลการพยากรณ์รายปี</div>
        <table class="forecast-year-table">
            <thead><tr>${YEAR_COLUMNS.map((column) => sortableHeader(column, sort, 'years')).join('')}</tr></thead>
            <tbody>`;

    for (const y of years) {
        html += `<tr>
            <td><a class="forecast-year-link" href="${escapeHtml(assetsUrl)}?year=${encodeURIComponent(y.year)}">พ.ศ. ${thaiYear(y.year)} <span class="year-ce">(ค.ศ. ${escapeHtml(y.year)})</span></a></td>
            <td class="forecast-center">${formatCount(y.asset_count)}</td>
            <td class="forecast-num">${formatMoney(y.forecast_budget)}</td>
        </tr>`;
    }

    html += `<tr class="forecast-all-row">
            <td colspan="3"><a class="forecast-year-link" href="${escapeHtml(assetsUrl)}">แสดงทั้งหมด</a></td>
        </tr>`;

    html += `<tr class="forecast-total-row">
            <td>รวม</td>
            <td class="forecast-center">${formatCount(json.total_assets)}</td>
            <td class="forecast-num">${formatMoney(json.total_forecast_budget)}</td>
        </tr>
        </tbody></table>`;

    return html;
}

function renderForecast(json, container, assetsUrl) {
    const warnings = Array.isArray(json.warnings) ? json.warnings : [];

    let html = renderSummary(json);

    if (warnings.length > 0) {
        html += `<div class="forecast-warning-box"><ul>${warnings.map((w) => `<li>${escapeHtml(w)}</li>`).join('')}</ul></div>`;
    }

    if (!json.total_assets) {
        const period = forecastPeriodLabel(json);
        html += `<p class="forecast-empty-msg">ไม่พบครุภัณฑ์ที่คาดว่าจะถึงกำหนดทดแทนในช่วง${period ? ` ${period}` : 'เวลาที่เลือก'}</p>`;
        container.innerHTML = html;
        return;
    }

    html += `<div class="forecast-year-table-wrap" id="forecastYearSection">${renderYearTable(json, noSort(), assetsUrl)}</div>`;

    container.innerHTML = html;
}

// ── Dropdown placement ──────────────────────────────────────────────────────

/** Open lists may be wider than their field; flip to right-aligned if they would leave the viewport. */
function keepDropdownListsInViewport(root) {
    root.querySelectorAll('.guja-autocomplete__items').forEach((list) => {
        new MutationObserver(() => {
            if (!list.classList.contains('is-visible')) return;

            // Inline styles do not retrigger this class-only observer.
            list.style.left = '';
            list.style.right = '';
            if (list.getBoundingClientRect().right > document.documentElement.clientWidth - 8) {
                list.style.left = 'auto';
                list.style.right = '0';
            }
        }).observe(list, { attributes: true, attributeFilter: ['class'] });
    });
}

// ── Page ────────────────────────────────────────────────────────────────────

/** The last calculation embedded by the server for ?restore=1 (forecast.blade.php), or null. */
function restoredForecast() {
    try {
        const json = JSON.parse(document.getElementById('forecastRestored')?.textContent ?? 'null');
        return json?.success ? json : null;
    } catch {
        return null;
    }
}

/**
 * Marks this page's address with ?restore=1 once a result is shown, so coming back to it with the browser's
 * back button (the year links open in this tab) shows the result again instead of an empty page.
 */
function rememberResultInAddress() {
    const url = new URL(window.location.href);
    if (url.searchParams.get('restore') === '1') return;
    url.searchParams.set('restore', '1');
    window.history.replaceState(window.history.state, '', url);
}

function initializeForecastPage() {
    const root     = document.getElementById('forecastPage');
    const calcBtn  = document.getElementById('forecastCalcBtn');
    const resultEl = document.getElementById('forecastResult');

    if (!root || !calcBtn || !resultEl) return;

    const yearsEl    = document.getElementById('forecastYears');
    const catGroupEl = document.getElementById('forecastCatGroup');
    const orgIdEl    = document.getElementById('forecastOrgId');
    const percentEl  = document.getElementById('forecastPercent');

    let latestResult = null;
    let yearSort = noSort();

    keepDropdownListsInViewport(root);

    latestResult = restoredForecast();
    if (latestResult) {
        renderForecast(latestResult, resultEl, root.dataset.assetsUrl);
    }

    calcBtn.addEventListener('click', async () => {
        calcBtn.disabled = true;
        calcBtn.textContent = 'กำลังคำนวณ...';
        latestResult = null;
        resultEl.innerHTML = '<p class="forecast-loading-msg">กำลังวิเคราะห์ข้อมูลและพยากรณ์...</p>';

        const orgValue = filterValue(orgIdEl);
        const payload = {
            forecast_years:   parseInt(yearsEl?.value || '1', 10),
            // หมวดครุภัณฑ์ name (ASSET_CATEGORY.asscat_group); null = ทั้งหมด
            filter_cat_group: filterValue(catGroupEl),
            filter_org_id:    orgValue !== null ? parseInt(orgValue, 10) : null,
            // งบประมาณทดแทน = มูลค่าตอนซื้อ + this percentage; the server validates it (0–1000)
            increase_percent: (percentEl?.value ?? '').trim() === '' ? null : Number(percentEl.value),
        };

        try {
            const res = await fetch(root.dataset.forecastUrl, {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'Accept':           'application/json',
                    'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            const json = await res.json().catch(() => null);

            if (!res.ok || !json?.success) {
                resultEl.innerHTML = `<p class="forecast-error-msg">${escapeHtml(forecastErrorMessage(res.status, json))}</p>`;
                return;
            }

            latestResult = json;
            yearSort = noSort();
            renderForecast(json, resultEl, root.dataset.assetsUrl);
            rememberResultInAddress();
        } catch {
            resultEl.innerHTML = '<p class="forecast-error-msg">ไม่สามารถเชื่อมต่อบริการได้ กรุณาลองอีกครั้ง</p>';
        } finally {
            calcBtn.disabled    = false;
            calcBtn.textContent = 'คำนวณ';
        }
    });

    // Sorting only re-renders the yearly table; the summary and totals stay as calculated.
    resultEl.addEventListener('click', (event) => {
        const sortButton = event.target.closest('[data-sort-table="years"][data-sort-key]');
        const section = document.getElementById('forecastYearSection');
        if (!latestResult || !sortButton || !section) return;

        const key = sortButton.dataset.sortKey;
        yearSort = nextSort(yearSort, key);
        section.innerHTML = renderYearTable(latestResult, yearSort, root.dataset.assetsUrl);
        section.querySelector(`[data-sort-table="years"][data-sort-key="${key}"]`)?.focus();
    });

}

document.addEventListener('DOMContentLoaded', () => {
    initializeSidebarGroups();
    preventDisabledMenuReload();
    initSearchableSelects();
    initializeForecastPage();
});
