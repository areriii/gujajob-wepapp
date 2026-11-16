-- =============================================================================
-- Seed เพิ่มเติม: ใบรับวัสดุสำหรับสายงานของบัญชีที่ใช้เดโม
-- File   : database/scripts/seed_mat002_demo_branch.sql
-- Schema : ASSET
--
-- ทำไมต้องมีไฟล์นี้
--   insert_mat002_sample_100.sql ใส่ใบรับไว้ที่ org 0, 64-70 ซึ่งไม่อยู่ในสาย
--   บังคับบัญชาของ user01 (ฝ่ายพัสดุ org 382) เลย
--   MAT-007 ใช้ branchIds() = ตัวเอง + ลูกหลานตามสายงาน รายงานจึงออกมาว่าง
--   ไฟล์นี้เติมใบรับให้ org 382 และลูก (394, 395, 396) เพื่อให้เดโมมีข้อมูลจริง
--
-- คุณสมบัติ
--   - เพิ่มอย่างเดียว ไม่ลบ ไม่แก้ไขข้อมูลเดิม
--   - รันซ้ำได้ ถ้ามีรหัส MPR-D* อยู่แล้วจะข้ามทั้งชุด
--   - ใช้ dealer 1-5 และ mat_id 1-101 ที่มีอยู่แล้ว
--
-- หลังรันไฟล์นี้ ต้องสั่ง  php artisan material:rebuild-inventory --apply
-- เพื่อให้ MATERIAL_INVENTORY อัปเดตตาม
-- =============================================================================
SET SERVEROUTPUT ON;
SET DEFINE OFF;

DECLARE
    TYPE t_num IS TABLE OF NUMBER INDEX BY PLS_INTEGER;

    v_orgs    t_num;
    v_h_id    NUMBER;
    v_d_id    NUMBER;
    v_code    VARCHAR2(20);
    v_date    DATE;
    v_org     NUMBER;
    v_dealer  NUMBER;
    v_mat_id  NUMBER;
    v_amt     NUMBER;
    v_price   NUMBER;
    v_items   NUMBER;
    v_exists  NUMBER;
    v_headers NUMBER := 0;
    v_lines   NUMBER := 0;
BEGIN
    SELECT COUNT(*) INTO v_exists FROM MATERIAL_PROCUREMENT WHERE mat_pro_code LIKE 'MPR-D%';
    IF v_exists > 0 THEN
        DBMS_OUTPUT.PUT_LINE('ข้าม: มีใบรับชุดเดโม (MPR-D*) อยู่แล้ว ' || v_exists || ' ใบ');
        RETURN;
    END IF;

    SELECT NVL(MAX(id), 0) + 1 INTO v_h_id FROM MATERIAL_PROCUREMENT;
    SELECT NVL(MAX(id), 0) + 1 INTO v_d_id FROM MATERIAL_PROCUREMENT_LIST;

    -- สายงานของ user01: ฝ่ายพัสดุ และหน่วยงานลูก
    v_orgs(1) := 382;   -- ฝ่ายพัสดุ
    v_orgs(2) := 394;   -- งานจัดหา
    v_orgs(3) := 395;   -- งานควบคุมพัสดุ
    v_orgs(4) := 396;   -- งานยานพาหนะและอาคารสถานที่

    FOR i IN 1 .. 24 LOOP
        v_code   := 'MPR-D' || LPAD(TO_CHAR(i), 4, '0');
        v_date   := DATE '2026-02-02' + TRUNC((i - 1) * 11);
        v_dealer := MOD(i * 3 + 2, 5) + 1;
        v_org    := v_orgs(MOD(i - 1, 4) + 1);

        INSERT INTO MATERIAL_PROCUREMENT (
            ID, MAT_PRO_CODE, DEALER_ID, ORG_ID,
            MAT_PRO_DATE, MAT_PRO_METHOD, VAT_TYPE, VAT_RATE,
            CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
        ) VALUES (
            v_h_id, v_code, v_dealer, v_org,
            v_date,
            CASE MOD(i, 4) WHEN 0 THEN 2 WHEN 1 THEN 1 WHEN 2 THEN 1 ELSE 3 END,
            CASE WHEN MOD(i, 5) = 0 THEN 2 ELSE 1 END,
            CASE WHEN MOD(i, 5) = 0 THEN NULL ELSE 7 END,
            1, SYSTIMESTAMP, 1, SYSTIMESTAMP
        );
        v_headers := v_headers + 1;

        -- 3-5 รายการต่อใบ เพื่อให้รายงานมีความยาวพอเห็นการแบ่งหน้า (หน้าละ 15)
        v_items := MOD(i, 3) + 3;

        FOR j IN 1 .. v_items LOOP
            v_mat_id := MOD((i * 13 + j * 7) - 1, 101) + 1;

            v_amt := CASE MOD(i + j * 3, 8)
                WHEN 0 THEN 40  WHEN 1 THEN 25  WHEN 2 THEN 60  WHEN 3 THEN 15
                WHEN 4 THEN 80  WHEN 5 THEN 100 WHEN 6 THEN 35  ELSE 50
            END;

            v_price := CASE MOD(i * j + j, 9)
                WHEN 0 THEN 15  WHEN 1 THEN 25  WHEN 2 THEN 45  WHEN 3 THEN 60
                WHEN 4 THEN 85  WHEN 5 THEN 120 WHEN 6 THEN 150 WHEN 7 THEN 200
                ELSE 350
            END;

            INSERT INTO MATERIAL_PROCUREMENT_LIST (
                ID, MAT_PRO_ID, MAT_ID, MAT_AMT, MAT_PRICE,
                CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
            ) VALUES (
                v_d_id, v_h_id, v_mat_id, v_amt, v_price,
                1, SYSTIMESTAMP, 1, SYSTIMESTAMP
            );

            v_d_id := v_d_id + 1;
            v_lines := v_lines + 1;
        END LOOP;

        v_h_id := v_h_id + 1;
    END LOOP;

    COMMIT;
    DBMS_OUTPUT.PUT_LINE('=====================================================');
    DBMS_OUTPUT.PUT_LINE('เพิ่มใบรับ (MATERIAL_PROCUREMENT)      : ' || v_headers);
    DBMS_OUTPUT.PUT_LINE('เพิ่มรายการ (MATERIAL_PROCUREMENT_LIST): ' || v_lines);
    DBMS_OUTPUT.PUT_LINE('หน่วยงาน: 382 ฝ่ายพัสดุ, 394 งานจัดหา, 395 งานควบคุมพัสดุ, 396 งานยานพาหนะฯ');
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
