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

function initializeMaterialRegister() {
    const dataScript = document.getElementById('registerDataJson');
    const searchType = document.getElementById('searchType');
    const searchInput = document.getElementById('searchInput');
    const departmentName = document.getElementById('departmentName');
    const searchButton = document.getElementById('searchRegisterButton');

    const materialTitle = document.getElementById('materialTitle');
    const summaryForward = document.getElementById('summaryForward');
    const summaryIn = document.getElementById('summaryIn');
    const summaryOut = document.getElementById('summaryOut');
    const summaryBalance = document.getElementById('summaryBalance');
    const tableBody = document.getElementById('registerTableBody');
    const resultText = document.getElementById('registerResultText');

    if (!dataScript || !searchType || !searchInput || !searchButton || !tableBody) {
        return;
    }

    const registerData = JSON.parse(dataScript.textContent);

    function updatePlaceholder() {
        if (searchType.value === 'name') {
            searchInput.placeholder = 'กรอกชื่อวัสดุ';
            return;
        }

        searchInput.placeholder = 'กรอกรหัสวัสดุ';
    }

    function findRegister() {
        const keyword = searchInput.value.trim().toLowerCase();

        if (!keyword) {
            return registerData['MAT-1001'];
        }

        if (searchType.value === 'name') {
            return Object.values(registerData).find((item) => {
                return item.name.toLowerCase().includes(keyword);
            });
        }

        return registerData[keyword.toUpperCase()];
    }

    function buildReference(referenceNo) {
        if (referenceNo === '-') {
            return '-';
        }

        return `<span class="reference-link">${referenceNo}</span>`;
    }

    function buildOperator(operator, role) {
        if (!role) {
            return `<div>${operator}</div>`;
        }

        return `<div>${operator}</div><small>${role}</small>`;
    }

    function buildTransactionRow(transaction) {
        return `
            <tr>
                <td>${transaction.date}</td>
                <td>${transaction.transaction_no}</td>
                <td>${buildReference(transaction.reference_no)}</td>
                <td>${transaction.detail}</td>
                <td>${buildOperator(transaction.operator, transaction.role)}</td>
                <td class="in-column">${transaction.in}</td>
                <td class="out-column">${transaction.out}</td>
                <td class="balance-column">${transaction.balance}</td>
            </tr>
        `;
    }

    function showNoData() {
        materialTitle.textContent = 'ไม่พบข้อมูลวัสดุ';
        summaryForward.textContent = '-';
        summaryIn.textContent = '-';
        summaryOut.textContent = '-';
        summaryBalance.textContent = '-';
        tableBody.innerHTML = '<tr><td class="no-data" colspan="8">ไม่พบข้อมูลทะเบียนวัสดุที่ค้นหา</td></tr>';
        resultText.textContent = 'ไม่พบรายการ';
    }

    function renderRegister(register) {
        if (!register) {
            showNoData();
            return;
        }

        materialTitle.textContent = `${register.code} : ${register.name}`;
        departmentName.value = register.department;

        summaryForward.textContent = register.summary.forward;
        summaryIn.textContent = `+ ${register.summary.in_total}`;
        summaryOut.textContent = `- ${register.summary.out_total}`;
        summaryBalance.textContent = register.summary.balance;

        tableBody.innerHTML = register.transactions.map(buildTransactionRow).join('');

        const count = register.transactions.length;
        resultText.textContent = `แสดง 1-${count} จากทั้งหมด ${count} รายการ`;
    }

    searchType.addEventListener('change', () => {
        searchInput.value = '';
        updatePlaceholder();
    });

    searchButton.addEventListener('click', () => {
        renderRegister(findRegister());
    });

    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            renderRegister(findRegister());
        }
    });

    updatePlaceholder();
}

// "พิมพ์คุมทะเบียนวัสดุ (PDF)" downloads the server-made PDF, as the print button of the MAT-007 report does.
function initializeRegisterPrint() {
    const printBtn = document.getElementById('printRegisterButton');
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
                link.download = printBtn.dataset.pdfFilename || 'material-register.pdf';
                link.click();
                URL.revokeObjectURL(objectUrl);
            }
        } catch (error) {
            console.error('Failed to generate the material register PDF:', error);
            alert('ไม่สามารถสร้างไฟล์รายงานได้ กรุณาลองใหม่อีกครั้ง');
        } finally {
            isGeneratingPdf = false;
            printBtn.disabled = false;
        }
    });
}

initializeSidebarGroups();
preventDisabledMenuReload();
initSearchableSelects();
initializeMaterialRegister();
initializeRegisterPrint();


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
