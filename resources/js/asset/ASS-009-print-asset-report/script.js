/**
 * หน้ากำหนดเงื่อนไขของ ASS-009
 *
 * เดิมทุกช่องเป็นช่องพิมพ์ค้นหาที่ยิง API เอง และปุ่มเดียวทำทั้งพรีวิวและดาวน์โหลด
 * ตามรูปแบบเดียวกับหน้าจัดพิมพ์รายงานวัสดุ ตอนนี้
 *   - ทุกช่องเป็น dropdown ที่มีแต่ตัวเลือกที่ออกรายงานได้จริง
 *   - หน่วยงานตั้งค่าเริ่มต้นตามผู้ใช้ที่ล็อกอิน
 *   - ฟอร์มส่งแบบ GET ไปหน้าพรีวิว แล้วค่อยเลือก PDF/Excel จากหน้านั้น
 */

import { initSearchableSelects, resetSearchableSelect } from '../../components/searchable-select.js';

const options = window.ass009Options ?? { assetsByCategory: {}, subOrgsByParent: {}, fiscalYearsByOrg: {} };

function setError(id, message) {
    const target = document.getElementById(id);
    if (target) target.textContent = message;
}

/** สลับระหว่างรายงานสองแบบ พร้อมล้างค่าของฟอร์มที่ซ่อนไป */
function initReportTypeSelection() {
    const registerBtn = document.querySelector('[data-report-type="register"]');
    const ledgerBtn = document.querySelector('[data-report-type="ledger"]');
    const registerForm = document.getElementById('registerConditions');
    const ledgerForm = document.getElementById('ledgerConditions');

    const show = (activeBtn, inactiveBtn, shownForm, hiddenForm) => {
        activeBtn.classList.add('active');
        inactiveBtn.classList.remove('active');
        shownForm.style.display = '';
        hiddenForm.style.display = 'none';
    };

    registerBtn?.addEventListener('click', () => show(registerBtn, ledgerBtn, registerForm, ledgerForm));
    ledgerBtn?.addEventListener('click', () => show(ledgerBtn, registerBtn, ledgerForm, registerForm));
}

/** รหัสครุภัณฑ์ขึ้นกับประเภทที่เลือก จึงต้องเติมรายการใหม่ทุกครั้งที่ประเภทเปลี่ยน */
function initDependentAssetField() {
    const categoryField = document.getElementById('categoryId');
    const assetField = document.getElementById('assetId');
    if (!categoryField || !assetField) return;

    const assetInput = assetField.closest('.guja-autocomplete')?.querySelector('.guja-autocomplete__input');

    const syncAssets = () => {
        const categoryId = String(categoryField.value || '');
        const list = options.assetsByCategory[categoryId] ?? [];

        resetSearchableSelect('assetId', list, true);

        const wrapper = assetField.closest('.guja-autocomplete');
        wrapper?.classList.toggle('guja-autocomplete--disabled', categoryId === '');

        if (assetInput) {
            assetInput.placeholder = categoryId === ''
                ? 'เลือกประเภทครุภัณฑ์ก่อน'
                : 'เลือกรหัสครุภัณฑ์';
            assetInput.disabled = categoryId === '';
        }

        setError('assetError', '');
    };

    categoryField.addEventListener('change', syncAssets);
    syncAssets();
}

/** หน่วยงานย่อยขึ้นกับหน่วยงาน และปีที่มีข้อมูลขึ้นกับหน่วยงานย่อย */
function initDependentOrgFields() {
    const orgField = document.getElementById('orgId');
    const subOrgField = document.getElementById('subOrgId');
    const fiscalYearField = document.getElementById('fiscalYear');
    const availabilityNote = document.getElementById('fiscalYearAvailability');
    if (!orgField || !subOrgField) return;

    const syncYears = () => {
        const years = options.fiscalYearsByOrg[String(subOrgField.value || '')] ?? [];

        if (availabilityNote) {
            availabilityNote.textContent = years.length
                ? `หน่วยงานย่อยนี้มีข้อมูลในปีงบประมาณ ${years.join(', ')}`
                : '';
        }

        // ปีงบประมาณปัจจุบันอาจยังไม่มีครุภัณฑ์เลย เลือกปีล่าสุดที่มีข้อมูลให้แทน
        if (fiscalYearField && years.length) {
            fiscalYearField.value = years[0];
        }
    };

    const syncSubOrgs = () => {
        const parentId = String(orgField.value || '');
        const list = options.subOrgsByParent[parentId] ?? [];

        resetSearchableSelect('subOrgId', list, false);

        // ถ้าหน่วยงานย่อยที่ค้างอยู่ไม่ได้อยู่ใต้หน่วยงานใหม่ ให้เลือกตัวแรกให้เลย
        // ผู้ใช้จะได้ไม่ต้องกดสองชั้นก่อนดูรายงาน และไม่เหลือคู่ที่เซิร์ฟเวอร์ปฏิเสธ
        const stillValid = list.some((option) => String(option.value) === String(subOrgField.value));

        if (! stillValid) {
            const picked = list[0] ?? null;
            subOrgField.value = picked ? String(picked.value) : '';

            const subInput = subOrgField.closest('.guja-autocomplete')?.querySelector('.guja-autocomplete__input');
            if (subInput) subInput.value = picked ? picked.label : '';
        }

        syncYears();
        setError('subOrgError', '');
    };

    orgField.addEventListener('change', syncSubOrgs);
    subOrgField.addEventListener('change', syncYears);

    syncSubOrgs();
}

/** ช่องว่างต้องเตือนที่ช่อง ไม่ใช่ปล่อยให้เซิร์ฟเวอร์ตีกลับมาเป็นแถบแดง */
function initFormValidation() {
    const rules = {
        registerConditions: [
            { field: 'categoryId', error: 'categoryError', message: 'กรุณาเลือกประเภทครุภัณฑ์' },
            { field: 'assetId', error: 'assetError', message: 'กรุณาเลือกรหัสครุภัณฑ์' },
        ],
        ledgerConditions: [
            { field: 'orgId', error: 'orgError', message: 'กรุณาเลือกหน่วยงาน' },
            { field: 'subOrgId', error: 'subOrgError', message: 'กรุณาเลือกหน่วยงานย่อย' },
            { field: 'fiscalYear', error: null, message: 'กรุณากรอกปีงบประมาณ' },
        ],
    };

    Object.entries(rules).forEach(([formId, fields]) => {
        const form = document.getElementById(formId);

        form?.addEventListener('submit', (event) => {
            let firstInvalid = null;

            fields.forEach(({ field, error, message }) => {
                const element = document.getElementById(field);
                const isEmpty = String(element?.value || '').trim() === '';

                if (error) setError(error, isEmpty ? message : '');
                if (isEmpty && ! firstInvalid) firstInvalid = element;
            });

            if (firstInvalid) {
                event.preventDefault();
                const visible = firstInvalid.closest('.guja-autocomplete')?.querySelector('.guja-autocomplete__input') ?? firstInvalid;
                visible.focus();
            }
        });
    });
}

initSearchableSelects();
initReportTypeSelection();
initDependentAssetField();
initDependentOrgFields();
initFormValidation();
