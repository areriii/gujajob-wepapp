import { SearchAutocomplete } from '../../components/search-autocomplete.js';
import { initDatePickers } from '../../components/date-picker.js';

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

function initializeWithdrawSearch() {
    const searchType = document.getElementById('withdrawSearchType');
    const searchInput = document.getElementById('withdrawSearchInput');
    const statusSelect = document.getElementById('withdrawStatus');
    const searchButton = document.getElementById('withdrawSearchButton');
    const tableBody = document.getElementById('withdrawTableBody');
    const resultText = document.getElementById('withdrawResultText');

    if (!searchType || !searchInput || !statusSelect || !searchButton || !tableBody || !resultText) {
        return;
    }

    const originalRows = Array.from(tableBody.querySelectorAll('tr'));

    function updatePlaceholder() {
        if (searchType.value === 'withdraw_date') {
            searchInput.placeholder = 'กรอกวันที่เบิก';
            return;
        }

        if (searchType.value === 'requester') {
            searchInput.placeholder = 'กรอกชื่อผู้เบิก';
            return;
        }

        if (searchType.value === 'department') {
            searchInput.placeholder = 'กรอกหน่วยงาน';
            return;
        }

        searchInput.placeholder = 'กรอกเลขที่ใบเบิกวัสดุ';
    }

    function getTargetText(row) {
        if (!row || !row.dataset) return '';

        if (searchType.value === 'withdraw_date') {
            return (row.dataset.withdrawDate ?? '').toLowerCase();
        }

        if (searchType.value === 'requester') {
            return (row.dataset.requester ?? '').toLowerCase();
        }

        if (searchType.value === 'department') {
            return (row.dataset.department ?? '').toLowerCase();
        }

        return (row.dataset.withdrawNo ?? '').toLowerCase();
    }

    function updateResultText(visibleCount) {
        if (visibleCount === 0) {
            resultText.textContent = 'ไม่พบประวัติการเบิกวัสดุ';
            return;
        }

        resultText.textContent = `แสดง 1–${visibleCount} จากทั้งหมด ${visibleCount} รายการ`;
    }

    function searchWithdrawRecords() {
        const keyword = searchInput.value.trim().toLowerCase();
        const selectedStatus = statusSelect.value;
        let visibleCount = 0;

        originalRows.forEach((row) => {
            const targetText = getTargetText(row);
            const rowStatus = row.dataset.status;

            const keywordMatched = targetText.includes(keyword);
            const statusMatched = !selectedStatus || rowStatus === selectedStatus;
            const isVisible = keywordMatched && statusMatched;

            row.style.display = isVisible ? '' : 'none';

            if (isVisible) {
                visibleCount += 1;
            }
        });

        const oldNoDataRow = tableBody.querySelector('.no-data-row');

        if (oldNoDataRow) {
            oldNoDataRow.remove();
        }

        if (visibleCount === 0) {
            const noDataRow = document.createElement('tr');
            noDataRow.className = 'no-data-row';
            noDataRow.innerHTML = '<td class="no-data" colspan="6">ไม่พบข้อมูลที่ค้นหา</td>';
            tableBody.appendChild(noDataRow);
        }

        updateResultText(visibleCount);
    }

    searchType.addEventListener('change', () => {
        searchInput.value = '';
        updatePlaceholder();
        searchWithdrawRecords();
    });

    statusSelect.addEventListener('change', searchWithdrawRecords);
    searchButton.addEventListener('click', searchWithdrawRecords);

    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            searchWithdrawRecords();
        }
    });

    searchInput.addEventListener('input', () => {
        if (searchInput.value.trim() === '') {
            searchWithdrawRecords();
        }
    });

    updatePlaceholder();
    searchWithdrawRecords();
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

