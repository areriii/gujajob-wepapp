-- =============================================================================
-- FILE : database/test-data/asset_allocation_demo_data_cleanup.sql
-- DESC : ลบเฉพาะข้อมูลทดสอบที่สร้างโดย database/test-data/asset_allocation_demo_data.sql
-- DB   : Oracle 19c (schema ASSET)
-- !!! ห้ามรันจนกว่าจะทดสอบเสร็จ — สคริปต์นี้ไม่มี COMMIT อัตโนมัติ !!!
-- =============================================================================
-- ข้อมูลที่ถูกเพิ่มจริงเมื่อ 2026-09-14 (ID ได้จาก sequence ตอนเพิ่มข้อมูล):
--   ASSET                  ID 121-133  (DEMO-ASSET-001 .. DEMO-ASSET-013)
--   ASSET_ASSIGNMENT       ID 11-17
--   ASSET_ASSIGNMENT_LIST  ID 14-20
--
-- เงื่อนไขระบุข้อมูลทดสอบ (ต้องตรงทั้ง ID และเครื่องหมาย จึงไม่ตรงกับข้อมูลจริง):
--   ครุภัณฑ์ทดสอบ   : ASSET.ID IN (121..133) AND ASSET.REMARKS LIKE '[ALLOCATION DEMO DATA]%'
--                   (ไม่ใช้ ASS_CODE เพราะหน้า ASS-005 เปลี่ยนรหัสได้ตอนรับครุภัณฑ์)
--   การจัดสรรทดสอบ  : ASSET_ASSIGNMENT.ID IN (11..17) AND REMARK LIKE '[ALLOCATION DEMO DATA]%'
--   การจัดสรรที่สร้างจากหน้าจอระหว่างทดสอบ : ใบจัดสรรที่มีเฉพาะครุภัณฑ์ทดสอบเท่านั้น
--
-- สคริปต์จะหยุด (RAISE) โดยไม่ลบอะไรเลย ถ้าพบ:
--   - ใบจัดสรรที่มีทั้งครุภัณฑ์ทดสอบและครุภัณฑ์จริงปนกัน
--   - รายการจำหน่าย (ASSET_SELLING_LIST) หรือค่าเสื่อมราคา (ASSET_DEPRECIATION) ที่อ้างถึงครุภัณฑ์ทดสอบ
-- ลำดับการลบ: ASSET_IMAGE -> ASSET_ASSIGNMENT_LIST -> ASSET_ASSIGNMENT -> ASSET
-- =============================================================================

SAVEPOINT before_allocation_demo_cleanup;

-- Step 0: รายการที่จะถูกลบ (ควรได้ไม่เกิน 13 แถว)
SELECT a.ID, a.ASS_CODE, a.ASS_STATUS, a.ORG_ID, a.SUB_ORG_ID, a.REMARKS
FROM ASSET a
WHERE a.ID IN (121, 122, 123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133)
  AND a.REMARKS LIKE '[ALLOCATION DEMO DATA]%'
ORDER BY a.ID;

-- ใบจัดสรรที่จะถูกลบ: ใบทดสอบ 11-17 และใบที่สร้างจากหน้าจอซึ่งมีเฉพาะครุภัณฑ์ทดสอบ
SELECT aa.ID, aa.ORG_ID, aa.TARGET_ORG_ID, aa.STATUS, aa.REMARK
FROM ASSET_ASSIGNMENT aa
WHERE (aa.ID IN (11, 12, 13, 14, 15, 16, 17) AND aa.REMARK LIKE '[ALLOCATION DEMO DATA]%')
   OR (EXISTS (SELECT 1 FROM ASSET_ASSIGNMENT_LIST l JOIN ASSET a ON a.ID = l.ASSET_ID
               WHERE l.ASS_ASSIGN_ID = aa.ID
                 AND a.ID IN (121, 122, 123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133)
                 AND a.REMARKS LIKE '[ALLOCATION DEMO DATA]%')
       AND NOT EXISTS (SELECT 1 FROM ASSET_ASSIGNMENT_LIST l LEFT JOIN ASSET a ON a.ID = l.ASSET_ID
                       WHERE l.ASS_ASSIGN_ID = aa.ID
                         AND NOT (a.ID IN (121, 122, 123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133)
                                  AND a.REMARKS LIKE '[ALLOCATION DEMO DATA]%')))
ORDER BY aa.ID;

-- Step 1-5: ตรวจสอบความปลอดภัยแล้วลบในบล็อกเดียว
DECLARE
    TYPE t_ids IS TABLE OF NUMBER;
    v_asset_ids  t_ids;
    v_assign_ids t_ids;
    v_count      NUMBER;
