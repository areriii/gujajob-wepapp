-- =============================================================================
-- Seed: ใบแจ้งขอจำหน่ายครุภัณฑ์สำหรับเดโม ASS-006 / ASS-007 / ASS-008
-- File   : database/scripts/seed_ass006_disposal_demo.sql
-- Schema : ASSET
--
-- ทำไมต้องมีไฟล์นี้
--   ASSET_SELLING และ ASSET_SELLING_LIST ว่างเปล่า (dump ที่กู้มาไม่มีข้อมูลส่วนนี้)
--   ทั้งสามหน้าจึงขึ้น "ไม่พบข้อมูล" ทั้งหมด เดโมไม่ได้
--
-- สร้างใบแจ้งขอจำหน่าย 8 ใบ ครบทุกสถานะที่ระบบรองรับ
--   SL2569001  รอการอนุมัติ   ชำรุด      2 รายการ   -> ใช้เดโม ASS-007 อนุมัติสด
--   SL2569002  รอการอนุมัติ   สูญหาย     1 รายการ   -> สูญหายไม่ต้องกรอกราคา
--   SL2569003  รอการอนุมัติ   ชำรุด      2 รายการ
--   SL2569004  อนุมัติแล้ว     ชำรุด      2 รายการ   -> ใช้เดโม ASS-008 บันทึกผลสด
--   SL2569005  อนุมัติแล้ว     ชำรุด      2 รายการ
--   SL2569006  บันทึกผลแล้ว   ชำรุด      2 รายการ   -> ตัวอย่างงานที่จบแล้ว (มีผู้ซื้อ)
--   SL2569007  บันทึกผลแล้ว   สูญหาย     1 รายการ   -> จบแล้วแบบไม่มีผู้ซื้อ
--   SL2569008  ไม่อนุมัติ      หมดอายุ    1 รายการ   -> ตัวอย่างใบที่ถูกตีกลับ
--
-- รหัสเหตุผล (คอลัมน์ REASON เป็น VARCHAR2 เก็บ '1'/'2'/'3')
--   1 = หมดอายุการใช้งาน   2 = ชำรุดจนซ่อมแซมไม่ได้   3 = สูญหาย
-- สถานะอนุมัติ (SELLING_APPROVAL_STATUS)
--   0 = รอการอนุมัติ   1 = อนุมัติแล้ว   2 = ไม่อนุมัติ
--
-- คุณสมบัติ
--   - เพิ่มอย่างเดียว ไม่ลบข้อมูลเดิม
--   - รันซ้ำได้ ถ้ามีรหัส SL2569* อยู่แล้วจะข้ามทั้งชุด
--   - ใช้ ASSET_SELLING_SEQ / ASSET_SELLING_LIST_SEQ เพื่อให้ sequence ไม่ชนกับที่
--     แอปจะสร้างต่อหลังจากนี้ (ใบถัดไปที่สร้างผ่านหน้าเว็บจะได้รหัส SL2569009)
--
-- ครุภัณฑ์ที่ใช้
--   ใช้เฉพาะ DEMO-ASSET-001..013 (org 382 ฝ่ายพัสดุ) เท่านั้น
--   ไม่แตะ AI-TEST-* เพราะเป็นชุดข้อมูลที่ ASS-003 ใช้พยากรณ์งบประมาณ
--   ตัวเลขพยากรณ์ใน DEMO-RUNBOOK.md จึงไม่เปลี่ยน
--
-- ข้อมูลเดิมที่ถูกแก้ (ถ้าต้องการย้อนกลับ)
--   ASSET.ASS_STATUS ของ id 48, 49 เดิม '1' -> ตั้งเป็น '5' (ชำรุด/จำหน่ายแล้ว)
--   ASSET.ASS_STATUS ของ id 50     เดิม '1' -> ตั้งเป็น '6' (สูญหาย)
--   ย้อนกลับด้วย: UPDATE ASSET SET ass_status='1' WHERE id IN (48,49,50);
--   ระบบตั้งค่านี้เองตอนบันทึกผลใน ASS-008 จึงต้องตั้งให้ตรงกันเพื่อไม่ให้ข้อมูลขัดกัน
--
-- DEMO-ASSET-013 (id 53) ตั้งใจใส่ไว้ในใบที่ "ไม่อนุมัติ" เท่านั้น
--   เพราะใบที่ถูกตีกลับไม่ล็อกครุภัณฑ์ ตัวนี้จึงยังเลือกได้ตอนเดโมสร้างใบใหม่
--   และเป็นตัวเดียวที่ REMAIN_PRICE = 1 ซึ่งเป็นเงื่อนไขของเหตุผล "หมดอายุการใช้งาน"
--
-- วิธีรัน (ต้องตั้ง NLS_LANG ให้เป็น UTF-8 ไม่งั้นภาษาไทยเพี้ยน)
--   set NLS_LANG=AMERICAN_AMERICA.AL32UTF8
--   sqlplus asset/<รหัส>@localhost:1521/orclpdb @database/scripts/seed_ass006_disposal_demo.sql
-- =============================================================================
SET SERVEROUTPUT ON;
SET DEFINE OFF;

