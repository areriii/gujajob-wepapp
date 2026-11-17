-- =============================================================================
-- Seed: ประวัติการเบิก/รับโอนวัสดุ 24 เดือน สำหรับ MAT-003 / MAT-004 / MAT-006
-- File   : database/scripts/seed_mat003_withdraw_history.sql
-- Schema : ASSET
--
-- ทำไมต้องมีไฟล์นี้
--   MATERIAL_WITHDRAWN / MATERIAL_INSPECTION ว่างเปล่า (dump ที่กู้มาไม่มีส่วนนี้)
--   ทำให้สามหน้านี้ไม่มีข้อมูลเลย และที่สำคัญคือ "ส่วนที่ใช้ AI" ใช้งานไม่ได้
--
--   1) MAT-006 คอลัมน์ "จำนวนเฉลี่ยในการเบิกต่อเดือน"
--      MaterialStockRecommendationService อ่านจากใบเบิกที่ "อนุมัติแล้ว"
--      และ "รับโอนแล้ว" (ต้องมีแถวใน MATERIAL_INSPECTION_LIST ที่ ISP_AMOUNT > 0)
--      ถ้าไม่มีประวัติ คอลัมน์นี้จะขึ้น "-" ทุกแถว
--
--   2) MAT-003 "แนะนำจากประวัติการเบิก"
--      MaterialWithdrawRecommendationService ใช้กฎความสัมพันธ์
--      confidence(A->B) = ใบที่มีทั้ง A และ B / ใบที่มี A  ต้อง > 50% และเคยคู่กัน >= 2 ครั้ง
--      ถ้าไม่มีประวัติก็ไม่มีอะไรให้แนะนำ
--
-- ทำไมต้อง 24 เดือน
--   ฝั่ง AI (ai-service /forecast/material-stock) จะเทรน Ridge Regression ก็ต่อเมื่อ
--   ประวัติยาวกว่า 12 เดือน (LOOKBACK) และมีเดือนที่เบิกจริงอย่างน้อย 12 เดือน
--   (MIN_NONZERO_TARGETS) แล้วยังกันอีก 6 เดือนท้ายไว้ทดสอบว่าโมเดลชนะค่าเฉลี่ยไหม
--   ประวัติ 24 เดือน (ธ.ค. 2567 - พ.ย. 2569) จึงเป็นขั้นต่ำที่ทำให้ AI ทำงานจริง
--   ไม่ใช่ตกไปใช้ค่าเฉลี่ยเพราะข้อมูลไม่พอ
--
-- ปริมาณการเบิกจงใจให้มีรูปแบบ
--   มีทั้งค่าฐานต่อวัสดุ + แนวโน้มเพิ่มขึ้น + รอบฤดูกาล 12 เดือน
--   ถ้าสุ่มล้วน ๆ โมเดลจะแพ้ค่าเฉลี่ยแล้วระบบจะตกไปใช้ค่าเฉลี่ยแทน AI
--
-- สิ่งที่สร้าง
--   - ใบรับวัสดุเข้าคลัง (MPR-H*) 8 ใบ เข้า org 382 เพื่อให้มีของพอให้เบิก 24 เดือน
--   - ใบเบิก 24 เดือน x 2 ประเภท (ภายในหน่วยงาน + จากหน่วยงานต้นทาง) = 48 ใบ
--   - ใบเบิกค้างสถานะ: รออนุมัติ 2 ใบ, ไม่อนุมัติ 1 ใบ (ไว้เดโมหน้าอนุมัติ)
--   - ใบเบิกอนุมัติแล้วแต่ยังไม่รับโอน 3 ใบ (ไว้เดโม MAT-004 สด)
--   - ใบรับโอน (ISP-*) สำหรับใบเบิกที่เหลือ รวมใบที่รับไม่ครบ 1 ใบ
--
-- คุณสมบัติ
--   - เพิ่มอย่างเดียว ไม่ลบข้อมูลเดิม  รันซ้ำได้ (เจอ MWD-* แล้วจะข้าม)
--   - ใช้ MAX(id)+1 เหมือนที่โค้ดแอปใช้ ไม่ใช่ sequence (แอปไม่ได้ใช้ sequence ชุดนี้)
--
-- หลังรันไฟล์นี้ ต้องสั่ง  php artisan material:rebuild-inventory --apply
-- เพื่อให้ยอดคงเหลือหักการเบิกและบวกการรับโอน
--
-- วิธีรัน (มีภาษาไทยในข้อมูล ต้องตั้ง NLS_LANG ก่อน)
--   set NLS_LANG=AMERICAN_AMERICA.AL32UTF8
--   sqlplus asset/<รหัส>@localhost:1521/orclpdb @database/scripts/seed_mat003_withdraw_history.sql
-- =============================================================================
SET SERVEROUTPUT ON;
SET DEFINE OFF;

