-- =============================================================================
-- ปิดช่องว่างระหว่าง asset.dmp (exp 2026-07-06) กับโค้ดปัจจุบัน (commit 3444e7f)
-- รันหลัง imp เสร็จแล้วเท่านั้น
--
-- ใช้: sqlplus asset/<password>@localhost:1521/orclpdb @database/oracle/post_import_gap_fill.sql
--
-- ที่มาของนิยามคอลัมน์:
--   - CG_REF_CODES, ASSET_ASSIGNMENT, ASSET_ASSIGNMENT_LIST
--       => เอกสาร "GUJAJOB - Report.pdf" หัวข้อ 3.5.2 พจนานุกรมข้อมูล (น.141, 142, 145)
--   - ASSET.REPLACEMENT_STATUS
--       => ไม่มีในเล่มรายงาน (เพิ่มทีหลังใน commit e658370) อ้างจากโค้ด:
--          AssetController กำหนด in:no,yes และ default 'no'
--
-- หมายเหตุที่ตั้งใจต่างจากเอกสาร:
--   ASSET_ASSIGNMENT.ORG_ID ในเล่มระบุ Varchar2(20) แต่เป็น FK ไปยัง
--   GLB_ORGANIZATION.ORG_ID ซึ่งเป็น NUMBER จึงสร้างเป็น NUMBER(10)
--   มิฉะนั้น FK จะสร้างไม่ได้ และ demo data ที่มีอยู่ก็ใส่ค่าเป็นตัวเลข
--
-- เขียนแบบรันซ้ำได้ (มีอยู่แล้วจะข้าม ไม่ error)
-- =============================================================================
SET DEFINE OFF;
SET SERVEROUTPUT ON;

-- ---------------------------------------------------------------------------
-- 1. ASSET.REPLACEMENT_STATUS  ('no' / 'yes', ค่าเริ่มต้น 'no')
-- ---------------------------------------------------------------------------
DECLARE
    n NUMBER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'ASSET' AND column_name = 'REPLACEMENT_STATUS';
    IF n = 0 THEN
        EXECUTE IMMEDIATE
            'ALTER TABLE ASSET ADD (REPLACEMENT_STATUS VARCHAR2(3 CHAR) DEFAULT ''no'')';
        EXECUTE IMMEDIATE
            'UPDATE ASSET SET REPLACEMENT_STATUS = ''no'' WHERE REPLACEMENT_STATUS IS NULL';
        COMMIT;
        DBMS_OUTPUT.PUT_LINE('OK  เพิ่ม ASSET.REPLACEMENT_STATUS');
    ELSE
        DBMS_OUTPUT.PUT_LINE('ข้าม ASSET.REPLACEMENT_STATUS (มีอยู่แล้ว)');
    END IF;
END;
/

-- ---------------------------------------------------------------------------
-- 2. CG_REF_CODES  (น.145) - ไม่มีคอลัมน์ ID ตามเอกสาร
--    โค้ดใช้เฉพาะ domain 'PROCUREMENT_METHOD' (MaterialReceivingController MAT-002)
-- ---------------------------------------------------------------------------
DECLARE
    n NUMBER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'CG_REF_CODES';
    IF n = 0 THEN
        EXECUTE IMMEDIATE q'[
            CREATE TABLE CG_REF_CODES (
                REF_CODE    VARCHAR2(10 CHAR)  NOT NULL,
                REF_NAME    VARCHAR2(100 CHAR) NOT NULL,
                REF_DOMAIN  VARCHAR2(50 CHAR)  NOT NULL,
                CREATED_BY  NUMBER(10)   DEFAULT 1,
                CREATED_AT  TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
                UPDATED_BY  NUMBER(10)   DEFAULT 1,
                UPDATED_AT  TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT PK_CG_REF_CODES PRIMARY KEY (REF_DOMAIN, REF_CODE)
            )]';
        DBMS_OUTPUT.PUT_LINE('OK  สร้าง CG_REF_CODES');
    ELSE
        DBMS_OUTPUT.PUT_LINE('ข้าม CG_REF_CODES (มีอยู่แล้ว)');
    END IF;
END;
/

-- เติมวิธีจัดซื้อ: ดึงจาก MAT_PRO_METHOD ที่มาพร้อม dump ถ้าไม่มีจึงใช้ค่าเริ่มต้น
DECLARE
    n       NUMBER;
    cnt_src NUMBER := 0;