DECLARE
    v_exists NUMBER;
    v_sid    NUMBER;
    v_hdr    NUMBER := 0;
    v_lines  NUMBER := 0;

    -- สร้างหัวใบแจ้งขอจำหน่าย แล้วคืน id ไว้ผูกกับรายการครุภัณฑ์
    FUNCTION new_selling(
        p_code       IN VARCHAR2,
        p_req_date   IN DATE,
        p_reason     IN VARCHAR2,
        p_remarks    IN VARCHAR2,
        p_org        IN NUMBER,
        p_status     IN NUMBER,
        p_app_date   IN DATE,
        p_reject     IN VARCHAR2,
        p_buyer      IN VARCHAR2,
        p_decided_by IN NUMBER
    ) RETURN NUMBER IS
        v_id NUMBER;
    BEGIN
        SELECT ASSET_SELLING_SEQ.NEXTVAL INTO v_id FROM DUAL;

        INSERT INTO ASSET_SELLING (
            ID, SELLING_CODE, SELLING_REQ_DATE, REASON, REMARKS,
            REQ_ORG_ID, APP_ORG_ID, SELLING_APPROVAL_DATE, SELLING_APPROVAL_STATUS,
            REJECT_REASON, BUYER,
            CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
        ) VALUES (
            v_id, p_code, p_req_date, p_reason, p_remarks,
            p_org, p_org, p_app_date, p_status,
            p_reject, p_buyer,
            1, SYSTIMESTAMP, p_decided_by, SYSTIMESTAMP
        );

        v_hdr := v_hdr + 1;
        RETURN v_id;
    END new_selling;

    -- ผูกครุภัณฑ์เข้ากับใบ  p_min = ราคาขั้นต่ำ (ตั้งตอนอนุมัติ)
    -- p_real = ราคาขายจริง (ตั้งตอนบันทึกผล) ถ้ายังไม่ถึงขั้นนั้นให้ส่ง NULL
    PROCEDURE add_line(
        p_selling_id IN NUMBER,
        p_asset_id   IN NUMBER,
        p_min        IN NUMBER,
        p_real       IN NUMBER
    ) IS
        v_id NUMBER;
    BEGIN
        SELECT ASSET_SELLING_LIST_SEQ.NEXTVAL INTO v_id FROM DUAL;

        INSERT INTO ASSET_SELLING_LIST (
            ID, SELLING_ID, ASS_ID, SELLING_MIN_PRICE, SELLING_REAL_PRICE,
            CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
        ) VALUES (
            v_id, p_selling_id, p_asset_id, p_min, p_real,
            1, SYSTIMESTAMP, 1, SYSTIMESTAMP
        );

        v_lines := v_lines + 1;
    END add_line;

