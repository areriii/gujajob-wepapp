-- =============================================================================
-- FILE : ai_forecast_test_data_cleanup.sql
-- DESC : ลบเฉพาะข้อมูลทดสอบ AI พยากรณ์งบประมาณ (DEVELOPMENT TEST DATA) ที่สร้างโดย
--        database/scripts/ai_forecast_test_data.sql
-- DB   : Oracle 19c (schema ASSET)
-- =============================================================================
-- เงื่อนไขระบุข้อมูลทดสอบ (ต้องตรงทั้ง 3 ข้อ จึงไม่มีทางตรงกับครุภัณฑ์จริง):
--   1. ASS_CODE อยู่ในรายการ AI-TEST-001 .. AI-TEST-040 เท่านั้น (ระบุทีละรหัส ไม่ใช้ wildcard)
--   2. ASS_DESC ขึ้นต้นด้วย '[AI FORECAST TEST DATA]'
--   3. ASS_MODEL = 'AI-TEST'
-- ไม่ลบข้อมูลธุรกรรม (ASSET_SELLING_LIST / ASSET_DEPRECIATION / ASSET_ASSIGNMENT_LIST):
--   ถ้า Step 1 พบรายการอ้างอิง ให้ตรวจสอบและจัดการเองก่อน มิฉะนั้น DELETE จะล้มเหลว (ORA-02292)
-- =============================================================================

SAVEPOINT before_ai_forecast_test_cleanup;

-- Step 0: รายการที่จะถูกลบ (ควรได้ไม่เกิน 40 แถว)
SELECT id, ass_code, ass_desc
FROM ASSET
WHERE ass_code IN (
    'AI-TEST-001',
    'AI-TEST-002',
    'AI-TEST-003',
    'AI-TEST-004',
    'AI-TEST-005',
    'AI-TEST-006',
    'AI-TEST-007',
    'AI-TEST-008',
    'AI-TEST-009',
    'AI-TEST-010',
    'AI-TEST-011',
    'AI-TEST-012',
    'AI-TEST-013',
    'AI-TEST-014',
    'AI-TEST-015',
    'AI-TEST-016',
    'AI-TEST-017',
    'AI-TEST-018',
    'AI-TEST-019',
    'AI-TEST-020',
    'AI-TEST-021',
    'AI-TEST-022',
    'AI-TEST-023',
    'AI-TEST-024',
    'AI-TEST-025',
    'AI-TEST-026',
    'AI-TEST-027',
    'AI-TEST-028',
    'AI-TEST-029',
    'AI-TEST-030',
    'AI-TEST-031',
    'AI-TEST-032',
    'AI-TEST-033',
    'AI-TEST-034',
    'AI-TEST-035',
    'AI-TEST-036',
    'AI-TEST-037',
    'AI-TEST-038',
    'AI-TEST-039',
    'AI-TEST-040'
)
  AND ass_desc LIKE '[AI FORECAST TEST DATA]%'
  AND ass_model = 'AI-TEST'
ORDER BY ass_code;

