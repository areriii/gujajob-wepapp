import { initSearchableSelects } from '../../components/searchable-select.js';

const sidebarStorageKey = 'gujajob.sidebar.groupState';

/** Detail rows shown per page. Pagination is presentation only: totals always cover every asset. */
const ASSETS_PER_PAGE = 10;

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

/** "2026-09-14" → "14/09/2569" */
function formatThaiDate(isoDate) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate ?? '');
    return match ? `${match[3]}/${match[2]}/${Number(match[1]) + 543}` : '-';
}

function toNumber(value) {
    return value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? null : Number(value);
}

/** Inspect date as YYYY-MM-DD for sorting; falls back to the DD/MM/BBBB display date. */
function inspectDateKey(asset) {
    if (asset.inspect_date) return String(asset.inspect_date);
    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(asset.acceptance_date ?? '');
    return match ? `${Number(match[3]) - 543}-${match[2]}-${match[1]}` : null;
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

const ASSET_COLUMNS = [
    { key: 'sequence',                   label: 'ลำดับ',               type: 'number', className: 'forecast-center', value: (a) => a.sequence },
    { key: 'asset_code',                 label: 'รหัสครุภัณฑ์',          type: 'text',                                 value: (a) => a.asset_code },
    { key: 'category_name',              label: 'ชื่อครุภัณฑ์',          type: 'text',                                 value: (a) => a.category_name },
    { key: 'category_group',             label: 'หมวดครุภัณฑ์',         type: 'text',                                 value: (a) => a.category_group },
    { key: 'organization_name',          label: 'หน่วยงาน',             type: 'text',                                 value: (a) => a.organization_name },
    { key: 'inspect_date',               label: 'วันที่ตรวจรับ',          type: 'text',   className: 'forecast-center', value: inspectDateKey },
    { key: 'forecast_year',              label: 'ปีที่ครบกำหนดทดแทน',   type: 'number', className: 'forecast-center', value: (a) => toNumber(a.forecast_year) },
    { key: 'acquisition_value',          label: 'มูลค่า',               type: 'number', className: 'forecast-num',    value: (a) => toNumber(a.acquisition_value) },
    { key: 'accumulated_depreciation',   label: 'ค่าเสื่อมราคาสะสม',     type: 'number', className: 'forecast-num',    value: (a) => toNumber(a.accumulated_depreciation) },
    { key: 'current_value',              label: 'มูลค่าคงเหลือ',         type: 'number', className: 'forecast-num',    value: (a) => toNumber(a.current_value) },
    { key: 'predicted_replacement_cost', label: 'มูลค่าทดแทน (AI)',      type: 'number', className: 'forecast-num',    value: (a) => (a.prediction_basis === 'unavailable' ? null : toNumber(a.predicted_replacement_cost)) },
];

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
            </div>
        </div>
    </div>`;
}

/** Yearly table. The total row always comes last and uses the totals calculated for every asset. */
function renderYearTable(json, sort) {
    const years = sortRows(Array.isArray(json.years) ? json.years : [], YEAR_COLUMNS, sort);

    let html = `<div class="forecast-section-title">ผลการพยากรณ์รายปี</div>
        <table class="forecast-year-table">
            <thead><tr>${YEAR_COLUMNS.map((column) => sortableHeader(column, sort, 'years')).join('')}</tr></thead>
            <tbody>`;

    for (const y of years) {
        html += `<tr>
            <td>พ.ศ. ${thaiYear(y.year)} <span class="year-ce">(ค.ศ. ${escapeHtml(y.year)})</span></td>
            <td class="forecast-center">${formatCount(y.asset_count)}</td>
            <td class="forecast-num">${formatMoney(y.forecast_budget)}</td>
        </tr>`;
    }

    html += `<tr class="forecast-total-row">
            <td>รวม</td>
            <td class="forecast-center">${formatCount(json.total_assets)}</td>
            <td class="forecast-num">${formatMoney(json.total_forecast_budget)}</td>
        </tr>
        </tbody></table>`;

    return html;
}

/** Same page-number pattern as resources/views/components/app-pagination.blade.php. */
function pageNumbers(current, last) {
    if (last <= 5) return Array.from({ length: last }, (_, i) => i + 1);
    if (current <= 3) return [1, 2, 3, 'ellipsis', last];
    if (current >= last - 2) return [1, 'ellipsis', last - 2, last - 1, last];
    return [1, 'ellipsis', current - 1, current, current + 1, 'ellipsis', last];
}

function renderPagination(current, last) {
    const link = (page, label, ariaLabel) => `<button type="button" class="app-pagination-link" data-forecast-page="${page}" aria-label="${ariaLabel}">${label}</button>`;
    const disabled = (label, ariaLabel) => `<span class="app-pagination-link is-disabled" aria-disabled="true" aria-label="${ariaLabel}">${label}</span>`;

    let html = '<div class="app-pagination-actions">';
    html += current > 1 ? link(current - 1, '‹', 'ย้อนกลับ') : disabled('‹', 'ย้อนกลับ');

    for (const page of pageNumbers(current, last)) {
        if (page === 'ellipsis') {
            html += '<span class="app-pagination-ellipsis" aria-hidden="true">…</span>';
        } else if (page === current) {
            html += `<span class="app-pagination-link is-active" aria-current="page">${page}</span>`;
        } else {
            html += link(page, page, `หน้า ${page}`);
        }
    }

    html += current < last ? link(current + 1, '›', 'ถัดไป') : disabled('›', 'ถัดไป');
    return `${html}</div>`;
}

function renderCostCell(asset) {
    if (asset.prediction_basis === 'unavailable' || asset.predicted_replacement_cost === null || asset.predicted_replacement_cost === undefined) {
        return '<span class="forecast-cost-unavailable">ไม่สามารถพยากรณ์ได้</span>';
    }
    const suffix = asset.prediction_basis === 'asset_price_trend' ? ' *' : '';
    return `${formatMoney(asset.predicted_replacement_cost)}${suffix}`;
}

/**
 * Candidate detail table for one page. `assets` is always the complete candidate list: it is sorted
 * first and only then cut into pages.
 */
function renderAssetSection(assets, requestedPage, sort) {
    const sorted = sortRows(assets, ASSET_COLUMNS, sort);
    const lastPage = Math.max(1, Math.ceil(sorted.length / ASSETS_PER_PAGE));
    const page = Math.min(Math.max(1, requestedPage), lastPage);
    const offset = (page - 1) * ASSETS_PER_PAGE;
    const pageAssets = sorted.slice(offset, offset + ASSETS_PER_PAGE);

    let rows = '';
    pageAssets.forEach((a) => {
        const code = a.asset_code ?? '';
        rows += `<tr>
            <td class="forecast-center">${escapeHtml(a.sequence)}</td>
            <td><a class="ass-code-link asset-code" href="/asset/ASS-003-manage-asset-registration?search_by=code&keyword=${encodeURIComponent(code)}">${escapeHtml(code || '-')}</a></td>
            <td>${escapeHtml(a.category_name ?? '-')}</td>
            <td>${escapeHtml(a.category_group ?? '-')}</td>
            <td>${escapeHtml(a.organization_name ?? '-')}</td>
            <td class="forecast-center">${escapeHtml(a.acceptance_date ?? '-')}</td>
            <td class="forecast-center">พ.ศ. ${thaiYear(a.forecast_year)}</td>
            <td class="forecast-num">${formatMoney(a.acquisition_value)}</td>
            <td class="forecast-num">${formatMoney(a.accumulated_depreciation)}</td>
            <td class="forecast-num">${formatMoney(a.current_value)}</td>
            <td class="forecast-num ai-cost-cell">${renderCostCell(a)}</td>
        </tr>`;
    });

    const hasFallback = assets.some((a) => a.prediction_basis === 'asset_price_trend');

    return `
        <div class="forecast-section-title">รายละเอียดครุภัณฑ์ที่คาดว่าจะทดแทน (ทั้งหมด ${formatCount(sorted.length)} รายการ)</div>
        <div class="forecast-table-card">
            <div class="forecast-table-scroll">
                <table class="forecast-table forecast-candidate-table">
                    <thead><tr>${ASSET_COLUMNS.map((column) => sortableHeader(column, sort, 'assets')).join('')}</tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <div class="table-footer forecast-table-footer">
                <p class="app-pagination-summary">แสดง ${formatCount(offset + 1)}–${formatCount(offset + pageAssets.length)} จากทั้งหมด ${formatCount(sorted.length)} รายการ</p>
                ${renderPagination(page, lastPage)}
            </div>
        </div>
        ${hasFallback ? '<p class="forecast-basis-note">* หมวดที่ไม่มีข้อมูลราคาย้อนหลัง ประมาณจากมูลค่าของครุภัณฑ์ปรับด้วยอัตราการเปลี่ยนแปลงราคาเฉลี่ย</p>' : ''}`;
}

/** What each value column means; มูลค่าคงเหลือ and มูลค่าทดแทน (AI) answer different questions. */
function renderValueNotes(json) {
    const asOf = formatThaiDate(json.depreciation?.as_of_date);

    return `
    <div class="forecast-value-notes">
        <p><strong>มูลค่าคงเหลือ</strong> = มูลค่า − ค่าเสื่อมราคาสะสม ณ วันที่ ${asOf} (หลักเกณฑ์เดียวกับทะเบียนคุมครุภัณฑ์: วิธีเส้นตรง มูลค่า ÷ อายุการใช้งาน คิดตามปีงบประมาณ เริ่มเดือนที่ตรวจรับ ถ้าตรวจรับหลังวันที่ 15 เริ่มเดือนถัดไป คงเหลือ 1 บาทเมื่อครบอายุ) ใช้กำหนดปีที่ครบกำหนดทดแทน</p>
        <p><strong>มูลค่าทดแทน (AI)</strong> = ราคาจัดซื้อครุภัณฑ์ใหม่ชื่อครุภัณฑ์เดียวกันที่คาดการณ์ในปีที่ครบกำหนดทดแทน จากแนวโน้มราคาจัดซื้อย้อนหลัง (Ridge Regression) ไม่ได้คำนวณจากมูลค่าคงเหลือ</p>
    </div>`;
}

function renderModelBadge(json) {
    const m = json.model;
    if (!m) return '';

    const parts = [
        `โมเดล: ${escapeHtml(m.name ?? 'AI')}`,
        `ข้อมูลฝึกสอน: ${formatCount(m.training_records)} รายการ`,
    ];
    if (m.annual_price_growth_pct !== null && m.annual_price_growth_pct !== undefined) {
        parts.push(`อัตราการเปลี่ยนแปลงราคาเฉลี่ย ${Number(m.annual_price_growth_pct).toFixed(2)}% ต่อปี`);
    }
    if (m.mape !== null && m.mape !== undefined && m.evaluation_year) {
        parts.push(`MAPE (ทดสอบปี พ.ศ. ${thaiYear(m.evaluation_year)}): ${Number(m.mape).toFixed(2)}%`);
    }
    if (json.training_data_source === 'demo') {
        parts.push('ข้อมูลตัวอย่าง (DEMO)');
    }
    return `<div class="forecast-model-badge">${parts.join(' · ')}</div>`;
}

function renderForecast(json, assets, container) {
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

    html += `<div class="forecast-year-table-wrap" id="forecastYearSection">${renderYearTable(json, noSort())}</div>`;
    html += `<div class="forecast-asset-detail-wrap" id="forecastAssetSection">${renderAssetSection(assets, 1, noSort())}</div>`;
    html += renderValueNotes(json);
    html += renderModelBadge(json);

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

function initializeForecastPage() {
    const root     = document.getElementById('forecastPage');
    const calcBtn  = document.getElementById('forecastCalcBtn');
    const printBtn = document.getElementById('forecastPrintBtn');
    const resultEl = document.getElementById('forecastResult');

    if (!root || !calcBtn || !resultEl) return;

    const yearsEl    = document.getElementById('forecastYears');
    const catGroupEl = document.getElementById('forecastCatGroup');
    const orgIdEl    = document.getElementById('forecastOrgId');

    let latestResult = null;
    // Complete candidate list in the order returned by the server; `sequence` is the ลำดับ column.
    let latestAssets = [];
    let assetSort = noSort();
    let yearSort = noSort();
    let assetPage = 1;

    keepDropdownListsInViewport(root);

    calcBtn.addEventListener('click', async () => {
        calcBtn.disabled = true;
        calcBtn.textContent = 'กำลังคำนวณ...';
        if (printBtn) printBtn.disabled = true;
        latestResult = null;
        latestAssets = [];
        resultEl.innerHTML = '<p class="forecast-loading-msg">กำลังวิเคราะห์ข้อมูลและพยากรณ์...</p>';

        const orgValue = filterValue(orgIdEl);
        const payload = {
            forecast_years:   parseInt(yearsEl?.value || '1', 10),
            // หมวดครุภัณฑ์ name (ASSET_CATEGORY.asscat_group); null = ทั้งหมด
            filter_cat_group: filterValue(catGroupEl),
            filter_org_id:    orgValue !== null ? parseInt(orgValue, 10) : null,
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
            latestAssets = (Array.isArray(json.assets) ? json.assets : []).map((asset, index) => ({ ...asset, sequence: index + 1 }));
            assetSort = noSort();
            yearSort = noSort();
            assetPage = 1;
            renderForecast(json, latestAssets, resultEl);
            if (printBtn) printBtn.disabled = false;
        } catch {
            resultEl.innerHTML = '<p class="forecast-error-msg">ไม่สามารถเชื่อมต่อบริการได้ กรุณาลองอีกครั้ง</p>';
        } finally {
            calcBtn.disabled    = false;
            calcBtn.textContent = 'คำนวณ';
        }
    });

    // Sorting and paging only re-render their own table; the summary and totals stay as calculated.
    resultEl.addEventListener('click', (event) => {
        if (!latestResult) return;

        const sortButton = event.target.closest('[data-sort-key]');
        if (sortButton) {
            const key = sortButton.dataset.sortKey;
            const table = sortButton.dataset.sortTable;
            const section = document.getElementById(table === 'years' ? 'forecastYearSection' : 'forecastAssetSection');
            if (!section) return;

            if (table === 'years') {
                yearSort = nextSort(yearSort, key);
                section.innerHTML = renderYearTable(latestResult, yearSort);
            } else {
                assetSort = nextSort(assetSort, key);
                assetPage = 1;
                section.innerHTML = renderAssetSection(latestAssets, assetPage, assetSort);
            }
            section.querySelector(`[data-sort-table="${table}"][data-sort-key="${key}"]`)?.focus();
            return;
        }

        const pageButton = event.target.closest('[data-forecast-page]');
        const assetSection = document.getElementById('forecastAssetSection');
        if (!pageButton || !assetSection) return;

        assetPage = Number(pageButton.dataset.forecastPage);
        assetSection.innerHTML = renderAssetSection(latestAssets, assetPage, assetSort);
    });

    printBtn?.addEventListener('click', () => {
        window.open(root.dataset.printUrl, '_blank');
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initializeSidebarGroups();
    preventDisabledMenuReload();
    initSearchableSelects();
    initializeForecastPage();
});
