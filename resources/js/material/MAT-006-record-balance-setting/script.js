import { SearchAutocomplete } from '../../components/search-autocomplete.js';
import { initSearchableSelects } from '../../components/searchable-select.js';

const sidebarStorageKey = 'gujajob.sidebar.groupState';

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

function initializeBalanceSearch() {
    const searchByEl  = document.getElementById('searchBy');
    const searchInput = document.getElementById('searchInput');
    const form        = document.getElementById('balanceSearchForm');

    if (! searchInput) return;

    // Update placeholder when search type changes
    function updatePlaceholder() {
        searchInput.placeholder = searchByEl && searchByEl.value === 'code'
            ? 'กรอกรหัสวัสดุ'
            : 'กรอกชื่อวัสดุ';
    }

    if (searchByEl) {
        searchByEl.addEventListener('change', updatePlaceholder);
    }

    updatePlaceholder();

    // Autocomplete — queries /search/suggestions, entity=material
    new SearchAutocomplete({
        inputEl:      searchInput,
        searchTypeEl: searchByEl,
        endpoint:     '/search/suggestions',
        extraParams:  { entity: 'material' },
        minChars:     1,
        debounceMs:   300,
        maxResults:   20,
        onSelect: (item) => {
            const byName = searchByEl && searchByEl.value === 'name';
            searchInput.value = byName ? (item.name || '') : (item.code || '');
            if (form) form.submit();
        },
    });
}

function openOverlay(overlay) {
    if (!overlay) return;

    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');
}

function closeOverlay(overlay) {
    if (!overlay) return;

    overlay.classList.remove('is-visible');
    overlay.setAttribute('aria-hidden', 'true');
}

/* ===== Draft tracking for bulk save ===== */

/** Map of materialId → {balance, materialCode} for rows pending save. */
const draftMap = new Map();

function updateBulkButton() {
    const btn = document.getElementById('bulkUpdateButton');
    if (!btn) return;
    btn.disabled = draftMap.size === 0;
}

function markRowDraft(row, matId, matCode, newBalance) {
    draftMap.set(matId, { balance: newBalance, materialCode: matCode });
    row.classList.add('row-modified');
    updateBulkButton();
}

function enterBalanceEditMode(editBtn, qtyInput) {
    qtyInput.dataset.originalValue = qtyInput.value;
    qtyInput.disabled = false;
    qtyInput.focus();
    qtyInput.select();
    editBtn.querySelector('use').setAttribute('href', '#icon-success');
    editBtn.setAttribute('aria-label', 'ยืนยันค่า');
    editBtn.setAttribute('title', 'ยืนยันค่า');
    editBtn.setAttribute('data-tooltip', 'ยืนยันค่า');
    editBtn.classList.remove('table-action-edit');
    editBtn.classList.add('table-action-save');
    editBtn.dataset.mode = 'save';
}

function exitBalanceDraftMode(editBtn, qtyInput) {
    const raw = qtyInput.value.trim();
    const qty = Number(raw);

    if (raw === '' || Number.isNaN(qty) || qty < 0 || !Number.isInteger(qty)) {
        qtyInput.focus();
        qtyInput.select();
        return;
    }

    const row    = editBtn.closest('tr');
    const matId  = row?.dataset.materialId;
    const matCode= row?.dataset.materialCode;

    if (matId) {
        markRowDraft(row, matId, matCode, qty);
    }

    qtyInput.disabled = true;
    editBtn.querySelector('use').setAttribute('href', '#icon-square-pen');
    editBtn.setAttribute('aria-label', 'แก้ไขยอดคงเหลือ');
    editBtn.setAttribute('title', 'แก้ไขยอดคงเหลือ');
    editBtn.setAttribute('data-tooltip', 'แก้ไขยอดคงเหลือ');
    editBtn.classList.remove('table-action-save');
    editBtn.classList.add('table-action-edit');
    editBtn.dataset.mode = 'edit';
}