-- Step 1: ตรวจข้อมูลที่อ้างอิงครุภัณฑ์ทดสอบ (ควรเป็น 0 ทั้งหมด)
SELECT 'ASSET_SELLING_LIST' AS ref_table, COUNT(*) AS ref_rows FROM ASSET_SELLING_LIST
WHERE ass_id IN (SELECT id FROM ASSET WHERE ass_code IN (
    'AI-TEST-001',
    'AI-TEST-002',
    'AI-TEST-003',
    'AI-TEST-004',
    'AI-TEST-005',
    'AI-TEST-006',
    'AI-TEST-007',
    'AI-TEST-008',
    'AI-TEST-009',
    'AI-TEST-010',
    'AI-TEST-011',
    'AI-TEST-012',
    'AI-TEST-013',
    'AI-TEST-014',
    'AI-TEST-015',
    'AI-TEST-016',
    'AI-TEST-017',
    'AI-TEST-018',
    'AI-TEST-019',
    'AI-TEST-020',
    'AI-TEST-021',
    'AI-TEST-022',
    'AI-TEST-023',
    'AI-TEST-024',
    'AI-TEST-025',
    'AI-TEST-026',
    'AI-TEST-027',
    'AI-TEST-028',
    'AI-TEST-029',
    'AI-TEST-030',
    'AI-TEST-031',
    'AI-TEST-032',
    'AI-TEST-033',
    'AI-TEST-034',
    'AI-TEST-035',
    'AI-TEST-036',
    'AI-TEST-037',
    'AI-TEST-038',
    'AI-TEST-039',
    'AI-TEST-040'
) AND ass_desc LIKE '[AI FORECAST TEST DATA]%' AND ass_model = 'AI-TEST')
UNION ALL
SELECT 'ASSET_DEPRECIATION', COUNT(*) FROM ASSET_DEPRECIATION
WHERE ass_id IN (SELECT id FROM ASSET WHERE ass_code IN (
    'AI-TEST-001',
    'AI-TEST-002',
    'AI-TEST-003',
    'AI-TEST-004',
    'AI-TEST-005',
    'AI-TEST-006',
    'AI-TEST-007',
    'AI-TEST-008',
    'AI-TEST-009',
    'AI-TEST-010',
    'AI-TEST-011',
    'AI-TEST-012',
    'AI-TEST-013',
    'AI-TEST-014',
    'AI-TEST-015',
    'AI-TEST-016',
    'AI-TEST-017',
    'AI-TEST-018',
    'AI-TEST-019',
    'AI-TEST-020',
    'AI-TEST-021',
    'AI-TEST-022',
    'AI-TEST-023',
    'AI-TEST-024',
    'AI-TEST-025',
    'AI-TEST-026',
    'AI-TEST-027',
    'AI-TEST-028',
    'AI-TEST-029',
    'AI-TEST-030',
    'AI-TEST-031',
    'AI-TEST-032',
    'AI-TEST-033',
    'AI-TEST-034',
    'AI-TEST-035',
    'AI-TEST-036',
    'AI-TEST-037',
    'AI-TEST-038',
    'AI-TEST-039',
    'AI-TEST-040'
) AND ass_desc LIKE '[AI FORECAST TEST DATA]%' AND ass_model = 'AI-TEST')
UNION ALL
SELECT 'ASSET_ASSIGNMENT_LIST', COUNT(*) FROM ASSET_ASSIGNMENT_LIST
WHERE asset_id IN (SELECT id FROM ASSET WHERE ass_code IN (
    'AI-TEST-001',
    'AI-TEST-002',
    'AI-TEST-003',
    'AI-TEST-004',
    'AI-TEST-005',
    'AI-TEST-006',
    'AI-TEST-007',
    'AI-TEST-008',
    'AI-TEST-009',
    'AI-TEST-010',
    'AI-TEST-011',
    'AI-TEST-012',
    'AI-TEST-013',
    'AI-TEST-014',
    'AI-TEST-015',
    'AI-TEST-016',
    'AI-TEST-017',
    'AI-TEST-018',
    'AI-TEST-019',
    'AI-TEST-020',
    'AI-TEST-021',
    'AI-TEST-022',
    'AI-TEST-023',
    'AI-TEST-024',
    'AI-TEST-025',
    'AI-TEST-026',
    'AI-TEST-027',
    'AI-TEST-028',
    'AI-TEST-029',
    'AI-TEST-030',
    'AI-TEST-031',
    'AI-TEST-032',
    'AI-TEST-033',
    'AI-TEST-034',
    'AI-TEST-035',
    'AI-TEST-036',
    'AI-TEST-037',
    'AI-TEST-038',
    'AI-TEST-039',
    'AI-TEST-040'
) AND ass_desc LIKE '[AI FORECAST TEST DATA]%' AND ass_model = 'AI-TEST')
UNION ALL
SELECT 'ASSET_IMAGE', COUNT(*) FROM ASSET_IMAGE
WHERE ass_id IN (SELECT id FROM ASSET WHERE ass_code IN (
    'AI-TEST-001',
    'AI-TEST-002',
    'AI-TEST-003',
    'AI-TEST-004',
    'AI-TEST-005',
    'AI-TEST-006',
    'AI-TEST-007',
    'AI-TEST-008',
    'AI-TEST-009',
    'AI-TEST-010',
    'AI-TEST-011',
    'AI-TEST-012',
    'AI-TEST-013',
    'AI-TEST-014',
    'AI-TEST-015',
    'AI-TEST-016',
    'AI-TEST-017',
    'AI-TEST-018',
    'AI-TEST-019',
    'AI-TEST-020',
    'AI-TEST-021',
    'AI-TEST-022',
    'AI-TEST-023',
    'AI-TEST-024',
    'AI-TEST-025',
    'AI-TEST-026',
    'AI-TEST-027',
    'AI-TEST-028',
    'AI-TEST-029',
    'AI-TEST-030',
    'AI-TEST-031',
    'AI-TEST-032',
    'AI-TEST-033',
    'AI-TEST-034',
    'AI-TEST-035',
    'AI-TEST-036',
    'AI-TEST-037',
    'AI-TEST-038',
    'AI-TEST-039',
    'AI-TEST-040'
) AND ass_desc LIKE '[AI FORECAST TEST DATA]%' AND ass_model = 'AI-TEST');