BEGIN
    SELECT COUNT(*) INTO n FROM CG_REF_CODES WHERE REF_DOMAIN = 'PROCUREMENT_METHOD';
    IF n > 0 THEN
        DBMS_OUTPUT.PUT_LINE('ข้าม PROCUREMENT_METHOD (มี ' || n || ' แถวแล้ว)');
        RETURN;
    END IF;

    SELECT COUNT(*) INTO cnt_src FROM user_tables WHERE table_name = 'MAT_PRO_METHOD';

    IF cnt_src > 0 THEN
        EXECUTE IMMEDIATE q'[
            INSERT INTO CG_REF_CODES (REF_CODE, REF_NAME, REF_DOMAIN)
            SELECT TO_CHAR(METHOD_ID), METHOD_NAME, 'PROCUREMENT_METHOD'
            FROM   MAT_PRO_METHOD]';
        DBMS_OUTPUT.PUT_LINE('OK  คัดลอกวิธีจัดซื้อจาก MAT_PRO_METHOD');
    ELSE
        INSERT INTO CG_REF_CODES (REF_CODE, REF_NAME, REF_DOMAIN)
        VALUES ('1', 'เฉพาะเจาะจง', 'PROCUREMENT_METHOD');
        INSERT INTO CG_REF_CODES (REF_CODE, REF_NAME, REF_DOMAIN)
        VALUES ('2', 'ประกวดราคา', 'PROCUREMENT_METHOD');
        INSERT INTO CG_REF_CODES (REF_CODE, REF_NAME, REF_DOMAIN)
        VALUES ('3', 'คัดเลือก', 'PROCUREMENT_METHOD');
        DBMS_OUTPUT.PUT_LINE('OK  ใส่วิธีจัดซื้อค่าเริ่มต้น 3 รายการ');
    END IF;
    COMMIT;
END;
/

-- ---------------------------------------------------------------------------
-- 3. ASSET_ASSIGNMENT (น.141)  - ASS-004 จัดสรรครุภัณฑ์ให้หน่วยงาน
--    STATUS  0:ยกเลิก 1:จัดสรรแล้ว 2:รับครุภัณฑ์แล้ว
-- ---------------------------------------------------------------------------
DECLARE
    n NUMBER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'ASSET_ASSIGNMENT';
    IF n = 0 THEN
        EXECUTE IMMEDIATE q'[
            CREATE TABLE ASSET_ASSIGNMENT (
                ID                 NUMBER(10) NOT NULL,
                ORG_ID             NUMBER(10),
                TARGET_ORG_ID      NUMBER(10),
                TARGET_SUB_ORG_ID  NUMBER(10),
                ASSIGNER_ID        NUMBER(10),
                ASSIGN_DATE        DATE,
                STATUS             VARCHAR2(1 CHAR),
                REMARK             VARCHAR2(500 CHAR),
                CREATED_BY         NUMBER(10)   DEFAULT 1,
                CREATED_AT         TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
                UPDATED_BY         NUMBER(10)   DEFAULT 1,
                UPDATED_AT         TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT PK_ASSET_ASSIGNMENT PRIMARY KEY (ID)
            )]';
        DBMS_OUTPUT.PUT_LINE('OK  สร้าง ASSET_ASSIGNMENT');
    ELSE
        DBMS_OUTPUT.PUT_LINE('ข้าม ASSET_ASSIGNMENT (มีอยู่แล้ว)');
    END IF;
END;
/

-- FK แยกออกมา เพราะ GLB_ORGANIZATION / SYS_USER ต้องมาจาก dump ก่อน
DECLARE
    PROCEDURE add_fk(p_sql VARCHAR2, p_label VARCHAR2) IS
    BEGIN
        EXECUTE IMMEDIATE p_sql;
        DBMS_OUTPUT.PUT_LINE('OK  ' || p_label);
    EXCEPTION
        WHEN OTHERS THEN
            -- 2275 = constraint ซ้ำ, 2270/942 = ตารางแม่ไม่พร้อม
            DBMS_OUTPUT.PUT_LINE('ข้าม ' || p_label || ' (' || SQLCODE || ')');
    END;
