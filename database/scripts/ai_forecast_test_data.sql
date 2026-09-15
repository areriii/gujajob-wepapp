-- =============================================================================
-- FILE : ai_forecast_test_data.sql
-- DESC : DEVELOPMENT TEST DATA ONLY — ข้อมูลทดสอบ AI พยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน (ASS-003)
--        เพิ่มครุภัณฑ์ทดสอบ 40 รายการในตาราง ASSET (ไม่แก้ไข/ลบข้อมูลเดิม ไม่เปลี่ยน schema)
-- DB   : Oracle 19c (schema ASSET)
-- =============================================================================
-- ระบุตัวตนข้อมูลทดสอบ:
--   ASS_CODE  = AI-TEST-001 .. AI-TEST-040
--   ASS_DESC  ขึ้นต้นด้วย '[AI FORECAST TEST DATA]'
--   ASS_MODEL = 'AI-TEST'
-- ลบภายหลังด้วย: database/scripts/ai_forecast_test_data_cleanup.sql
--
-- ข้อมูลอ้างอิงจริงที่ใช้ (มีอยู่แล้วใน Oracle):
--   ASSET_CATEGORY 545 7440-001-0004 เครื่องคอมพิวเตอร์ ชนิดกระเป๋าหิ้ว (Notebook) หมวด ครุภัณฑ์คอมพิวเตอร์ อัตราค่าเสื่อม 20%   -> อายุ 5 ปี
--   ASSET_CATEGORY 18  4120-004-0002 เครื่องปรับอากาศแยกส่วน 12,000-16,000 BTU      หมวด ครุภัณฑ์สำนักงาน     อัตราค่าเสื่อม 20%   -> อายุ 5 ปี
--   ASSET_CATEGORY 284 2310-002-0001 รถนั่งตรวจการ                                 หมวด ครุภัณฑ์ยานพาหนะและขนส่ง อัตราค่าเสื่อม 12.5% -> อายุ 8 ปี
--   GLB_ORGANIZATION 1313 กองวิชาการ, 1314 กองฝึกอบรม (zone C)
--
-- กฎที่ใช้ (ตาม source code ปัจจุบัน):
--   ASS_LIFETIME  = 100 / ASSET_CATEGORY.DEPRECIATION_RATE (ค่าเสื่อมราคาแบบเส้นตรง)
--   ปีครบกำหนดทดแทน = EXTRACT(YEAR FROM ADD_MONTHS(INSPECT_DATE, ASS_LIFETIME * 12))
--   REMAIN_PRICE  = ASS_PRICE (เหมือน AssetController::store())
--   ID            = ASSET_SEQ.NEXTVAL (เหมือน AssetController::store())
--
-- ขั้นตอน:
--   1. SAVEPOINT
--   2. ตรวจสอบว่ายังไม่มีข้อมูลทดสอบ และข้อมูลอ้างอิงมีอยู่จริง
--   3. INSERT 40 รายการ
--   4. Verification queries
--   5. ตรวจสอบผลแล้วจึง COMMIT (หรือ ROLLBACK TO before_ai_forecast_test_insert)
-- =============================================================================

SAVEPOINT before_ai_forecast_test_insert;

-- =============================================================================
-- Phase 1: Idempotency + reference checks
-- =============================================================================
DECLARE
    v_cnt NUMBER;
BEGIN
    SELECT COUNT(*) INTO v_cnt FROM ASSET WHERE ass_code LIKE 'AI-TEST-%';
    IF v_cnt > 0 THEN
        RAISE_APPLICATION_ERROR(-20001, 'AI forecast test data already exists. Run ai_forecast_test_data_cleanup.sql first.');
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM ASSET_CATEGORY WHERE id IN (545, 18, 284);
    IF v_cnt <> 3 THEN
        RAISE_APPLICATION_ERROR(-20002, 'Reference ASSET_CATEGORY rows 545, 18, 284 not found.');
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM GLB_ORGANIZATION WHERE org_id IN (1313, 1314);
    IF v_cnt <> 2 THEN
        RAISE_APPLICATION_ERROR(-20003, 'Reference GLB_ORGANIZATION rows 1313, 1314 not found.');
    END IF;