function initializeBalanceInlineEdit() {
    const tableBody = document.querySelector('.balance-table tbody');
    if (!tableBody) return;

    tableBody.addEventListener('click', (event) => {
        const editBtn = event.target.closest('.table-action-edit, .table-action-save');
        if (!editBtn) return;

        const row      = editBtn.closest('tr');
        const qtyInput = row?.querySelector('.balance-qty-input');
        if (!qtyInput) return;

        if (editBtn.dataset.mode === 'save') {
            exitBalanceDraftMode(editBtn, qtyInput);
        } else {
            enterBalanceEditMode(editBtn, qtyInput);
        }
    });

    tableBody.addEventListener('keydown', (event) => {
        const qtyInput = event.target;
        if (!qtyInput.classList.contains('balance-qty-input')) return;

        if (event.key === 'Enter') {
            event.preventDefault();
            const row     = qtyInput.closest('tr');
            const editBtn = row?.querySelector('.table-action-save');
            if (editBtn) exitBalanceDraftMode(editBtn, qtyInput);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            const row     = qtyInput.closest('tr');
            const editBtn = row?.querySelector('.table-action-save');
            if (!editBtn) return;

            qtyInput.value    = qtyInput.dataset.originalValue ?? qtyInput.value;
            qtyInput.disabled = true;
            editBtn.querySelector('use').setAttribute('href', '#icon-square-pen');
            editBtn.setAttribute('aria-label', 'แก้ไขยอดคงเหลือ');
            editBtn.setAttribute('title', 'แก้ไขยอดคงเหลือ');
            editBtn.setAttribute('data-tooltip', 'แก้ไขยอดคงเหลือ');
            editBtn.classList.remove('table-action-save');
            editBtn.classList.add('table-action-edit');
            editBtn.dataset.mode = 'edit';
        }
    });
}

/* ===== Bulk Update (ปรับปรุง) modal + submit ===== */

function initializeBulkUpdate() {
    const bulkBtn     = document.getElementById('bulkUpdateButton');
    const overlay     = document.getElementById('bulkUpdateOverlay');
    const cancelBtn   = document.getElementById('cancelBulkUpdateButton');
    const confirmBtn  = document.getElementById('confirmBulkUpdateButton');

    if (!bulkBtn || !overlay || !cancelBtn || !confirmBtn) return;

    bulkBtn.addEventListener('click', () => {
        if (draftMap.size === 0) return;
        openOverlay(overlay);
    });

    cancelBtn.addEventListener('click', () => closeOverlay(overlay));
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeOverlay(overlay); });

    confirmBtn.addEventListener('click', async () => {
        closeOverlay(overlay);
        confirmBtn.disabled = true;
        bulkBtn.disabled = true;

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const bulkUrl   = bulkBtn.dataset.bulkUrl;
        const fiscalYear= parseInt(bulkBtn.dataset.fiscalYear ?? '0', 10);
        const orgId     = parseInt(bulkBtn.dataset.orgId ?? '0', 10);

        const items = Array.from(draftMap.entries()).map(([matId, data]) => ({
            material_id:   parseInt(matId, 10),
            material_code: data.materialCode,
            balance:       data.balance,
        }));

        try {
            const res  = await fetch(bulkUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept':        'application/json',
                },
                body: JSON.stringify({ fiscal_year: fiscalYear, organization_id: orgId, items }),
            });

            const data = await res.json();

            if (!res.ok || !data.success) {
                alert('บันทึกไม่สำเร็จ: ' + (data.error ?? 'ข้อผิดพลาดไม่ทราบสาเหตุ'));
                bulkBtn.disabled = false;
                confirmBtn.disabled = false;
                return;
            }

            // Clear draft state and reload to reflect DB values
            draftMap.clear();
            document.querySelectorAll('.row-modified').forEach((r) => r.classList.remove('row-modified'));
            bulkBtn.disabled = true;
            // Brief success feedback then reload
            alert(`บันทึกสำเร็จ: ${data.count} รายการ`);
            window.location.reload();

        } catch (err) {
            alert('เกิดข้อผิดพลาด กรุณาลองใหม่');
            bulkBtn.disabled = false;
            confirmBtn.disabled = false;
        }
    });
}

/* ===== Print ===== */