-- Step 2: ลบรูปภาพที่แนบกับครุภัณฑ์ทดสอบ (ถ้ามี)
DELETE FROM ASSET_IMAGE
WHERE ass_id IN (SELECT id FROM ASSET WHERE ass_code IN (
    'AI-TEST-001',
    'AI-TEST-002',
    'AI-TEST-003',
    'AI-TEST-004',
    'AI-TEST-005',
    'AI-TEST-006',
    'AI-TEST-007',
    'AI-TEST-008',
    'AI-TEST-009',
    'AI-TEST-010',
    'AI-TEST-011',
    'AI-TEST-012',
    'AI-TEST-013',
    'AI-TEST-014',
    'AI-TEST-015',
    'AI-TEST-016',
    'AI-TEST-017',
    'AI-TEST-018',
    'AI-TEST-019',
    'AI-TEST-020',
    'AI-TEST-021',
    'AI-TEST-022',
    'AI-TEST-023',
    'AI-TEST-024',
    'AI-TEST-025',
    'AI-TEST-026',
    'AI-TEST-027',
    'AI-TEST-028',
    'AI-TEST-029',
    'AI-TEST-030',
    'AI-TEST-031',
    'AI-TEST-032',
    'AI-TEST-033',
    'AI-TEST-034',
    'AI-TEST-035',
    'AI-TEST-036',
    'AI-TEST-037',
    'AI-TEST-038',
    'AI-TEST-039',
    'AI-TEST-040'
) AND ass_desc LIKE '[AI FORECAST TEST DATA]%' AND ass_model = 'AI-TEST');

-- Step 3: ลบครุภัณฑ์ทดสอบ
DELETE FROM ASSET
WHERE ass_code IN (
    'AI-TEST-001',
    'AI-TEST-002',
    'AI-TEST-003',
    'AI-TEST-004',
    'AI-TEST-005',
    'AI-TEST-006',
    'AI-TEST-007',
    'AI-TEST-008',
    'AI-TEST-009',
    'AI-TEST-010',
    'AI-TEST-011',
    'AI-TEST-012',
    'AI-TEST-013',
    'AI-TEST-014',
    'AI-TEST-015',
    'AI-TEST-016',
    'AI-TEST-017',
    'AI-TEST-018',
    'AI-TEST-019',
    'AI-TEST-020',
    'AI-TEST-021',
    'AI-TEST-022',
    'AI-TEST-023',
    'AI-TEST-024',
    'AI-TEST-025',
    'AI-TEST-026',
    'AI-TEST-027',
    'AI-TEST-028',
    'AI-TEST-029',
    'AI-TEST-030',
    'AI-TEST-031',
    'AI-TEST-032',
    'AI-TEST-033',
    'AI-TEST-034',
    'AI-TEST-035',
    'AI-TEST-036',
    'AI-TEST-037',
    'AI-TEST-038',
    'AI-TEST-039',
    'AI-TEST-040'
)
  AND ass_desc LIKE '[AI FORECAST TEST DATA]%'
  AND ass_model = 'AI-TEST';

-- Step 4: ตรวจสอบหลังลบ (ต้องเป็น 0)
SELECT COUNT(*) AS remaining_test_rows
FROM ASSET
WHERE ass_code LIKE 'AI-TEST-%' AND ass_model = 'AI-TEST';

-- ผลต้องเป็น 0 จึงรัน COMMIT
-- COMMIT;
-- ถ้าไม่ถูกต้อง:
-- ROLLBACK TO before_ai_forecast_test_cleanup;
