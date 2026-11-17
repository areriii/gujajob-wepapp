-- =============================================================================
-- ขยายความกว้างคอลัมน์เลขที่เอกสารให้พอกับรูปแบบที่โค้ดสร้างจริง
-- File   : database/oracle/document_code_widen.sql
-- Schema : ASSET
--
-- ปัญหาที่แก้
--   ฐานข้อมูลที่กู้มามีคอลัมน์เลขที่เอกสารกว้างแค่ 9 ตัวอักษร
--   (VARCHAR2(36 BYTE) บนฐาน AL32UTF8 = 9 CHAR)
--   แต่โค้ดสร้างรหัสยาว 11 ตัวอักษรทั้งสามระบบ
--
--     MaterialReceivingController  MPR-YYNNNNN  เช่น MPR-6900001   (11)
--     MaterialWithdrawController   MWD-YYNNNNN  เช่น MWD-6900001   (11)
--     MaterialTransferReceive...   ISP-YYNNNNN  เช่น ISP-6900001   (11)
--
--   ผลคือกดบันทึกที่ MAT-002 / MAT-003 / MAT-004 จะได้
--     ORA-12899: value too large for column ... (actual: 11, maximum: 9)
--   ข้อมูลเดิมในฐานใช้รหัสแบบเก่าที่สั้นกว่า จึงไม่เคยชนปัญหานี้
--
--   สคริปต์ document_number_prefix_update.sql เขียน ALTER ชุดนี้ไว้แล้วแต่ comment ไว้
--   พร้อมหมายเหตุว่า "uncomment ถ้า column สั้นกว่า 10" ซึ่งฐานนี้เข้าเงื่อนไขนั้นพอดี
--
-- ปลอดภัย
--   ขยายอย่างเดียว ไม่ลดขนาด ไม่แตะข้อมูลเดิม
--   รันซ้ำได้ ถ้ากว้างพออยู่แล้วจะข้ามให้
--   ใช้ CHAR semantics ชัดเจน จะได้ไม่ขึ้นกับ charset ของฐาน
-- =============================================================================
SET SERVEROUTPUT ON;
SET DEFINE OFF;

DECLARE
    TYPE t_col IS RECORD (tab VARCHAR2(30), col VARCHAR2(30));
    TYPE t_cols IS TABLE OF t_col INDEX BY PLS_INTEGER;

    v_targets t_cols;
    v_width   NUMBER;
    v_changed NUMBER := 0;
BEGIN
    v_targets(1).tab := 'MATERIAL_PROCUREMENT'; v_targets(1).col := 'MAT_PRO_CODE';
    v_targets(2).tab := 'MATERIAL_WITHDRAWN';   v_targets(2).col := 'MAT_WD_CODE';
    v_targets(3).tab := 'MATERIAL_INSPECTION';  v_targets(3).col := 'MAT_INSP_CODE';

    FOR i IN 1 .. v_targets.COUNT LOOP
        SELECT NVL(MAX(char_length), 0) INTO v_width
          FROM user_tab_columns
         WHERE table_name = v_targets(i).tab
           AND column_name = v_targets(i).col;

        IF v_width = 0 THEN
            DBMS_OUTPUT.PUT_LINE('ข้าม: ไม่พบ ' || v_targets(i).tab || '.' || v_targets(i).col);
        ELSIF v_width >= 20 THEN
            DBMS_OUTPUT.PUT_LINE('ข้าม: ' || v_targets(i).col || ' กว้าง ' || v_width || ' ตัวอักษรอยู่แล้ว');
        ELSE
            EXECUTE IMMEDIATE 'ALTER TABLE ' || v_targets(i).tab ||
                              ' MODIFY (' || v_targets(i).col || ' VARCHAR2(20 CHAR))';
            DBMS_OUTPUT.PUT_LINE('ขยาย: ' || v_targets(i).tab || '.' || v_targets(i).col ||
                                 '  ' || v_width || ' -> 20 ตัวอักษร');
            v_changed := v_changed + 1;
        END IF;
    END LOOP;

    DBMS_OUTPUT.PUT_LINE('-----------------------------------------------------');
    DBMS_OUTPUT.PUT_LINE('แก้ไขทั้งหมด ' || v_changed || ' คอลัมน์');
END;
/

SELECT table_name, column_name, char_length
  FROM user_tab_columns
 WHERE column_name IN ('MAT_PRO_CODE', 'MAT_WD_CODE', 'MAT_INSP_CODE')
 ORDER BY table_name;

EXIT