DECLARE
    TYPE t_num  IS TABLE OF NUMBER        INDEX BY PLS_INTEGER;
    TYPE t_cnt  IS TABLE OF NUMBER        INDEX BY VARCHAR2(2);
    TYPE t_name IS TABLE OF VARCHAR2(200) INDEX BY PLS_INTEGER;

    c_months   CONSTANT PLS_INTEGER := 24;   -- ธ.ค. 2567 - พ.ย. 2569
    c_store    CONSTANT NUMBER      := 382;  -- ฝ่ายพัสดุ (คลังต้นทาง)
    c_today    CONSTANT DATE        := DATE '2026-11-17';

    v_exists   NUMBER;
    v_price    t_num;    -- ราคาต่อหน่วยของวัสดุ 1-10
    v_child    t_num;    -- หน่วยงานลูกที่มาเบิกจากต้นทาง
    v_person   t_name;   -- ชื่อผู้เบิก
    v_run      t_cnt;    -- เลขรันของใบเบิก แยกตามปีงบประมาณ
    v_run_isp  t_cnt;    -- เลขรันของใบรับโอน แยกตามปีงบประมาณ

    v_wd_id    NUMBER;
    v_wdl_id   NUMBER;
    v_isp_id   NUMBER;
    v_ispl_id  NUMBER;
    v_pro_id   NUMBER;
    v_prol_id  NUMBER;

    v_date     DATE;
    v_wd       NUMBER;
    v_org      NUMBER;

    v_headers  NUMBER := 0;
    v_lines    NUMBER := 0;
    v_insp     NUMBER := 0;
    v_receipts NUMBER := 0;

    -- ปีงบประมาณไทยสองหลักท้าย (ปีงบเริ่ม 1 ต.ค.)
    FUNCTION fy2 (p_date IN DATE) RETURN VARCHAR2 IS
        v_fy NUMBER;
    BEGIN
        v_fy := TO_NUMBER(TO_CHAR(p_date, 'YYYY')) + 543
              + CASE WHEN TO_NUMBER(TO_CHAR(p_date, 'MM')) >= 10 THEN 1 ELSE 0 END;
        RETURN SUBSTR(TO_CHAR(v_fy), 3, 2);
    END fy2;

    FUNCTION next_wd_code (p_date IN DATE) RETURN VARCHAR2 IS
        v_yy VARCHAR2(2) := fy2(p_date);
    BEGIN
        IF NOT v_run.EXISTS(v_yy) THEN v_run(v_yy) := 0; END IF;
        v_run(v_yy) := v_run(v_yy) + 1;
        RETURN 'MWD-' || v_yy || LPAD(TO_CHAR(v_run(v_yy)), 5, '0');
    END next_wd_code;

    FUNCTION next_isp_code (p_date IN DATE) RETURN VARCHAR2 IS
        v_yy VARCHAR2(2) := fy2(p_date);
    BEGIN
        IF NOT v_run_isp.EXISTS(v_yy) THEN v_run_isp(v_yy) := 0; END IF;
        v_run_isp(v_yy) := v_run_isp(v_yy) + 1;
        RETURN 'ISP-' || v_yy || LPAD(TO_CHAR(v_run_isp(v_yy)), 5, '0');
    END next_isp_code;

    -- ปริมาณเบิกตั้งต้นของวัสดุ m ในเดือนลำดับ i (0 = เดือนแรกสุด)
    -- ค่าฐานต่อวัสดุ + แนวโน้มขาขึ้น + รอบ 12 เดือน + ค่ากวนเล็กน้อยแบบคงที่
    FUNCTION demand (p_mat IN PLS_INTEGER, p_i IN PLS_INTEGER) RETURN NUMBER IS
        v_base NUMBER := 5 + MOD(p_mat, 5) * 2;
    BEGIN
        RETURN GREATEST(2, ROUND(
            v_base
            + 0.3 * p_i
            + 4 * SIN(2 * 3.14159265 * ((p_i + p_mat) / 12))
            + MOD(p_i * 7 + p_mat * 3, 3)
        ));
    END demand;

    -- หัวใบเบิก คืน id ไว้ผูกรายการ
    FUNCTION new_withdraw (
        p_date IN DATE, p_org IN NUMBER, p_type IN VARCHAR2,
        p_status IN NUMBER, p_person IN VARCHAR2
    ) RETURN NUMBER IS
        -- ฟังก์ชันที่ประกาศในบล็อกนี้เรียกใช้ใน SQL ไม่ได้ ต้องรับค่าใส่ตัวแปรก่อน
        v_code VARCHAR2(36) := next_wd_code(p_date);
    BEGIN
        v_wd_id := v_wd_id + 1;

        INSERT INTO MATERIAL_WITHDRAWN (
            ID, MAT_WD_CODE, MAT_WD_DATE, ORG_ID, MAT_WD_PERSON,
            STATUS, WITHDRAW_TYPE, MAT_WD_TYPE,
            CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
        ) VALUES (
            v_wd_id, v_code, p_date, p_org, p_person,
            p_status, p_type, 1,
            1, SYSTIMESTAMP, 1, SYSTIMESTAMP
        );

        v_headers := v_headers + 1;
        RETURN v_wd_id;
    END new_withdraw;

    PROCEDURE add_item (p_wd IN NUMBER, p_mat IN NUMBER, p_amount IN NUMBER) IS
    BEGIN
        IF p_amount <= 0 THEN RETURN; END IF;

        v_wdl_id := v_wdl_id + 1;

        INSERT INTO MATERIAL_WITHDRAWN_LIST (
            ID, MAT_WD_ID, MAT_ID, WD_AMOUNT,
            CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
        ) VALUES (
            v_wdl_id, p_wd, p_mat, p_amount,
            1, SYSTIMESTAMP, 1, SYSTIMESTAMP
        );

        v_lines := v_lines + 1;
    END add_item;

    -- รับโอนตามใบเบิก  p_shortfall = จำนวนที่รับขาดของรายการแรก (ไว้โชว์เคสรับไม่ครบ)
    PROCEDURE receive (p_wd IN NUMBER, p_date IN DATE, p_person IN VARCHAR2,
                       p_shortfall IN NUMBER DEFAULT 0) IS
        v_first BOOLEAN := TRUE;
        v_amt   NUMBER;
        v_code  VARCHAR2(36) := next_isp_code(p_date);
    BEGIN
        v_isp_id := v_isp_id + 1;

        INSERT INTO MATERIAL_INSPECTION (
            ID, MAT_INSP_CODE, MAT_WD_ID, MAT_INSP_DATE, MAT_INSP_PERSON, MAT_INSP_REMARK,
            CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
        ) VALUES (
            v_isp_id, v_code, p_wd, p_date, p_person,
            CASE WHEN p_shortfall > 0 THEN 'รับไม่ครบตามจำนวนที่ขอเบิก ของขาดส่ง' ELSE NULL END,
            1, SYSTIMESTAMP, 1, SYSTIMESTAMP
        );

        FOR r IN (SELECT mat_id, wd_amount FROM MATERIAL_WITHDRAWN_LIST WHERE mat_wd_id = p_wd ORDER BY id) LOOP
            v_amt := r.wd_amount;
            IF v_first AND p_shortfall > 0 THEN
                v_amt := GREATEST(1, r.wd_amount - p_shortfall);
            END IF;
            v_first := FALSE;

            v_ispl_id := v_ispl_id + 1;

            INSERT INTO MATERIAL_INSPECTION_LIST (
                ID, MAT_ISP_ID, MAT_ID, ISP_AMOUNT,
                CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
            ) VALUES (
                v_ispl_id, v_isp_id, r.mat_id, v_amt,
                1, SYSTIMESTAMP, 1, SYSTIMESTAMP
            );
        END LOOP;

        v_insp := v_insp + 1;
    END receive;

    -- ใบรับวัสดุเข้าคลังของ org 382 เพื่อให้มีของพอเบิกตลอด 24 เดือน
    PROCEDURE stock_up (p_seq IN PLS_INTEGER, p_date IN DATE) IS
    BEGIN
        v_pro_id := v_pro_id + 1;

        INSERT INTO MATERIAL_PROCUREMENT (
            ID, MAT_PRO_CODE, DEALER_ID, ORG_ID,
            MAT_PRO_DATE, MAT_PRO_METHOD, VAT_TYPE, VAT_RATE,
            CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
        ) VALUES (
            v_pro_id, 'MPR-H' || LPAD(TO_CHAR(p_seq), 4, '0'),
            MOD(p_seq, 5) + 1, c_store,
            p_date, 1, 1, 7,
            1, SYSTIMESTAMP, 1, SYSTIMESTAMP
        );

        FOR m IN 1 .. 10 LOOP
            v_prol_id := v_prol_id + 1;

            INSERT INTO MATERIAL_PROCUREMENT_LIST (
                ID, MAT_PRO_ID, MAT_ID, MAT_AMT, MAT_PRICE,
                CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
            ) VALUES (
                v_prol_id, v_pro_id, m,
                CASE WHEN m <= 5 THEN 60 ELSE 30 END,
                v_price(m),
                1, SYSTIMESTAMP, 1, SYSTIMESTAMP
            );
        END LOOP;

        v_receipts := v_receipts + 1;
    END stock_up;