END;
/

-- =============================================================================
-- Phase 2: INSERT test assets
-- =============================================================================
-- AI-TEST-001: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2016-03-15 | อายุ 5 ปี | ครบกำหนด 2021 (พ.ศ. 2564)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-001', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2559', 'AI-TEST', 20100.00, 1313, DATE '2016-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 20100.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-002: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2017-03-15 | อายุ 5 ปี | ครบกำหนด 2022 (พ.ศ. 2565)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-002', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2560', 'AI-TEST', 20900.00, 1313, DATE '2017-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 20900.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-003: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2018-03-15 | อายุ 5 ปี | ครบกำหนด 2023 (พ.ศ. 2566)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-003', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2561', 'AI-TEST', 21700.00, 1313, DATE '2018-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 21700.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-004: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2019-03-15 | อายุ 5 ปี | ครบกำหนด 2024 (พ.ศ. 2567)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-004', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2562', 'AI-TEST', 22600.00, 1313, DATE '2019-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 22600.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-005: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2020-03-15 | อายุ 5 ปี | ครบกำหนด 2025 (พ.ศ. 2568)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-005', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2563', 'AI-TEST', 23500.00, 1313, DATE '2020-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 23500.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-006: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2021-03-15 | อายุ 5 ปี | ครบกำหนด 2026 (พ.ศ. 2569)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-006', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2564', 'AI-TEST', 24500.00, 1313, DATE '2021-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 24500.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-007: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2022-03-15 | อายุ 5 ปี | ครบกำหนด 2027 (พ.ศ. 2570)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-007', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2565', 'AI-TEST', 25400.00, 1313, DATE '2022-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 25400.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-008: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2023-03-15 | อายุ 5 ปี | ครบกำหนด 2028 (พ.ศ. 2571)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-008', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2566', 'AI-TEST', 26500.00, 1313, DATE '2023-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 26500.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-009: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2024-03-15 | อายุ 5 ปี | ครบกำหนด 2029 (พ.ศ. 2572)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-009', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2567', 'AI-TEST', 27500.00, 1313, DATE '2024-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 27500.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-010: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2025-03-15 | อายุ 5 ปี | ครบกำหนด 2030 (พ.ศ. 2573)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-010', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2568', 'AI-TEST', 28600.00, 1313, DATE '2025-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 28600.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-011: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1313 | ตรวจรับ 2026-03-15 | อายุ 5 ปี | ครบกำหนด 2031 (พ.ศ. 2574)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-011', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2569', 'AI-TEST', 29800.00, 1313, DATE '2026-03-15', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 29800.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-012: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1314 | ตรวจรับ 2022-08-20 | อายุ 5 ปี | ครบกำหนด 2027 (พ.ศ. 2570)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-012', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2565', 'AI-TEST', 25900.00, 1314, DATE '2022-08-20', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 25900.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-013: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1314 | ตรวจรับ 2023-08-20 | อายุ 5 ปี | ครบกำหนด 2028 (พ.ศ. 2571)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-013', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2566', 'AI-TEST', 27000.00, 1314, DATE '2023-08-20', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 27000.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-014: หมวด ครุภัณฑ์คอมพิวเตอร์ | org 1314 | ตรวจรับ 2024-08-20 | อายุ 5 ปี | ครบกำหนด 2029 (พ.ศ. 2572)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 545, 'AI-TEST-014', '[AI FORECAST TEST DATA] เครื่องคอมพิวเตอร์โน้ตบุ๊ก (ทดสอบ AI) ปีตรวจรับ 2567', 'AI-TEST', 28100.00, 1314, DATE '2024-08-20', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 28100.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-015: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2016-05-10 | อายุ 5 ปี | ครบกำหนด 2021 (พ.ศ. 2564)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-015', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2559', 'AI-TEST', 15900.00, 1313, DATE '2016-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 15900.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-016: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2017-05-10 | อายุ 5 ปี | ครบกำหนด 2022 (พ.ศ. 2565)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-016', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2560', 'AI-TEST', 16500.00, 1313, DATE '2017-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 16500.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-017: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2018-05-10 | อายุ 5 ปี | ครบกำหนด 2023 (พ.ศ. 2566)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-017', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2561', 'AI-TEST', 17000.00, 1313, DATE '2018-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 17000.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-018: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2019-05-10 | อายุ 5 ปี | ครบกำหนด 2024 (พ.ศ. 2567)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-018', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2562', 'AI-TEST', 17600.00, 1313, DATE '2019-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 17600.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-019: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2020-05-10 | อายุ 5 ปี | ครบกำหนด 2025 (พ.ศ. 2568)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-019', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2563', 'AI-TEST', 18200.00, 1313, DATE '2020-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 18200.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-020: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2021-05-10 | อายุ 5 ปี | ครบกำหนด 2026 (พ.ศ. 2569)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-020', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2564', 'AI-TEST', 18900.00, 1313, DATE '2021-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 18900.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-021: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2022-05-10 | อายุ 5 ปี | ครบกำหนด 2027 (พ.ศ. 2570)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-021', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2565', 'AI-TEST', 19500.00, 1313, DATE '2022-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 19500.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-022: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2023-05-10 | อายุ 5 ปี | ครบกำหนด 2028 (พ.ศ. 2571)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-022', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2566', 'AI-TEST', 20200.00, 1313, DATE '2023-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 20200.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-023: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2024-05-10 | อายุ 5 ปี | ครบกำหนด 2029 (พ.ศ. 2572)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-023', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2567', 'AI-TEST', 20900.00, 1313, DATE '2024-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 20900.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-024: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2025-05-10 | อายุ 5 ปี | ครบกำหนด 2030 (พ.ศ. 2573)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-024', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2568', 'AI-TEST', 21700.00, 1313, DATE '2025-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 21700.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-025: หมวด ครุภัณฑ์สำนักงาน | org 1313 | ตรวจรับ 2026-05-10 | อายุ 5 ปี | ครบกำหนด 2031 (พ.ศ. 2574)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-025', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2569', 'AI-TEST', 22400.00, 1313, DATE '2026-05-10', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 22400.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-026: หมวด ครุภัณฑ์สำนักงาน | org 1314 | ตรวจรับ 2022-11-05 | อายุ 5 ปี | ครบกำหนด 2027 (พ.ศ. 2570)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-026', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2565', 'AI-TEST', 19800.00, 1314, DATE '2022-11-05', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 19800.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-027: หมวด ครุภัณฑ์สำนักงาน | org 1314 | ตรวจรับ 2024-11-05 | อายุ 5 ปี | ครบกำหนด 2029 (พ.ศ. 2572)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 18, 'AI-TEST-027', '[AI FORECAST TEST DATA] เครื่องปรับอากาศแยกส่วน 12000-16000 BTU (ทดสอบ AI) ปีตรวจรับ 2567', 'AI-TEST', 21200.00, 1314, DATE '2024-11-05', 5,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 21200.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-028: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2016-07-01 | อายุ 8 ปี | ครบกำหนด 2024 (พ.ศ. 2567)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-028', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2559', 'AI-TEST', 676000.00, 1314, DATE '2016-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 676000.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-029: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2017-07-01 | อายุ 8 ปี | ครบกำหนด 2025 (พ.ศ. 2568)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-029', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2560', 'AI-TEST', 696300.00, 1314, DATE '2017-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 696300.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-030: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2018-07-01 | อายุ 8 ปี | ครบกำหนด 2026 (พ.ศ. 2569)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-030', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2561', 'AI-TEST', 717200.00, 1314, DATE '2018-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 717200.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-031: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2019-07-01 | อายุ 8 ปี | ครบกำหนด 2027 (พ.ศ. 2570)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-031', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2562', 'AI-TEST', 738700.00, 1314, DATE '2019-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 738700.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-032: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2020-07-01 | อายุ 8 ปี | ครบกำหนด 2028 (พ.ศ. 2571)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-032', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2563', 'AI-TEST', 760800.00, 1314, DATE '2020-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 760800.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-033: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2021-07-01 | อายุ 8 ปี | ครบกำหนด 2029 (พ.ศ. 2572)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-033', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2564', 'AI-TEST', 783700.00, 1314, DATE '2021-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 783700.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-034: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2022-07-01 | อายุ 8 ปี | ครบกำหนด 2030 (พ.ศ. 2573)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-034', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2565', 'AI-TEST', 807200.00, 1314, DATE '2022-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 807200.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-035: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2023-07-01 | อายุ 8 ปี | ครบกำหนด 2031 (พ.ศ. 2574)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-035', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2566', 'AI-TEST', 831400.00, 1314, DATE '2023-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 831400.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-036: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2024-07-01 | อายุ 8 ปี | ครบกำหนด 2032 (พ.ศ. 2575)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-036', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2567', 'AI-TEST', 856300.00, 1314, DATE '2024-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 856300.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-037: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2025-07-01 | อายุ 8 ปี | ครบกำหนด 2033 (พ.ศ. 2576)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-037', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2568', 'AI-TEST', 882000.00, 1314, DATE '2025-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 882000.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-038: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1314 | ตรวจรับ 2026-07-01 | อายุ 8 ปี | ครบกำหนด 2034 (พ.ศ. 2577)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-038', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2569', 'AI-TEST', 908500.00, 1314, DATE '2026-07-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 908500.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-039: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1313 | ตรวจรับ 2020-09-01 | อายุ 8 ปี | ครบกำหนด 2028 (พ.ศ. 2571)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-039', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2563', 'AI-TEST', 760000.00, 1313, DATE '2020-09-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 760000.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- AI-TEST-040: หมวด ครุภัณฑ์ยานพาหนะและขนส่ง | org 1313 | ตรวจรับ 2021-09-01 | อายุ 8 ปี | ครบกำหนด 2029 (พ.ศ. 2572)
INSERT INTO ASSET (ID, ASSCAT_ID, ASS_CODE, ASS_DESC, ASS_MODEL, ASS_PRICE, ORG_ID, INSPECT_DATE, ASS_LIFETIME,
                   REMARKS, REMAIN_PRICE, ASS_STATUS, CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT)