// "จัดพิมพ์รายงาน" downloads the server-made PDF, as the print button of the MAT-007 report does.
function initializePrint() {
    const printBtn = document.getElementById('printBalanceButton');
    if (!printBtn) return;

    let isGeneratingPdf = false;

    const openPdfPreview = (objectUrl) => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(15,23,42,.65)';
        overlay.innerHTML = `
            <div style="position:relative;width:min(960px,100%);height:min(90vh,760px);background:#fff">
                <button type="button" style="position:absolute;right:8px;top:8px;z-index:1;padding:5px 10px;border:0;background:#111827;color:#fff;cursor:pointer">ปิด</button>
                <iframe style="width:100%;height:100%;border:0" src="${objectUrl}" title="PDF preview"></iframe>
            </div>
        `;

        const close = () => {
            overlay.remove();
            URL.revokeObjectURL(objectUrl);
        };

        overlay.querySelector('button').addEventListener('click', close);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
        document.body.appendChild(overlay);
    };

    printBtn.addEventListener('click', async (event) => {
        event.preventDefault();
        if (isGeneratingPdf) return;

        const pdfUrl = printBtn.dataset.pdfUrl;
        if (!pdfUrl) return;

        isGeneratingPdf = true;
        printBtn.disabled = true;
        try {
            const response = await fetch(pdfUrl);
            if (!response.ok) throw new Error(`PDF request failed with status ${response.status}`);

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const isTestPreview = new URL(pdfUrl, window.location.href).searchParams.get('pdf_test') === '1'
                || (response.headers.get('content-disposition') || '').includes('inline');

            if (isTestPreview) {
                openPdfPreview(objectUrl);
            } else {
                const link = document.createElement('a');
                link.href = objectUrl;
                link.download = printBtn.dataset.pdfFilename || 'material-balance-setting.pdf';
                link.click();
                URL.revokeObjectURL(objectUrl);
            }
        } catch (error) {
            console.error('Failed to generate the balance setting PDF:', error);
            alert('ไม่สามารถสร้างไฟล์รายงานได้ กรุณาลองใหม่อีกครั้ง');
        } finally {
            isGeneratingPdf = false;
            printBtn.disabled = false;
        }
    });
}

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    document.querySelectorAll('.confirm-overlay.is-visible').forEach((overlay) => {
        closeOverlay(overlay);
    });
});

initializeSidebarGroups();
preventDisabledMenuReload();
initSearchableSelects();
initializeBalanceSearch();
initializeBalanceInlineEdit();
initializeBulkUpdate();
initializePrint();


/* ===== Inline Balance Edit ===== */

/* ===== Global save validation for create/edit pages ===== */

