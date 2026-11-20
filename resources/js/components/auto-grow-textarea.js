/**
 * ช่องข้อความหลายบรรทัดที่สูงขึ้นเองตามเนื้อหา
 *
 * เดิมช่อง "คุณลักษณะเฉพาะ" สูงคงที่ 3 บรรทัด พิมพ์ยาว ๆ แล้วเห็นได้ทีละ 3 บรรทัด
 * ต้องเลื่อนอ่านในกล่องเล็ก ๆ เอง (ลากมุมขยายได้ แต่ไม่มีอะไรบอกผู้ใช้)
 *
 * ตอนนี้กล่องจะยืดตามข้อความจนถึงความสูงสูงสุดที่กำหนด แล้วจึงค่อยกลายเป็น scroll
 * และยังลากขยายเองได้เหมือนเดิม ถ้าผู้ใช้ลากเอง ระบบจะหยุดยุ่งกับความสูงของช่องนั้น
 *
 * ใช้โดยใส่ data-autogrow ที่ <textarea> ไฟล์นี้ถูกโหลดจาก layout ทุกหน้า
 */

const MAX_HEIGHT_FALLBACK = 320;

function maxHeightFor(textarea) {
    const fromAttribute = Number.parseInt(textarea.dataset.autogrowMax ?? '', 10);
    if (Number.isFinite(fromAttribute) && fromAttribute > 0) {
        return fromAttribute;
    }

    const computed = Number.parseFloat(getComputedStyle(textarea).maxHeight);
    return Number.isFinite(computed) && computed > 0 ? computed : MAX_HEIGHT_FALLBACK;
}

function resize(textarea) {
    if (textarea.dataset.autogrowManual === '1') {
        return;
    }

    const max = maxHeightFor(textarea);

    // ต้องยุบก่อนวัด ไม่งั้น scrollHeight จะค้างที่ความสูงเดิมตอนลบข้อความออก
    textarea.style.height = 'auto';

    const wanted = textarea.scrollHeight + (textarea.offsetHeight - textarea.clientHeight);
    const height = Math.min(wanted, max);

    textarea.style.height = `${height}px`;
    textarea.style.overflowY = wanted > max ? 'auto' : 'hidden';

    // จำความสูงที่สคริปต์เป็นคนตั้งไว้ เพื่อแยกออกจากความสูงที่ผู้ใช้ลากเอง
    // (เทียบกับ style.height ตรง ๆ ไม่ได้ เพราะ offsetHeight รวม padding/border เมื่อ box-sizing เป็น content-box)
    textarea.dataset.autogrowLast = String(textarea.offsetHeight);
}

function watch(textarea) {
    if (textarea.dataset.autogrowReady === '1') {
        return;
    }
    textarea.dataset.autogrowReady = '1';

    const startHeight = textarea.offsetHeight;

    // ผู้ใช้ลากมุมขยายเอง = ตั้งใจกำหนดความสูงเอง ให้สคริปต์เลิกปรับช่องนี้
    if (typeof ResizeObserver === 'function') {
        let settled = false;
        const observer = new ResizeObserver(() => {
            if (!settled) {
                settled = true;
                return;
            }
            const last = Number.parseFloat(textarea.dataset.autogrowLast ?? '');
            if (Number.isFinite(last) && Math.abs(textarea.offsetHeight - last) > 2) {
                textarea.dataset.autogrowManual = '1';
                textarea.style.overflowY = 'auto';
                observer.disconnect();
            }
        });
        observer.observe(textarea);
    }

    textarea.addEventListener('input', () => resize(textarea));

    if (startHeight > 0) {
        resize(textarea);
    }
}

function init() {
    document.querySelectorAll('textarea[data-autogrow]').forEach(watch);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

export { init as initAutoGrowTextareas };