BEGIN
    SELECT COUNT(*) INTO v_exists FROM MATERIAL_WITHDRAWN WHERE mat_wd_code LIKE 'MWD-%';
    IF v_exists > 0 THEN
        DBMS_OUTPUT.PUT_LINE('ข้าม: มีใบเบิกชุดนี้อยู่แล้ว ' || v_exists || ' ใบ');
        RETURN;
    END IF;

    SELECT NVL(MAX(id), 0) INTO v_wd_id   FROM MATERIAL_WITHDRAWN;
    SELECT NVL(MAX(id), 0) INTO v_wdl_id  FROM MATERIAL_WITHDRAWN_LIST;
    SELECT NVL(MAX(id), 0) INTO v_isp_id  FROM MATERIAL_INSPECTION;
    SELECT NVL(MAX(id), 0) INTO v_ispl_id FROM MATERIAL_INSPECTION_LIST;
    SELECT NVL(MAX(id), 0) INTO v_pro_id  FROM MATERIAL_PROCUREMENT;
    SELECT NVL(MAX(id), 0) INTO v_prol_id FROM MATERIAL_PROCUREMENT_LIST;

    -- ราคาต่อหน่วย ตั้งให้ใกล้เคียงราคาเฉลี่ยเดิมของวัสดุแต่ละตัว
    -- เพื่อไม่ให้คอลัมน์ "ราคาเฉลี่ย/หน่วย" ใน MAT-006 กระโดด
    v_price(1) := 48.20;  v_price(2) := 30.00;  v_price(3) := 77.31;
    v_price(4) := 162.18; v_price(5) := 15.00;  v_price(6) := 30.00;
    v_price(7) := 115.00; v_price(8) := 120.00; v_price(9) := 50.00;
    v_price(10) := 52.20;

    v_child(0) := 394;  -- งานจัดหา
    v_child(1) := 395;  -- งานควบคุมพัสดุ
    v_child(2) := 396;  -- งานยานพาหนะและอาคารสถานที่

    v_person(0) := 'นางสาวกัลยา ศรีสมบูรณ์';
    v_person(1) := 'นายวิรัตน์ ทองใบ';
    v_person(2) := 'นางสุภาพร ใจดี';
    v_person(3) := 'นายธีรยุทธ แก้วประเสริฐ';

    -- ── ใบรับวัสดุเข้าคลัง ทุก 3 เดือน ────────────────────────────────────────
    FOR q IN 0 .. 7 LOOP
        stock_up(q + 1, ADD_MONTHS(TRUNC(c_today, 'MM'), -(c_months - 1) + q * 3) + 1);
    END LOOP;

    -- ── ใบเบิก 24 เดือน ──────────────────────────────────────────────────────
    FOR i IN 0 .. c_months - 1 LOOP
        v_date := ADD_MONTHS(TRUNC(c_today, 'MM'), -(c_months - 1 - i)) + 4 + MOD(i * 7, 12);

        -- (1) เบิกใช้ภายในฝ่ายพัสดุเอง  ระบบตั้งเป็นอนุมัติแล้วตั้งแต่บันทึก
        v_wd := new_withdraw(v_date, c_store, 'INTERNAL_USE',
                             2, v_person(MOD(i, 4)));

        -- วัสดุหลักเบิกทุกเดือน ส่วนที่เหลือเบิกถี่ไม่เท่ากันโดยตั้งใจ
        -- เพราะ MAT-003 แนะนำเฉพาะคู่ที่ confidence > 50% เท่านั้น
        -- ถ้าตั้งให้เบิกเดือนเว้นเดือนจะได้ 12/24 = 50% พอดี ซึ่ง "ไม่เกิน" เกณฑ์ จะไม่ถูกแนะนำเลย
        --   วัสดุ 3, 6, 7  -> 16 จาก 24 เดือน = 67%
        --   วัสดุ 8, 9, 10 -> 18 จาก 24 เดือน = 75%
        -- ได้คำแนะนำหลายรายการและค่าความเชื่อมั่นต่างกัน ไม่ใช่ 100% ทุกตัว
        FOR m IN 1 .. 10 LOOP
            IF m IN (1, 2, 4, 5)
               OR (m IN (3, 6, 7)  AND MOD(i + m, 3) <> 0)
               OR (m IN (8, 9, 10) AND MOD(i + m, 4) <> 0) THEN
                add_item(v_wd, m, GREATEST(1, ROUND(demand(m, i) * 0.6)));
            END IF;
        END LOOP;

        -- รับโอนทุกใบ ยกเว้นเดือนสุดท้ายที่กันไว้ให้เดโม MAT-004
        IF i < c_months - 1 THEN
            receive(v_wd, v_date + 2, v_person(MOD(i + 1, 4)),
                    CASE WHEN i = 14 THEN 3 ELSE 0 END);
        END IF;

        -- (2) หน่วยงานลูกเบิกจากคลังฝ่ายพัสดุ หมุนเวียนกันไป
        v_org := v_child(MOD(i, 3));
        v_wd  := new_withdraw(v_date + 1, v_org, 'LARGE_LOT',
                              2, v_person(MOD(i + 2, 4)));

        FOR m IN 1 .. 5 LOOP
            IF m IN (1, 2, 4) OR MOD(i, 2) = 0 THEN
                add_item(v_wd, m, GREATEST(1, ROUND(demand(m, i) * 0.4)));
            END IF;
        END LOOP;

        IF i < c_months - 1 THEN
            receive(v_wd, v_date + 3, v_person(MOD(i, 4)));
        END IF;
    END LOOP;

    -- ── ใบเบิกค้างสถานะ ไว้เดโมหน้าอนุมัติการเบิกวัสดุ ───────────────────────
    v_wd := new_withdraw(c_today - 5, 394, 'LARGE_LOT', 1, v_person(0));
    add_item(v_wd, 1, 25);
    add_item(v_wd, 4, 40);
    add_item(v_wd, 7, 12);

    v_wd := new_withdraw(c_today - 3, 395, 'LARGE_LOT', 1, v_person(1));
    add_item(v_wd, 2, 18);
    add_item(v_wd, 5, 30);

    v_wd := new_withdraw(c_today - 12, 396, 'LARGE_LOT', 3, v_person(2));
    add_item(v_wd, 3, 15);
    add_item(v_wd, 9, 20);

    -- ── อนุมัติแล้วแต่ยังไม่รับโอน ไว้เดโม MAT-004 สด ────────────────────────
    v_wd := new_withdraw(c_today - 8, 396, 'LARGE_LOT', 2, v_person(3));
    add_item(v_wd, 1, 20);
    add_item(v_wd, 2, 15);
    add_item(v_wd, 4, 25);

    v_wd := new_withdraw(c_today - 6, c_store, 'INTERNAL_USE', 2, v_person(1));
    add_item(v_wd, 5, 14);
    add_item(v_wd, 8, 10);

    COMMIT;
    DBMS_OUTPUT.PUT_LINE('=====================================================');
    DBMS_OUTPUT.PUT_LINE('ใบรับวัสดุเข้าคลัง (MPR-H*)        : ' || v_receipts);
    DBMS_OUTPUT.PUT_LINE('ใบเบิก (MATERIAL_WITHDRAWN)        : ' || v_headers);
    DBMS_OUTPUT.PUT_LINE('รายการเบิก (MATERIAL_WITHDRAWN_LIST): ' || v_lines);
    DBMS_OUTPUT.PUT_LINE('ใบรับโอน (MATERIAL_INSPECTION)     : ' || v_insp);
    DBMS_OUTPUT.PUT_LINE('ประวัติ 24 เดือน: ธ.ค. 2567 - พ.ย. 2569');
    DBMS_OUTPUT.PUT_LINE('ขั้นต่อไป: php artisan material:rebuild-inventory --apply');
    DBMS_OUTPUT.PUT_LINE('=====================================================');

EXCEPTION
    WHEN OTHERS THEN
        ROLLBACK;
        DBMS_OUTPUT.PUT_LINE('ERROR - rolled back: ' || SQLERRM);
        RAISE;
END;
/

EXIT