VALUES (ASSET_SEQ.NEXTVAL, 284, 'AI-TEST-040', '[AI FORECAST TEST DATA] รถยนต์นั่งตรวจการ (ทดสอบ AI) ปีตรวจรับ 2564', 'AI-TEST', 781000.00, 1313, DATE '2021-09-01', 8,
        'DEVELOPMENT TEST DATA สำหรับทดสอบ AI พยากรณ์งบประมาณ ASS-003 - ลบด้วย database/scripts/ai_forecast_test_data_cleanup.sql', 781000.00, '2', 1, SYSTIMESTAMP, 1, SYSTIMESTAMP);

-- =============================================================================
-- Phase 3: Verification (expected: 40 rows, all with category, organisation, date, lifetime, price)
-- =============================================================================
SELECT COUNT(*) AS inserted_test_rows
FROM ASSET
WHERE ass_code LIKE 'AI-TEST-%' AND ass_model = 'AI-TEST';

SELECT a.ass_code, c.asscat_name, TRIM(c.asscat_group) AS asscat_group, o.org_name,
       TO_CHAR(a.inspect_date, 'YYYY-MM-DD') AS inspect_date, a.ass_lifetime, a.ass_price, a.remain_price,
       EXTRACT(YEAR FROM ADD_MONTHS(a.inspect_date, a.ass_lifetime * 12)) AS replacement_year
FROM ASSET a
JOIN ASSET_CATEGORY c ON c.id = a.asscat_id
JOIN GLB_ORGANIZATION o ON o.org_id = a.org_id
WHERE a.ass_code LIKE 'AI-TEST-%' AND a.ass_model = 'AI-TEST'
ORDER BY a.ass_code;

-- ถ้าผลถูกต้องจึงรัน COMMIT
-- COMMIT;
-- ถ้าไม่ถูกต้อง:
-- ROLLBACK TO before_ai_forecast_test_insert;
