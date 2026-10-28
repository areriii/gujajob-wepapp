import '../../components/date-picker.js';

/**
 * ASS-007 Approval review page.
 *
 * Single #approveForm holds the approval_date input, per-item selling_min_price
 * inputs, and a hidden reject_reason field. The "อนุมัติ"/"ไม่อนุมัติ" buttons
 * only open a shared confirm modal; on confirm, the form action is switched to
 * the matching route and submitted (no separate AJAX call, backend validates).
 */

const approveForm = document.getElementById('approveForm');
if (approveForm) {
    const approveTriggerBtn  = document.getElementById('approveTriggerBtn');
    const rejectTriggerBtn   = document.getElementById('rejectTriggerBtn');
    const saveEditBtn        = document.getElementById('saveApprovalEditBtn');
    const overlay            = document.getElementById('approvalConfirmOverlay');
    const iconWrapper        = document.getElementById('approvalConfirmIcon');
    const iconUse            = document.getElementById('approvalConfirmIconUse');
    const titleEl            = document.getElementById('approvalConfirmTitle');
    const messageEl          = document.getElementById('approvalConfirmMessage');
    const reasonInput        = document.getElementById('rejectReasonInput');
    const reasonError        = document.getElementById('rejectReasonError');
    const cancelBtn          = document.getElementById('cancelApprovalButton');
    const confirmBtn         = document.getElementById('confirmApprovalButton');
    const validationAlert    = document.getElementById('approvalValidationAlert');
    const approvalDateInput  = document.getElementById('approvalDate');
    const approvalDecision   = document.getElementById('approvalDecision');
    const priceInputs        = Array.from(document.querySelectorAll('.price-input'));

    const approveUrl = approveForm.action;
    const rejectUrl  = approveForm.dataset.rejectUrl || '';
    const formMode = approveForm.dataset.formMode || 'consider';
    const isLost = approveForm.dataset.isLost === '1';

    let pendingMode = null;

    function showAlert(message) {
        if (!validationAlert) return;
        validationAlert.textContent = message;
        validationAlert.hidden = false;
    }

    function hideAlert() {
        if (!validationAlert) return;
        validationAlert.hidden = true;
        validationAlert.textContent = '';
    }

    function clearPriceErrors() {
        priceInputs.forEach((input) => input.classList.remove('is-invalid'));
    }

    function validateApprovalDate() {
        if (!approvalDateInput || approvalDateInput.value.trim() === '') {
            showAlert('กรุณาระบุวันที่พิจารณา');
            return false;
        }
        return true;
    }

    function validatePrices() {
        clearPriceErrors();
        let valid = true;
        priceInputs.forEach((input) => {
            const value = parseFloat(input.value);
            if (input.value.trim() === '' || Number.isNaN(value) || value < 0) {
                input.classList.add('is-invalid');
                valid = false;
            }
        });
        if (!valid) {
            showAlert('กรุณากรอกราคาขายขั้นต่ำของทุกรายการให้ถูกต้อง (ต้องไม่ติดลบ)');
        }
        return valid;
    }

    function validateRejectReason() {
        if (!reasonInput) return true;
        if (reasonInput.value.trim() !== '') {
            reasonError.hidden = true;
            return true;
        }
        reasonError.textContent = 'กรุณากรอกหมายเหตุหากไม่อนุมัติ';
        reasonError.hidden = false;
        reasonInput.focus();
        return false;
    }

    function openModal(mode) {
        pendingMode = mode;
        if (mode === 'approve') {
            iconWrapper.className = 'confirm-icon success-confirm-icon';
            iconUse.setAttribute('href', '#icon-success');
            titleEl.textContent = 'ยืนยันการอนุมัติ';
            messageEl.textContent = 'คุณแน่ใจหรือไม่ว่าต้องการอนุมัติใบแจ้งจำหน่ายนี้';
            confirmBtn.className = 'modal-confirm-btn success-confirm-btn';
        } else if (mode === 'reject') {
            iconWrapper.className = 'confirm-icon delete-confirm-icon';
            iconUse.setAttribute('href', '#icon-alert-triangle');
            titleEl.textContent = 'ยืนยันไม่อนุมัติ';
            messageEl.textContent = 'คุณแน่ใจหรือไม่ว่าต้องการไม่อนุมัติใบแจ้งจำหน่ายนี้';
            confirmBtn.className = 'modal-confirm-btn delete-confirm-btn';
        } else {
            iconWrapper.className = 'confirm-icon success-confirm-icon save-confirm-icon';
            iconUse.setAttribute('href', '#icon-success');
            titleEl.textContent = 'ยืนยันการบันทึก';
            messageEl.textContent = 'คุณแน่ใจหรือไม่ว่าต้องการบันทึกผลการอนุมัตินี้';
            confirmBtn.className = 'modal-confirm-btn success-confirm-btn';
        }
        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-hidden', 'true');
        pendingMode = null;
    }

    approveTriggerBtn?.addEventListener('click', () => {
        hideAlert();
        if (!validateApprovalDate() || (!isLost && !validatePrices())) return;
        openModal('approve');
    });

    rejectTriggerBtn?.addEventListener('click', () => {
        hideAlert();
        if (!validateApprovalDate() || !validateRejectReason()) return;
        openModal('reject');
    });

    saveEditBtn?.addEventListener('click', () => {
        hideAlert();
        const decision = approvalDecision?.value || '1';
        if (!validateApprovalDate()) return;
        if (decision === '1' && !isLost && !validatePrices()) return;
        if (decision === '2' && !validateRejectReason()) return;
        openModal('edit');
    });

    reasonInput?.addEventListener('input', () => {
        if (reasonInput.value.trim() !== '') {
            reasonError.hidden = true;
        }
    });

    cancelBtn?.addEventListener('click', closeModal);
    overlay?.addEventListener('click', (event) => {
        if (event.target === overlay) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay?.classList.contains('is-visible')) closeModal();
    });

    confirmBtn?.addEventListener('click', () => {
        if (formMode === 'edit') {
            approveForm.submit();
            return;
        }

        if (pendingMode === 'reject') {
            approveForm.action = rejectUrl;
        } else {
            approveForm.action = approveUrl;
        }
        approveForm.submit();
    });
}