(function () {
    if (window.__gujajobSaveValidationInstalled) {
        return;
    }

    window.__gujajobSaveValidationInstalled = true;

    const currentPath = window.location.pathname;
    const isFormPage = currentPath.includes('/create') || currentPath.endsWith('/edit') || currentPath.includes('/edit/');

    if (!isFormPage) {
        return;
    }

    function isVisible(element) {
        if (!element) {
            return false;
        }

        const style = window.getComputedStyle(element);

        return style.display !== 'none'
            && style.visibility !== 'hidden'
            && element.offsetParent !== null;
    }

    function getContentRoot() {
        return document.querySelector('.content') || document.body;
    }

    function getFieldLabel(field) {
        if (field.id) {
            const label = document.querySelector(`label[for="${CSS.escape(field.id)}"]`);

            if (label) {
                return label;
            }
        }

        const wrapper = field.closest('.form-field, .field-group');

        if (wrapper) {
            return wrapper.querySelector('label');
        }

        return null;
    }

    function isIgnoredField(field) {
        if (!field || field.disabled || field.readOnly) {
            return true;
        }

        if (!isVisible(field)) {
            return true;
        }

        const ignoredTypes = ['hidden', 'button', 'submit', 'reset', 'file'];

        if (ignoredTypes.includes(field.type)) {
            return true;
        }

        const id = field.id || '';
        const name = field.name || '';
        const ignoredKeyword = ['search', 'keyword', 'filter'];

        return ignoredKeyword.some((word) => {
            return id.toLowerCase().includes(word) || name.toLowerCase().includes(word);
        });
    }

    function isRequiredField(field) {
        if (field.required) {
            return true;
        }

        const label = getFieldLabel(field);

        if (!label) {
            return false;
        }

        return label.textContent.includes('*') || Boolean(label.querySelector('.required'));
    }

    function clearValidationState() {
        document.querySelectorAll('.gujajob-is-invalid').forEach((field) => {
            field.classList.remove('gujajob-is-invalid');
        });

        document.querySelectorAll('.gujajob-validation-message').forEach((message) => {
            message.remove();
        });

        const oldAlert = document.querySelector('.gujajob-validation-alert');

        if (oldAlert) {
            oldAlert.remove();
        }
    }

    function markInvalid(field, message) {
        field.classList.add('gujajob-is-invalid');

        const wrapper = field.closest('.form-field, .field-group') || field.parentElement;

        if (wrapper && !wrapper.querySelector('.gujajob-validation-message')) {
            const messageElement = document.createElement('div');
            messageElement.className = 'gujajob-validation-message';
            messageElement.textContent = message;
            wrapper.appendChild(messageElement);
        }
    }

    function showValidationAlert(message) {
        const root = getContentRoot();
        const pageHeader = root.querySelector('.page-header');

        const alert = document.createElement('div');
        alert.className = 'gujajob-validation-alert';
        alert.textContent = message;

        if (pageHeader && pageHeader.parentNode) {
            pageHeader.insertAdjacentElement('afterend', alert);
        } else {
            root.prepend(alert);
        }
    }

    function hasAtLeastOneRealRow(selector) {
        const body = document.querySelector(selector);

        if (!body) {
            return true;
        }

        const rows = Array.from(body.querySelectorAll('tr')).filter((row) => {
            const text = row.textContent.trim();

            return isVisible(row)
                && !row.classList.contains('no-data-row')
                && !text.includes('ยังไม่มีรายการ')
                && !text.includes('ไม่พบข้อมูล')
                && row.querySelectorAll('td').length > 1;
        });

        return rows.length > 0;
    }

    function validateRequiredFields() {
        clearValidationState();

        const root = getContentRoot();
        let isValid = true;
        let firstInvalidField = null;

        const fields = Array.from(root.querySelectorAll('input, select, textarea'));

        fields.forEach((field) => {
            if (isIgnoredField(field)) {
                return;
            }

            const required = isRequiredField(field);
            const value = String(field.value || '').trim();

            if (required && value === '') {
                isValid = false;

                if (!firstInvalidField) {
                    firstInvalidField = field;
                }

                markInvalid(field, 'กรุณากรอกข้อมูลช่องนี้');

                return;
            }

            if (field.type === 'number' && value !== '' && Number(value) < 0) {
                isValid = false;

                if (!firstInvalidField) {
                    firstInvalidField = field;
                }

                markInvalid(field, 'ค่าตัวเลขต้องไม่ติดลบ');
            }
        });

        if (currentPath.includes('MAT-002-record-material-receiving')) {
            const hasRows = hasAtLeastOneRealRow('#receivingItemsBody')
                && hasAtLeastOneRealRow('#receiveItemsBody')
                && hasAtLeastOneRealRow('#materialReceivingItemsBody');

            if (!hasRows) {
                isValid = false;
                showValidationAlert('กรุณาเพิ่มรายการวัสดุที่รับเข้าคลังอย่างน้อย 1 รายการ');
            }
        }

        if (currentPath.includes('MAT-003-withdraw-material')) {
            const hasRows = hasAtLeastOneRealRow('#withdrawItemsBody');

            if (!hasRows) {
                isValid = false;
                showValidationAlert('กรุณาเพิ่มรายการวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ');
            }
        }

        if (!isValid) {
            if (!document.querySelector('.gujajob-validation-alert')) {
                showValidationAlert('กรุณากรอกข้อมูลที่จำเป็นให้ครบก่อนบันทึก');
            }

            if (firstInvalidField) {
                firstInvalidField.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                });

                setTimeout(() => firstInvalidField.focus(), 250);
            }
        }

        return isValid;
    }

    function isMainSaveButton(button) {
        if (!button || button.tagName !== 'BUTTON') {
            return false;
        }

        const text = button.textContent.trim();

        if (!text.includes('บันทึก')) {
            return false;
        }

        if (text.includes('ยืนยัน')) {
            return false;
        }

        if (text.includes('พิมพ์') || text.includes('ดาวน์โหลด')) {
            return false;
        }

        return true;
    }

    function enableAllSaveButtons() {
        const root = getContentRoot();

        Array.from(root.querySelectorAll('button')).forEach((button) => {
            if (!isMainSaveButton(button)) {
                return;
            }

            button.disabled = false;
            button.classList.remove('gujajob-save-disabled');
        });
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('button');

        if (!isMainSaveButton(button)) {
            return;
        }

        const isValid = validateRequiredFields();

        if (!isValid) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('input', (event) => {
        const field = event.target;

        if (!field.classList || !field.classList.contains('gujajob-is-invalid')) {
            return;
        }

        if (String(field.value || '').trim() !== '') {
            field.classList.remove('gujajob-is-invalid');

            const wrapper = field.closest('.form-field, .field-group') || field.parentElement;
            const message = wrapper ? wrapper.querySelector('.gujajob-validation-message') : null;

            if (message) {
                message.remove();
            }
        }
    });

    document.addEventListener('change', (event) => {
        const field = event.target;

        if (!field.classList || !field.classList.contains('gujajob-is-invalid')) {
            return;
        }

        if (String(field.value || '').trim() !== '') {
            field.classList.remove('gujajob-is-invalid');

            const wrapper = field.closest('.form-field, .field-group') || field.parentElement;
            const message = wrapper ? wrapper.querySelector('.gujajob-validation-message') : null;

            if (message) {
                message.remove();
            }
        }
    });

    setTimeout(enableAllSaveButtons, 50);
    setTimeout(enableAllSaveButtons, 300);
})();