BEGIN
    SELECT a.ID BULK COLLECT INTO v_asset_ids
    FROM ASSET a
    WHERE a.ID IN (121, 122, 123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133)
      AND a.REMARKS LIKE '[ALLOCATION DEMO DATA]%';

    IF v_asset_ids.COUNT > 13 THEN
        RAISE_APPLICATION_ERROR(-20010, 'Unexpected number of demo assets: ' || v_asset_ids.COUNT);
    END IF;

    -- Step 1: หยุดถ้าครุภัณฑ์ทดสอบถูกนำไปใช้ในเอกสารที่มีข้อมูลจริง
    SELECT COUNT(*) INTO v_count
    FROM ASSET_ASSIGNMENT_LIST l
    WHERE l.ASSET_ID IN (SELECT COLUMN_VALUE FROM TABLE(v_asset_ids))
      AND EXISTS (SELECT 1 FROM ASSET_ASSIGNMENT_LIST o
                  WHERE o.ASS_ASSIGN_ID = l.ASS_ASSIGN_ID
                    AND o.ASSET_ID NOT IN (SELECT COLUMN_VALUE FROM TABLE(v_asset_ids)));
    IF v_count > 0 THEN
        RAISE_APPLICATION_ERROR(-20011, 'Demo assets share an assignment with real assets; resolve manually: ' || v_count);
    END IF;

    SELECT COUNT(*) INTO v_count FROM ASSET_SELLING_LIST WHERE ASS_ID IN (SELECT COLUMN_VALUE FROM TABLE(v_asset_ids));
    IF v_count > 0 THEN
        RAISE_APPLICATION_ERROR(-20012, 'Demo assets are referenced by ASSET_SELLING_LIST; resolve manually: ' || v_count);
    END IF;

    SELECT COUNT(*) INTO v_count FROM ASSET_DEPRECIATION WHERE ASS_ID IN (SELECT COLUMN_VALUE FROM TABLE(v_asset_ids));
    IF v_count > 0 THEN
        RAISE_APPLICATION_ERROR(-20013, 'Demo assets are referenced by ASSET_DEPRECIATION; resolve manually: ' || v_count);
    END IF;

    -- ใบจัดสรรทดสอบ + ใบจัดสรรจากหน้าจอที่มีเฉพาะครุภัณฑ์ทดสอบ
    SELECT aa.ID BULK COLLECT INTO v_assign_ids
    FROM ASSET_ASSIGNMENT aa
    WHERE (aa.ID IN (11, 12, 13, 14, 15, 16, 17) AND aa.REMARK LIKE '[ALLOCATION DEMO DATA]%')
       OR (EXISTS (SELECT 1 FROM ASSET_ASSIGNMENT_LIST l
                   WHERE l.ASS_ASSIGN_ID = aa.ID AND l.ASSET_ID IN (SELECT COLUMN_VALUE FROM TABLE(v_asset_ids)))
           AND NOT EXISTS (SELECT 1 FROM ASSET_ASSIGNMENT_LIST l
                           WHERE l.ASS_ASSIGN_ID = aa.ID AND l.ASSET_ID NOT IN (SELECT COLUMN_VALUE FROM TABLE(v_asset_ids))));

    -- Step 2: รูปภาพที่แนบกับครุภัณฑ์ทดสอบ (ไฟล์ใน storage/app/public/asset-images/<ID> ต้องลบเองถ้ามี)
    FORALL i IN 1 .. v_asset_ids.COUNT
        DELETE FROM ASSET_IMAGE WHERE ASS_ID = v_asset_ids(i);

    -- Step 3: รายการในใบจัดสรร
    FORALL i IN 1 .. v_assign_ids.COUNT
        DELETE FROM ASSET_ASSIGNMENT_LIST WHERE ASS_ASSIGN_ID = v_assign_ids(i);

    -- Step 4: ใบจัดสรร
    FORALL i IN 1 .. v_assign_ids.COUNT
        DELETE FROM ASSET_ASSIGNMENT WHERE ID = v_assign_ids(i);

    -- Step 5: ครุภัณฑ์ทดสอบ
    FORALL i IN 1 .. v_asset_ids.COUNT
        DELETE FROM ASSET WHERE ID = v_asset_ids(i);

    DBMS_OUTPUT.PUT_LINE('Deleted demo assets: ' || v_asset_ids.COUNT || ', assignments: ' || v_assign_ids.COUNT);
END;
/

-- Step 6: ตรวจสอบ (ทุกค่าควรเป็น 0)
SELECT
    (SELECT COUNT(*) FROM ASSET WHERE ID IN (121, 122, 123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133)
                                   AND REMARKS LIKE '[ALLOCATION DEMO DATA]%') AS DEMO_ASSETS,
    (SELECT COUNT(*) FROM ASSET_ASSIGNMENT WHERE REMARK LIKE '[ALLOCATION DEMO DATA]%') AS DEMO_ASSIGNMENTS,
    (SELECT COUNT(*) FROM ASSET_ASSIGNMENT_LIST WHERE ID IN (14, 15, 16, 17, 18, 19, 20)
                                                  AND ASS_ASSIGN_ID IN (11, 12, 13, 14, 15, 16, 17)) AS DEMO_LIST_ROWS
FROM DUAL;

-- ถูกต้อง: COMMIT;   ผิดพลาด: ROLLBACK TO SAVEPOINT before_allocation_demo_cleanup;
-- COMMIT;