BEGIN
    SELECT COUNT(*) INTO v_exists FROM ASSET_SELLING WHERE selling_code LIKE 'SL2569%';
    IF v_exists > 0 THEN
        DBMS_OUTPUT.PUT_LINE('ข้าม: มีใบแจ้งขอจำหน่ายชุดเดโม (SL2569*) อยู่แล้ว ' || v_exists || ' ใบ');
        RETURN;
    END IF;

    -- ── รอการอนุมัติ ─────────────────────────────────────────────────────────
    v_sid := new_selling('SL2569001', DATE '2026-09-08', '2',
        'เครื่องชำรุดจากการใช้งานต่อเนื่อง ช่างประเมินแล้วว่าไม่คุ้มค่าซ่อม',
        382, 0, NULL, NULL, NULL, 1);
    add_line(v_sid, 41, NULL, NULL);
    add_line(v_sid, 42, NULL, NULL);

    v_sid := new_selling('SL2569002', DATE '2026-09-22', '3',
        'ตรวจนับประจำปีแล้วไม่พบตัวครุภัณฑ์ อยู่ระหว่างสอบข้อเท็จจริง',
        382, 0, NULL, NULL, NULL, 1);
    add_line(v_sid, 43, NULL, NULL);

    v_sid := new_selling('SL2569003', DATE '2026-10-05', '2',
        'อุปกรณ์เสียหายจากไฟกระชาก ไม่มีอะไหล่ทดแทนแล้ว',
        382, 0, NULL, NULL, NULL, 1);
    add_line(v_sid, 51, NULL, NULL);
    add_line(v_sid, 52, NULL, NULL);

    -- ── อนุมัติแล้ว รอบันทึกผลการจำหน่าย ─────────────────────────────────────
    v_sid := new_selling('SL2569004', DATE '2026-08-11', '2',
        'ครุภัณฑ์ชำรุดใช้งานไม่ได้ ขออนุมัติจำหน่ายออกจากทะเบียน',
        382, 1, DATE '2026-08-25', NULL, NULL, 4);
    add_line(v_sid, 44, 3000, NULL);
    add_line(v_sid, 45, 1500, NULL);

    v_sid := new_selling('SL2569005', DATE '2026-09-01', '2',
        'สภาพทรุดโทรมตามอายุการใช้งาน ซ่อมแล้วไม่คุ้มค่า',
        382, 1, DATE '2026-09-15', NULL, NULL, 4);
    add_line(v_sid, 46, 800, NULL);
    add_line(v_sid, 47, 9000, NULL);

    -- ── บันทึกผลการจำหน่ายแล้ว ───────────────────────────────────────────────
    v_sid := new_selling('SL2569006', DATE '2026-07-06', '2',
        'จำหน่ายโดยวิธีขายทอดตลาด ดำเนินการเรียบร้อยแล้ว',
        382, 1, DATE '2026-07-20', NULL,
        'ร้านรับซื้อของเก่า ทรัพย์เจริญพาณิชย์', 4);
    add_line(v_sid, 48, 4000, 4500);
    add_line(v_sid, 49, 8000, 9200);

    v_sid := new_selling('SL2569007', DATE '2026-06-15', '3',
        'สูญหายระหว่างย้ายสำนักงาน สอบข้อเท็จจริงเสร็จสิ้นแล้ว',
        382, 1, DATE '2026-06-29', NULL, NULL, 4);
    add_line(v_sid, 50, NULL, 0);

    -- ── ไม่อนุมัติ ───────────────────────────────────────────────────────────
    v_sid := new_selling('SL2569008', DATE '2026-10-19', '1',
        'ครบอายุการใช้งานตามเกณฑ์ ขอจำหน่ายออกจากทะเบียน',
        382, 2, DATE '2026-10-27',
        'ตรวจสอบแล้วครุภัณฑ์ยังอยู่ในสภาพใช้งานได้ ให้ซ่อมบำรุงแทนการจำหน่าย',
        NULL, 4);
    add_line(v_sid, 53, NULL, NULL);

    -- ระบบจะตั้งสถานะครุภัณฑ์ให้เองตอนบันทึกผลใน ASS-008
    -- ที่นี่ต้องตั้งตามให้ตรงกัน ไม่งั้นทะเบียนครุภัณฑ์จะขัดกับใบจำหน่าย
    UPDATE ASSET SET ass_status = '5', updated_by = 1, updated_at = SYSTIMESTAMP
     WHERE id IN (48, 49);

    UPDATE ASSET SET ass_status = '6', updated_by = 1, updated_at = SYSTIMESTAMP
     WHERE id = 50;

    COMMIT;
    DBMS_OUTPUT.PUT_LINE('=====================================================');
    DBMS_OUTPUT.PUT_LINE('ใบแจ้งขอจำหน่าย (ASSET_SELLING)       : ' || v_hdr);
    DBMS_OUTPUT.PUT_LINE('รายการครุภัณฑ์ (ASSET_SELLING_LIST)  : ' || v_lines);
    DBMS_OUTPUT.PUT_LINE('ASS-006 เห็นทั้ง 8 ใบ / ASS-007 เห็น 3 ใบที่รออนุมัติ');
    DBMS_OUTPUT.PUT_LINE('ASS-008 เห็น 4 ใบ (รอบันทึกผล 2, บันทึกแล้ว 2)');
    DBMS_OUTPUT.PUT_LINE('=====================================================');

EXCEPTION
    WHEN OTHERS THEN
        ROLLBACK;
        DBMS_OUTPUT.PUT_LINE('ERROR - rolled back: ' || SQLERRM);
        RAISE;
END;
/

EXIT