function initializeWithdrawCreatePage() {
    const withdrawDepartment = document.getElementById('withdrawDepartment');
    const approveDepartment  = document.getElementById('approveDepartment');

    const materialSearchType  = document.getElementById('materialSearchType');
    const materialSearchInput = document.getElementById('materialSearchInput');
    const findButton          = document.getElementById('findWithdrawMaterialButton');

    const materialCode = document.getElementById('materialCode');
    const materialName = document.getElementById('materialName');
    const withdrawQty  = document.getElementById('withdrawQty');
    const materialUnit = document.getElementById('materialUnit');
    const addButton    = document.getElementById('addWithdrawMaterialButton');
    const tableBody    = document.getElementById('withdrawItemsBody');

    const overlay       = document.getElementById('saveWithdrawOverlay');
    const cancelButton  = document.getElementById('cancelSaveWithdrawButton');
    const confirmButton = document.getElementById('confirmSaveWithdrawButton');
    const confirmText   = document.getElementById('saveWithdrawConfirmText');

    if (!withdrawDepartment || !approveDepartment) {
        return;
    }

    // Both department fields show the same value (no login system yet)
    approveDepartment.value = withdrawDepartment.value;

    // Tracks the material currently shown in the add-item form.
    let currentMaterial = null;
    let stockRequestToken = 0; // incremented to discard stale async stock responses

    // ─── Autocomplete ───────────────────────────────────────────────────
    let autocomplete = null;

    if (materialSearchInput) {
        autocomplete = new SearchAutocomplete({
            inputEl:      materialSearchInput,
            searchTypeEl: materialSearchType,
            endpoint:     '/material/search-api',
            minChars:     1,
            debounceMs:   300,
            maxResults:   20,
            onSelect: async (material) => {
                currentMaterial    = material;
                materialCode.value = material.code;
                materialName.value = material.name;
                materialUnit.value = material.unit;
                withdrawQty.value  = '';

                await fetchAndShowStock(material);

                if (withdrawQty) {
                    withdrawQty.focus();
                }
            },
        });
    }

    function updateSearchPlaceholder() {
        if (!materialSearchType || !materialSearchInput) return;

        if (materialSearchType.value === 'name') {
            materialSearchInput.placeholder = 'กรอกชื่อวัสดุ';
            return;
        }

        materialSearchInput.placeholder = 'กรอกรหัสวัสดุ';
    }

    function fillMaterialForm(material, qty = '') {
        currentMaterial    = material;
        materialCode.value = material.code;
        materialName.value = material.name;
        materialUnit.value = material.unit;
        withdrawQty.value  = qty;
    }

    function clearMaterialForm() {
        currentMaterial    = null;
        materialCode.value = '';
        materialName.value = '';
        withdrawQty.value  = '';
        materialUnit.value = '';
        ++stockRequestToken;
        hideStockInfo();
    }

    // ── Stock info helper ────────────────────────────────────
    const stockInfoEl = document.getElementById('stockInfo');

    function showStockInfo(text, isWarning) {
        if (!stockInfoEl) return;
        stockInfoEl.textContent = text;
        stockInfoEl.className = isWarning ? 'stock-info-text stock-info-warning' : 'stock-info-text';
        stockInfoEl.hidden = false;
    }

    function hideStockInfo() {
        if (!stockInfoEl) return;
        stockInfoEl.hidden = true;
        stockInfoEl.textContent = '';
    }

    async function fetchStock(matId) {
        try {
            // The withdrawal type decides whose store is checked: the parent organization or our own.
            const mode = document.getElementById('withdrawTypeHidden')?.value ?? '';
            const res = await fetch(`/material/MAT-003-withdraw-material/stock-check?material_id=${encodeURIComponent(matId)}&withdraw_mode=${encodeURIComponent(mode)}`);
            if (!res.ok) return null;
            const data = await res.json();
            return data.available; // number or null
        } catch {
            return null;
        }
    }

    async function fetchAndShowStock(material) {
        const token = ++stockRequestToken;
        const available = await fetchStock(material.id);
        if (token !== stockRequestToken) return; // stale – user changed selection mid-flight
        if (available === null) {
            showStockInfo('คงเหลือ: ยังไม่ได้ตั้งค่า Stock', false);
        } else {
            showStockInfo(`คงเหลือ: ${available} ${material.unit}`, available === 0);
        }
    }

    async function findMaterial() {
        const keyword = materialSearchInput.value.trim();

        if (!keyword) {
            alert('กรุณากรอกคำค้นหาวัสดุ');
            return;
        }

        const type = materialSearchType ? materialSearchType.value : 'code';
        const url  = `/material/search-api?type=${encodeURIComponent(type)}&q=${encodeURIComponent(keyword)}`;

        try {
            const res = await fetch(url);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const results = await res.json();

            if (!Array.isArray(results) || results.length === 0) {
                clearMaterialForm();
                alert('ไม่พบข้อมูลวัสดุที่ค้นหา');
                return;
            }

            fillMaterialForm(results[0]);
            await fetchAndShowStock(results[0]);
        } catch {
            alert('เกิดข้อผิดพลาดในการค้นหาวัสดุ กรุณาลองใหม่อีกครั้ง');
        }
    }

    function reorderRows() {
        tableBody.querySelectorAll('tr').forEach((row, index) => {
            row.children[0].textContent = index + 1;
        });
    }

    // ── Recommendations: materials this organization usually withdraws with the listed ones ──
    const recommendBox  = document.getElementById('withdrawRecommendations');
    const recommendList = document.getElementById('withdrawRecommendationList');
    let recommendRequestToken = 0; // discards stale responses when the list changes mid-flight

    function hideRecommendations() {
        if (!recommendBox || !recommendList) return;
        recommendBox.hidden = true;
        recommendList.replaceChildren();
    }

    function renderRecommendations(items) {
        if (!recommendBox || !recommendList) return;
        if (items.length === 0) {
            hideRecommendations();
            return;
        }

        recommendList.replaceChildren(...items.map((item) => {
            const card = document.createElement('div');
            card.className = 'withdraw-recommend-item';

            const info = document.createElement('div');
            info.className = 'withdraw-recommend-info';

            const code = document.createElement('span');
            code.className = 'material-code';
            code.textContent = item.code;

            const name = document.createElement('span');
            name.className = 'withdraw-recommend-name';
            name.textContent = item.name;

            const reason = document.createElement('span');
            reason.className = 'withdraw-recommend-reason';
            reason.textContent = `เบิกคู่กับ${item.base_name} · ${item.together} จาก ${item.base_count} ครั้ง (${Math.round(item.confidence * 100)}%)`;

            const stock = document.createElement('span');
            stock.className = item.available > 0 ? 'withdraw-recommend-stock' : 'withdraw-recommend-stock is-empty';
            stock.textContent = `คงเหลือ ${item.available} ${item.unit}`;

            info.append(code, name, reason, stock);

            const pick = document.createElement('button');
            pick.className = 'withdraw-recommend-btn';
            pick.type = 'button';
            pick.textContent = 'เลือก';
            pick.addEventListener('click', async () => {
                const material = { id: item.mat_id, code: item.code, name: item.name, unit: item.unit };
                if (materialSearchInput) materialSearchInput.value = '';
                fillMaterialForm(material);
                await fetchAndShowStock(material);
                withdrawQty.focus();
            });

            card.append(info, pick);
            return card;
        }));
        recommendBox.hidden = false;
    }

    async function refreshRecommendations() {
        if (!recommendBox || !recommendList) return;

        const token  = ++recommendRequestToken;
        const matIds = Array.from(tableBody.querySelectorAll('tr[data-mat-id]')).map((row) => row.dataset.matId);
        if (matIds.length === 0) {
            hideRecommendations();
            return;
        }

        try {
            const mode = document.getElementById('withdrawTypeHidden')?.value ?? '';
            const res  = await fetch(`${recommendBox.dataset.url}?mat_ids=${encodeURIComponent(matIds.join(','))}&withdraw_mode=${encodeURIComponent(mode)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const payload = await res.json();
            if (token !== recommendRequestToken) return;
            renderRecommendations(Array.isArray(payload.data) ? payload.data : []);
        } catch {
            if (token === recommendRequestToken) hideRecommendations();
        }
    }

    function buildActionButtons() {
        return `
            <div class="table-action-buttons">
                <button
                    class="table-action-icon table-action-edit"
                    type="button"
                    aria-label="แก้ไข"
                    title="แก้ไข"
                    data-tooltip="แก้ไข"
                    data-mode="edit"
                >
                    <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                </button>
                <button
                    class="table-action-icon table-action-delete"
                    type="button"
                    aria-label="ลบ"
                    title="ลบ"
                    data-tooltip="ลบ"
                >
                    <svg aria-hidden="true"><use href="#icon-trash"></use></svg>
                </button>
            </div>
        `;
    }

    function addRow(matId, code, name, qty, unit) {
        const row      = document.createElement('tr');
        const rowCount = tableBody.querySelectorAll('tr').length + 1;

        row.dataset.matId = matId;
        row.dataset.code  = code;
        row.dataset.name  = name;
        row.dataset.qty   = qty;
        row.dataset.unit  = unit;

        row.innerHTML = `
            <td>${rowCount}</td>
            <td><span class="material-code">${code}</span></td>
            <td>${name}</td>
            <td><input class="table-qty-input" type="number" value="${qty}" min="1" disabled></td>
            <td>${unit}</td>
            <td>${buildActionButtons()}</td>
        `;

        tableBody.appendChild(row);
    }

    // Switch a row into inline edit mode
    function enterEditMode(editBtn, qtyInput) {
        qtyInput.disabled = false;
        qtyInput.focus();
        qtyInput.select();

        editBtn.querySelector('use').setAttribute('href', '#icon-success');
        editBtn.setAttribute('aria-label', 'ยืนยัน');
        editBtn.setAttribute('title', 'ยืนยัน');
        editBtn.setAttribute('data-tooltip', 'ยืนยัน');
        editBtn.classList.remove('table-action-edit');
        editBtn.classList.add('table-action-save');
        editBtn.dataset.mode = 'save';
    }

    // Validate and commit inline edit; returns true on success
    function exitEditMode(editBtn, qtyInput, row) {
        const qty = Number(qtyInput.value);

        if (Number.isNaN(qty) || qty <= 0) {
            alert('จำนวนที่เบิกต้องมากกว่า 0');
            qtyInput.focus();
            qtyInput.select();
            return false;
        }

        row.dataset.qty   = qty;
        qtyInput.disabled = true;

        editBtn.querySelector('use').setAttribute('href', '#icon-square-pen');
        editBtn.setAttribute('aria-label', 'แก้ไข');
        editBtn.setAttribute('title', 'แก้ไข');
        editBtn.setAttribute('data-tooltip', 'แก้ไข');
        editBtn.classList.remove('table-action-save');
        editBtn.classList.add('table-action-edit');
        editBtn.dataset.mode = 'edit';

        return true;
    }

    if (materialSearchType) {
        materialSearchType.addEventListener('change', () => {
            materialSearchInput.value = '';
            updateSearchPlaceholder();
            if (currentMaterial !== null) clearMaterialForm();
        });
    }

    if (findButton) {
        findButton.addEventListener('click', () => {
            if (autocomplete) autocomplete.triggerSearch();
        });
    }
    // Enter key in search box is handled by the autocomplete's own keydown listener.

    // Clear selected material when user edits the search text after a selection (REQ 11/12).
    if (materialSearchInput) {
        materialSearchInput.addEventListener('input', () => {
            if (currentMaterial !== null) clearMaterialForm();
        });
    }

    if (addButton) {
        addButton.addEventListener('click', async () => {
            if (!currentMaterial) {
                alert('กรุณาค้นหาและเลือกวัสดุก่อน');
                return;
            }

            const raw = withdrawQty.value.trim();
            const qty = Number(raw);

            if (raw === '' || !Number.isInteger(qty) || qty <= 0) {
                alert('กรุณาระบุจำนวนที่เบิกเป็นจำนวนเต็มบวกมากกว่า 0');
                withdrawQty.focus();
                return;
            }

            // Duplicate check
            const existingIds = Array.from(tableBody.querySelectorAll('tr[data-mat-id]'))
                .map((r) => r.dataset.matId);

            if (existingIds.includes(String(currentMaterial.id))) {
                alert('วัสดุรายการนี้ถูกเพิ่มในใบเบิกแล้ว');
                return;
            }

            // Stock guard: block only when stock is known (not null) and is insufficient
            const available = await fetchStock(currentMaterial.id);

            if (available !== null && qty > available) {
                alert(`จำนวนที่เบิก (${qty}) เกินจำนวนคงเหลือ (${available} ${currentMaterial.unit})`);
                return;
            }

            addRow(currentMaterial.id, currentMaterial.code, currentMaterial.name, qty, currentMaterial.unit);
            reorderRows();
            // Clear editor after adding
            currentMaterial    = null;
            materialCode.value = '';
            materialName.value = '';
            withdrawQty.value  = '';
            materialUnit.value = '';
            if (materialSearchInput) materialSearchInput.value = '';
            hideStockInfo();
            refreshRecommendations();
        });
    }

    if (tableBody) {
        // Click: delete row or toggle inline edit / save
        tableBody.addEventListener('click', (event) => {
            const row = event.target.closest('tr');
            if (!row) return;

            const deleteBtn = event.target.closest('.table-action-delete');
            if (deleteBtn) {
                row.remove();
                reorderRows();
                refreshRecommendations();
                return;
            }

            // After enterEditMode the class switches to .table-action-save; match both states.
            const editBtn = event.target.closest('.table-action-edit, .table-action-save');
            if (editBtn) {
                const qtyInput = row.querySelector('.table-qty-input');
                if (!qtyInput) return;

                if (editBtn.dataset.mode === 'save') {
                    exitEditMode(editBtn, qtyInput, row);
                } else {
                    enterEditMode(editBtn, qtyInput);
                }
            }
        });

        // Enter key in qty input = save inline edit
        tableBody.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;

            const qtyInput = event.target;
            if (!qtyInput.classList.contains('table-qty-input')) return;

            event.preventDefault();
            const row     = qtyInput.closest('tr');
            const editBtn = row?.querySelector('.table-action-save');

            if (editBtn && editBtn.dataset.mode === 'save') {
                exitEditMode(editBtn, qtyInput, row);
            }
        });

        // Keep row data-qty in sync while typing
        tableBody.addEventListener('input', (event) => {
            if (!event.target.classList.contains('table-qty-input')) return;

            const row = event.target.closest('tr');
            if (row) row.dataset.qty = event.target.value;
        });
    }

    const saveLargeLotButton    = document.getElementById('saveLargeLotButton');
    const saveInternalUseButton = document.getElementById('saveInternalUseButton');
    const legacySaveButton      = saveLargeLotButton ?? document.getElementById('saveWithdrawButton');

    function serializeItems() {
        const rows = Array.from(tableBody.querySelectorAll('tr[data-mat-id]'));
        return rows.map((row) => ({
            mat_id: Number(row.dataset.matId),
            code:   row.dataset.code,
            name:   row.dataset.name,
            amount: Number(row.dataset.qty),
            unit:   row.dataset.unit,
        }));
    }

    function openSaveConfirm(withdrawType, labelText) {
        const items = serializeItems();
        if (items.length === 0) {
            alert('กรุณาเพิ่มรายการวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ');
            return;
        }
        const hiddenInput    = document.getElementById('itemsJsonHidden');
        const withdrawTypeEl = document.getElementById('withdrawTypeHidden');
        if (hiddenInput)    hiddenInput.value    = JSON.stringify(items);
        if (withdrawTypeEl) withdrawTypeEl.value = withdrawType;
        if (confirmText)    confirmText.textContent = labelText;
        openOverlay(overlay);
    }

    if (saveLargeLotButton) {
        saveLargeLotButton.addEventListener('click', () => {
            openSaveConfirm('LARGE_LOT', 'เบิกจากหน่วยงานต้นทาง - รอการอนุมัติ');
        });
    }

    if (saveInternalUseButton) {
        saveInternalUseButton.addEventListener('click', () => {
            openSaveConfirm('INTERNAL_USE', 'เบิกใช้ภายในหน่วยงาน - อนุมัติ');
        });
    }

    // Legacy single-button fallback (if blade still has the old button)
    if (!saveLargeLotButton && legacySaveButton) {
        legacySaveButton.addEventListener('click', () => {
            const typeEl = document.getElementById('withdrawTypeHidden');
            const type = typeEl?.value ?? 'LARGE_LOT';
            const descriptions = {
                LARGE_LOT:    'เบิกจากหน่วยงานต้นทาง - รอการอนุมัติ',
                INTERNAL_USE: 'เบิกใช้ภายในหน่วยงาน - อนุมัติ',
            };
            openSaveConfirm(type, descriptions[type] ?? '');
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', () => closeOverlay(overlay));
    }

    if (overlay) {
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                closeOverlay(overlay);
            }
        });
    }

    if (confirmButton) {
        confirmButton.addEventListener('click', () => {
            closeOverlay(overlay);
            const form = document.getElementById('withdrawForm');
            if (form) {
                form.submit();
            }
        });
    }

    // ── Restore items from old() after validation error ───────────────────
    if (window.mat003OldItems && Array.isArray(window.mat003OldItems) && tableBody) {
        window.mat003OldItems.forEach((item) => {
            if (item.mat_id && item.amount > 0) {
                addRow(item.mat_id, item.code || '', item.name || '', item.amount, item.unit || '');
            }
        });
        reorderRows();
        refreshRecommendations();
    }

    updateSearchPlaceholder();
}

function initializeIndexDeleteModal() {
    const overlay       = document.getElementById('indexDeleteOverlay');
    const cancelButton  = document.getElementById('cancelIndexDeleteButton');
    const confirmButton = document.getElementById('confirmIndexDeleteButton');
    const deleteForm    = document.getElementById('indexDeleteForm');
    const messageEl     = document.getElementById('indexDeleteMessage');

    if (!overlay || !cancelButton || !confirmButton || !deleteForm) {
        return;
    }

    document.querySelectorAll('[data-delete-btn]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const code = btn.dataset.code ?? '';
            const form = btn.closest('form');
            if (form) {
                deleteForm.action = form.action;
            }
            if (messageEl) {
                messageEl.textContent = 'ต้องการลบใบเบิกวัสดุ ' + code + ' ใช่หรือไม่?';
            }
            openOverlay(overlay);
        });
    });

    cancelButton.addEventListener('click', () => closeOverlay(overlay));
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) closeOverlay(overlay);
    });
    confirmButton.addEventListener('click', () => {
        closeOverlay(overlay);
        deleteForm.submit();
    });
}

initializeIndexDeleteModal();

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    document.querySelectorAll('.confirm-overlay.is-visible').forEach((overlay) => {
        closeOverlay(overlay);
    });
});

function initializeWithdrawEditPage() {
    const editForm = document.getElementById('withdrawEditForm');

    if (!editForm) {
        return;
    }

    const materialSearchType   = document.getElementById('editMaterialSearchType');
    const materialSearchInput  = document.getElementById('editMaterialSearchInput');
    const findButton           = document.getElementById('editFindMaterialButton');
    const materialCode         = document.getElementById('editMaterialCode');
    const materialName         = document.getElementById('editMaterialName');
    const withdrawQty          = document.getElementById('editWithdrawQty');
    const materialUnit         = document.getElementById('editMaterialUnit');
    const addButton            = document.getElementById('editAddMaterialButton');
    const tableBody            = document.getElementById('editWithdrawItemsBody');
    const stockInfoEl          = document.getElementById('editStockInfo');

    const saveButton           = document.getElementById('editSaveButton');
    const saveOverlay          = document.getElementById('editConfirmOverlay');
    const saveCancelBtn        = document.getElementById('editCancelConfirmButton');
    const saveConfirmBtn       = document.getElementById('editConfirmSaveButton');
    const itemsJsonHidden      = document.getElementById('editItemsJsonHidden');

    const deleteItemOverlay    = document.getElementById('editDeleteItemOverlay');
    const deleteItemCancelBtn  = document.getElementById('editDeleteItemCancelButton');
    const deleteItemConfirmBtn = document.getElementById('editDeleteItemConfirmButton');

    let currentMaterial       = null;
    let editStockRequestToken = 0; // incremented to discard stale async stock responses
    let pendingDeleteRow      = null;
    let autocomplete          = null;

    // ── Local helpers: inline row editing ────────────────────────────────────
    function enterEditMode(editBtn, qtyInput) {
        qtyInput.disabled = false;
        qtyInput.focus();
        qtyInput.select();
        editBtn.querySelector('use').setAttribute('href', '#icon-success');
        editBtn.setAttribute('aria-label', 'ยืนยัน');
        editBtn.setAttribute('title',      'ยืนยัน');
        editBtn.setAttribute('data-tooltip', 'ยืนยัน');
        editBtn.classList.remove('table-action-edit');
        editBtn.classList.add('table-action-save');
        editBtn.dataset.mode = 'save';
    }

    function exitEditMode(editBtn, qtyInput, row) {
        const qty = Number(qtyInput.value);

        if (Number.isNaN(qty) || qty <= 0) {
            alert('จำนวนที่เบิกต้องมากกว่า 0');
            qtyInput.focus();
            qtyInput.select();
            return false;
        }

        row.dataset.qty   = qty;
        qtyInput.disabled = true;
        editBtn.querySelector('use').setAttribute('href', '#icon-square-pen');
        editBtn.setAttribute('aria-label',   'แก้ไข');
        editBtn.setAttribute('title',        'แก้ไข');
        editBtn.setAttribute('data-tooltip', 'แก้ไข');
        editBtn.classList.remove('table-action-save');
        editBtn.classList.add('table-action-edit');
        editBtn.dataset.mode = 'edit';
        return true;
    }

    // ── Autocomplete ──────────────────────────────────────────────────────────
    if (materialSearchInput) {
        autocomplete = new SearchAutocomplete({
            inputEl:      materialSearchInput,
            searchTypeEl: materialSearchType,
            endpoint:     '/material/search-api',
            minChars:     1,
            debounceMs:   300,
            maxResults:   20,
            onSelect: async (material) => {
                currentMaterial = material;
                if (materialCode) materialCode.value = material.code;
                if (materialName) materialName.value = material.name;
                if (materialUnit) materialUnit.value = material.unit;
                if (withdrawQty)  withdrawQty.value  = '';

                await editFetchAndShowStock(material);

                if (withdrawQty) withdrawQty.focus();
            },
        });
    }

    function updateSearchPlaceholder() {
        if (!materialSearchType || !materialSearchInput) return;
        materialSearchInput.placeholder =
            materialSearchType.value === 'name' ? 'กรอกชื่อวัสดุ' : 'กรอกรหัสวัสดุ';
    }

    function editShowStockInfo(text, isWarning) {
        if (!stockInfoEl) return;
        stockInfoEl.textContent = text;
        stockInfoEl.className   = isWarning ? 'stock-info-text stock-info-warning' : 'stock-info-text';
        stockInfoEl.hidden      = false;
    }

    function editHideStockInfo() {
        if (!stockInfoEl) return;
        stockInfoEl.hidden      = true;
        stockInfoEl.textContent = '';
    }

    function clearEditMaterialForm() {
        currentMaterial = null;
        if (materialCode)        materialCode.value        = '';
        if (materialName)        materialName.value        = '';
        if (withdrawQty)         withdrawQty.value         = '';
        if (materialUnit)        materialUnit.value        = '';
        if (materialSearchInput) materialSearchInput.value = '';
        ++editStockRequestToken;
        editHideStockInfo();
    }

    async function editFetchStock(matId) {
        try {
            const res = await fetch(
                `/material/MAT-003-withdraw-material/stock-check?material_id=${encodeURIComponent(matId)}`
            );
            if (!res.ok) return null;
            const data = await res.json();
            return data.available;
        } catch {
            return null;
        }
    }

    async function editFetchAndShowStock(material) {
        const token = ++editStockRequestToken;
        const available = await editFetchStock(material.id);
        if (token !== editStockRequestToken) return; // stale – user changed selection mid-flight
        if (available === null) {
            editShowStockInfo('คงเหลือ: ยังไม่ได้ตั้งค่า Stock', false);
        } else {
            editShowStockInfo(`คงเหลือ: ${available} ${material.unit}`, available === 0);
        }
    }

    function reorderEditRows() {
        if (!tableBody) return;
        tableBody.querySelectorAll('tr[data-mat-id]').forEach((row, index) => {
            const firstCell = row.querySelector('td:first-child');
            if (firstCell) firstCell.textContent = index + 1;
        });
    }

    function buildEditActionButtons() {
        return `
            <div class="table-action-buttons">
                <button
                    class="table-action-icon table-action-edit"
                    type="button"
                    aria-label="แก้ไข"
                    title="แก้ไข"
                    data-tooltip="แก้ไข"
                    data-mode="edit"
                >
                    <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                </button>
                <button
                    class="table-action-icon table-action-delete"
                    type="button"
                    aria-label="ลบ"
                    title="ลบ"
                    data-tooltip="ลบ"
                >
                    <svg aria-hidden="true"><use href="#icon-trash"></use></svg>
                </button>
            </div>
        `;
    }

    function addEditRow(detailId, matId, code, name, qty, unit) {
        if (!tableBody) return;

        const row      = document.createElement('tr');
        const rowCount = tableBody.querySelectorAll('tr[data-mat-id]').length + 1;

        row.dataset.detailId = String(detailId);
        row.dataset.matId    = String(matId);
        row.dataset.code     = code;
        row.dataset.name     = name;
        row.dataset.qty      = String(qty);
        row.dataset.unit     = unit;

        row.innerHTML = `
            <td>${rowCount}</td>
            <td><span class="material-code">${code}</span></td>
            <td>${name}</td>
            <td><input class="table-qty-input" type="number" value="${qty}" min="1" disabled></td>
            <td>${unit}</td>
            <td>${buildEditActionButtons()}</td>
        `;

        tableBody.appendChild(row);
    }

    // ── Search type change ────────────────────────────────────────────────────
    if (materialSearchType) {
        materialSearchType.addEventListener('change', () => {
            if (materialSearchInput) materialSearchInput.value = '';
            updateSearchPlaceholder();
            if (currentMaterial !== null) clearEditMaterialForm();
        });
    }

    // ── Find button ───────────────────────────────────────────────────────────
    if (findButton) {
        findButton.addEventListener('click', () => {
            if (autocomplete) autocomplete.triggerSearch();
        });
    }

    // Clear selected material when user edits the search text after a selection (REQ 11/12).
    if (materialSearchInput) {
        materialSearchInput.addEventListener('input', () => {
            if (currentMaterial !== null) clearEditMaterialForm();
        });
    }

    // ── Add button ────────────────────────────────────────────────────────────
    if (addButton) {
        addButton.addEventListener('click', async () => {
            if (!currentMaterial) {
                alert('กรุณาค้นหาและเลือกวัสดุก่อน');
                return;
            }

            const raw = withdrawQty ? withdrawQty.value.trim() : '';
            const qty = Number(raw);

            if (raw === '' || !Number.isInteger(qty) || qty <= 0) {
                alert('กรุณาระบุจำนวนที่เบิกเป็นจำนวนเต็มบวกมากกว่า 0');
                if (withdrawQty) withdrawQty.focus();
                return;
            }

            // Duplicate check
            const existingMatIds = Array.from(tableBody.querySelectorAll('tr[data-mat-id]'))
                .map((r) => r.dataset.matId);

            if (existingMatIds.includes(String(currentMaterial.id))) {
                alert('วัสดุรายการนี้ถูกเพิ่มในใบเบิกแล้ว');
                return;
            }

            // Stock guard
            const available = await editFetchStock(currentMaterial.id);
            if (available !== null && qty > available) {
                alert(`จำนวนที่เบิก (${qty}) เกินจำนวนคงเหลือ (${available} ${currentMaterial.unit})`);
                return;
            }

            addEditRow(0, currentMaterial.id, currentMaterial.code, currentMaterial.name, qty, currentMaterial.unit);
            reorderEditRows();
            clearEditMaterialForm();
        });
    }

    // ── Table events ──────────────────────────────────────────────────────────
    if (tableBody) {
        tableBody.addEventListener('click', (event) => {
            const row = event.target.closest('tr');
            if (!row) return;

            // Delete button → open confirmation popup
            const deleteBtn = event.target.closest('.table-action-delete');
            if (deleteBtn) {
                pendingDeleteRow = row;
                openOverlay(deleteItemOverlay);
                document.body.style.overflow = 'hidden';
                if (deleteItemCancelBtn) deleteItemCancelBtn.focus();
                return;
            }

            // Edit/save button → toggle inline edit
            // NOTE: after enterEditMode the class switches to .table-action-save;
            // selector must match both states.
            const editBtn = event.target.closest('.table-action-edit, .table-action-save');
            if (editBtn) {
                const qtyInput = row.querySelector('.table-qty-input');
                if (!qtyInput) return;

                if (editBtn.dataset.mode === 'save') {
                    exitEditMode(editBtn, qtyInput, row);
                } else {
                    // Close any other row currently in edit mode
                    tableBody.querySelectorAll('.table-action-save[data-mode="save"]').forEach((otherBtn) => {
                        if (otherBtn !== editBtn) {
                            const otherRow   = otherBtn.closest('tr');
                            const otherInput = otherRow?.querySelector('.table-qty-input');
                            if (otherInput) exitEditMode(otherBtn, otherInput, otherRow);
                        }
                    });
                    enterEditMode(editBtn, qtyInput);
                }
            }
        });

        // Enter key in qty input commits inline edit
        tableBody.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            const qtyInput = event.target;
            if (!qtyInput.classList.contains('table-qty-input')) return;
            event.preventDefault();
            const row     = qtyInput.closest('tr');
            const editBtn = row?.querySelector('.table-action-save');
            if (editBtn && editBtn.dataset.mode === 'save') {
                exitEditMode(editBtn, qtyInput, row);
            }
        });

        // Keep data-qty in sync while typing
        tableBody.addEventListener('input', (event) => {
            if (!event.target.classList.contains('table-qty-input')) return;
            const row = event.target.closest('tr');
            if (row) row.dataset.qty = event.target.value;
        });
    }

    // ── Delete item overlay ───────────────────────────────────────────────────
    if (deleteItemCancelBtn) {
        deleteItemCancelBtn.addEventListener('click', () => {
            pendingDeleteRow = null;
            closeOverlay(deleteItemOverlay);
            document.body.style.overflow = '';
        });
    }

    if (deleteItemOverlay) {
        deleteItemOverlay.addEventListener('click', (event) => {
            if (event.target === deleteItemOverlay) {
                pendingDeleteRow = null;
                closeOverlay(deleteItemOverlay);
                document.body.style.overflow = '';
            }
        });
    }

    if (deleteItemConfirmBtn) {
        deleteItemConfirmBtn.addEventListener('click', () => {
            if (pendingDeleteRow) {
                pendingDeleteRow.remove();
                reorderEditRows();
                pendingDeleteRow = null;
            }
            closeOverlay(deleteItemOverlay);
            document.body.style.overflow = '';
        });
    }

    // ── Save button ───────────────────────────────────────────────────────────
    if (saveButton) {
        saveButton.addEventListener('click', () => {
            // Commit any open inline edit first (class is .table-action-save when editing)
            if (tableBody) {
                tableBody.querySelectorAll('.table-action-save[data-mode="save"]').forEach((editBtn) => {
                    const row      = editBtn.closest('tr');
                    const qtyInput = row?.querySelector('.table-qty-input');
                    if (qtyInput) exitEditMode(editBtn, qtyInput, row);
                });
            }

            if (!tableBody) return;
            const rows = Array.from(tableBody.querySelectorAll('tr[data-mat-id]'));

            if (rows.length === 0) {
                alert('กรุณาเพิ่มรายการวัสดุอย่างน้อย 1 รายการ');
                return;
            }

            const items = rows.map((row) => ({
                detail_id: Number(row.dataset.detailId ?? 0),
                mat_id:    Number(row.dataset.matId),
                code:      row.dataset.code,
                name:      row.dataset.name,
                amount:    Number(row.dataset.qty),
                unit:      row.dataset.unit,
            }));

            if (itemsJsonHidden) {
                itemsJsonHidden.value = JSON.stringify(items);
            }

            openOverlay(saveOverlay);
            document.body.style.overflow = 'hidden';
            if (saveCancelBtn) saveCancelBtn.focus();
        });
    }

    // ── Save confirmation overlay ──────────────────────────────────────────────
    if (saveCancelBtn) {
        saveCancelBtn.addEventListener('click', () => {
            closeOverlay(saveOverlay);
            document.body.style.overflow = '';
            if (saveButton) saveButton.focus();
        });
    }

    if (saveOverlay) {
        saveOverlay.addEventListener('click', (event) => {
            if (event.target === saveOverlay) {
                closeOverlay(saveOverlay);
                document.body.style.overflow = '';
            }
        });
    }

    if (saveConfirmBtn) {
        saveConfirmBtn.addEventListener('click', () => {
            saveConfirmBtn.disabled    = true;
            saveConfirmBtn.textContent = 'กำลังบันทึก...';
            editForm.submit();
        });
    }

    // ── Finalize buttons (DRAFT mode) ─────────────────────────────────────────
    const finalizeRegionalBtn      = document.getElementById('finalizeRegionalBtn');
    const finalizeInternalBtn      = document.getElementById('finalizeInternalBtn');
    const finalizeRegionalOverlay  = document.getElementById('finalizeRegionalOverlay');
    const finalizeInternalOverlay  = document.getElementById('finalizeInternalOverlay');
    const withdrawFinalizeForm     = document.getElementById('withdrawFinalizeForm');
    const finalizeItemsJson        = document.getElementById('finalizeItemsJson');
    const finalizeAction           = document.getElementById('finalizeAction');
    const finalizeDateHidden       = document.getElementById('finalizeDateHidden');
    const finalizePersonHidden     = document.getElementById('finalizePersonHidden');

    function buildFinalizeItems() {
        if (!tableBody) return [];
        return Array.from(tableBody.querySelectorAll('tr[data-mat-id]')).map((row) => ({
            detail_id: Number(row.dataset.detailId ?? 0),
            mat_id:    Number(row.dataset.matId),
            code:      row.dataset.code,
            name:      row.dataset.name,
            amount:    Number(row.dataset.qty),
            unit:      row.dataset.unit,
        }));
    }

    function openFinalizeFlow(action, overlay) {
        const items = buildFinalizeItems();
        if (items.length === 0) {
            alert('กรุณาเพิ่มรายการวัสดุอย่างน้อย 1 รายการก่อนบันทึก');
            return;
        }
        if (finalizeItemsJson) finalizeItemsJson.value = JSON.stringify(items);
        if (finalizeAction) finalizeAction.value = action;
        // Copy current header values into finalize form hidden inputs
        const dateInput   = document.getElementById('editWithdrawDate');
        const personInput = document.getElementById('editWithdrawerName');
        if (finalizeDateHidden && dateInput) finalizeDateHidden.value = dateInput.value;
        if (finalizePersonHidden && personInput) finalizePersonHidden.value = personInput.value;
        openOverlay(overlay);
        document.body.style.overflow = 'hidden';
    }

    if (finalizeRegionalBtn) {
        finalizeRegionalBtn.addEventListener('click', () => {
            openFinalizeFlow('LARGE_LOT', finalizeRegionalOverlay);
        });
    }

    if (finalizeInternalBtn) {
        finalizeInternalBtn.addEventListener('click', () => {
            openFinalizeFlow('INTERNAL_USE', finalizeInternalOverlay);
        });
    }

    const cancelFinalizeRegionalBtn  = document.getElementById('cancelFinalizeRegionalBtn');
    const cancelFinalizeInternalBtn  = document.getElementById('cancelFinalizeInternalBtn');
    const confirmFinalizeRegionalBtn = document.getElementById('confirmFinalizeRegionalBtn');
    const confirmFinalizeInternalBtn = document.getElementById('confirmFinalizeInternalBtn');

    if (cancelFinalizeRegionalBtn) {
        cancelFinalizeRegionalBtn.addEventListener('click', () => {
            closeOverlay(finalizeRegionalOverlay);
            document.body.style.overflow = '';
        });
    }

    if (cancelFinalizeInternalBtn) {
        cancelFinalizeInternalBtn.addEventListener('click', () => {
            closeOverlay(finalizeInternalOverlay);
            document.body.style.overflow = '';
        });
    }

    if (confirmFinalizeRegionalBtn) {
        confirmFinalizeRegionalBtn.addEventListener('click', () => {
            confirmFinalizeRegionalBtn.disabled    = true;
            confirmFinalizeRegionalBtn.textContent = 'กำลังบันทึก...';
            closeOverlay(finalizeRegionalOverlay);
            if (withdrawFinalizeForm) withdrawFinalizeForm.submit();
        });
    }

    if (confirmFinalizeInternalBtn) {
        confirmFinalizeInternalBtn.addEventListener('click', () => {
            confirmFinalizeInternalBtn.disabled    = true;
            confirmFinalizeInternalBtn.textContent = 'กำลังบันทึก...';
            closeOverlay(finalizeInternalOverlay);
            if (withdrawFinalizeForm) withdrawFinalizeForm.submit();
        });
    }

    updateSearchPlaceholder();
}

function initializeWithdrawShowPage() {
    const openBtn   = document.getElementById('openDeleteModalBtn');
    const overlay   = document.getElementById('deleteWithdrawOverlay');
    const closeBtn  = document.getElementById('closeDeleteModalBtn');
    const confirmBtn = document.getElementById('confirmDeleteBtn');

    // If neither button exists, we're not on the show/detail page
    if (!openBtn || !overlay) {
        return;
    }

    // Open the delete confirmation modal
    openBtn.addEventListener('click', () => {
        openOverlay(overlay);
        if (closeBtn) closeBtn.focus();
        document.body.style.overflow = 'hidden';
    });

    // Close modal via cancel button
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            closeOverlay(overlay);
            document.body.style.overflow = '';
            openBtn.focus();
        });
    }

    // Close modal when clicking on the overlay backdrop
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeOverlay(overlay);
            document.body.style.overflow = '';
            openBtn.focus();
        }
    });

    // Also restore scroll when Escape closes the modal
    overlay.addEventListener('transitionend', () => {
        if (!overlay.classList.contains('is-visible')) {
            document.body.style.overflow = '';
        }
    });

    // Disable confirm button on submit to prevent double-click
    if (confirmBtn) {
        const deleteForm = confirmBtn.closest('form');
        if (deleteForm) {
            deleteForm.addEventListener('submit', () => {
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'กำลังลบ...';
            });
        }
    }
}

initializeSidebarGroups();
preventDisabledMenuReload();
initializeWithdrawSearch();
initializeWithdrawCreatePage();
initializeWithdrawShowPage();
initializeWithdrawEditPage();
initializeWithdrawListAutocomplete();

/* ===== Autocomplete for MAT-003 index search bar ===== */

function initializeWithdrawListAutocomplete() {
    const searchInput = document.getElementById('withdrawSearchInput');
    const searchType  = document.getElementById('withdrawSearchType');

    if (!searchInput) return;

    const ac = new SearchAutocomplete({
        inputEl:      searchInput,
        searchTypeEl: searchType,
        endpoint:     window.searchSuggestionsUrl || '/search/suggestions',
        extraParams:  { entity: 'withdrawal' },
        minChars:     1,
        debounceMs:   300,
        maxResults:   15,
        onSelect: (item) => {
            searchInput.value = item.code;
            ac.close();
        },
    });
}


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

        // Editor-only inputs validated by the "เพิ่ม" button, not by form submit
        if ('validateOnAdd' in (field.dataset ?? {})) {
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
            const hasRows = hasAtLeastOneRealRow('#withdrawItemsBody')
                && hasAtLeastOneRealRow('#editWithdrawItemsBody');

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