BEGIN
    add_fk('ALTER TABLE ASSET_ASSIGNMENT ADD CONSTRAINT FK_AA_ORG '
        || 'FOREIGN KEY (ORG_ID) REFERENCES GLB_ORGANIZATION (ORG_ID)', 'FK_AA_ORG');
    add_fk('ALTER TABLE ASSET_ASSIGNMENT ADD CONSTRAINT FK_AA_TARGET_ORG '
        || 'FOREIGN KEY (TARGET_ORG_ID) REFERENCES GLB_ORGANIZATION (ORG_ID)', 'FK_AA_TARGET_ORG');
END;
/

-- ---------------------------------------------------------------------------
-- 4. ASSET_ASSIGNMENT_LIST (น.142)
-- ---------------------------------------------------------------------------
DECLARE
    n NUMBER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'ASSET_ASSIGNMENT_LIST';
    IF n = 0 THEN
        EXECUTE IMMEDIATE q'[
            CREATE TABLE ASSET_ASSIGNMENT_LIST (
                ID             NUMBER(10) NOT NULL,
                ASS_ASSIGN_ID  NUMBER(10),
                ASSET_ID       NUMBER(10),
                CREATED_BY     NUMBER(10)   DEFAULT 1,
                CREATED_AT     TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
                UPDATED_BY     NUMBER(10)   DEFAULT 1,
                UPDATED_AT     TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT PK_ASSET_ASSIGNMENT_LIST PRIMARY KEY (ID),
                CONSTRAINT FK_AAL_ASSIGNMENT FOREIGN KEY (ASS_ASSIGN_ID)
                    REFERENCES ASSET_ASSIGNMENT (ID),
                CONSTRAINT FK_AAL_ASSET FOREIGN KEY (ASSET_ID)
                    REFERENCES ASSET (ID)
            )]';
        DBMS_OUTPUT.PUT_LINE('OK  สร้าง ASSET_ASSIGNMENT_LIST');
    ELSE
        DBMS_OUTPUT.PUT_LINE('ข้าม ASSET_ASSIGNMENT_LIST (มีอยู่แล้ว)');
    END IF;
END;
/

-- ---------------------------------------------------------------------------
-- 5. sequence ที่โค้ดเรียก NEXTVAL แต่ไม่มีมากับ dump
--    ตั้งค่าเริ่มต้นให้สูงกว่า ID สูงสุดที่มีอยู่ กันชนกับข้อมูลที่ import มา
-- ---------------------------------------------------------------------------
DECLARE
    TYPE t_pair IS RECORD (seq VARCHAR2(40), tab VARCHAR2(40));
    TYPE t_list IS TABLE OF t_pair;
    items t_list := t_list(
        t_pair('ASSET_ASSIGNMENT_SEQ',      'ASSET_ASSIGNMENT'),
        t_pair('ASSET_ASSIGNMENT_LIST_SEQ', 'ASSET_ASSIGNMENT_LIST')
    );
    n       NUMBER;
    max_id  NUMBER;
BEGIN
    FOR i IN 1 .. items.COUNT LOOP
        SELECT COUNT(*) INTO n FROM user_sequences WHERE sequence_name = items(i).seq;
        IF n = 0 THEN
            EXECUTE IMMEDIATE 'SELECT NVL(MAX(ID),0)+1 FROM ' || items(i).tab INTO max_id;
            EXECUTE IMMEDIATE 'CREATE SEQUENCE ' || items(i).seq
                || ' START WITH ' || max_id || ' INCREMENT BY 1 NOCACHE NOCYCLE';
            DBMS_OUTPUT.PUT_LINE('OK  สร้าง ' || items(i).seq || ' เริ่มที่ ' || max_id);
        ELSE
            DBMS_OUTPUT.PUT_LINE('ข้าม ' || items(i).seq || ' (มีอยู่แล้ว)');
        END IF;
    END LOOP;
END;
/

COMMIT;

-- ---------------------------------------------------------------------------
-- ตรวจผล
-- ---------------------------------------------------------------------------
SET LINESIZE 110
COL TABLE_NAME FORMAT A32
COL REF_NAME   FORMAT A30
PROMPT ===== ตารางทั้งหมด =====
SELECT table_name FROM user_tables ORDER BY table_name;

PROMPT ===== sequence ทั้งหมด =====
SELECT sequence_name FROM user_sequences ORDER BY sequence_name;

PROMPT ===== วิธีจัดซื้อที่ dropdown MAT-002 จะใช้ =====
SELECT ref_code, ref_name FROM CG_REF_CODES
WHERE ref_domain = 'PROCUREMENT_METHOD' ORDER BY ref_code;

EXIT
