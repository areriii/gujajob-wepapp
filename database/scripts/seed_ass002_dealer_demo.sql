-- =============================================================================
-- Seed: ข้อมูลผู้ประกอบการสำหรับเดโม ASS-002
-- File   : database/scripts/seed_ass002_dealer_demo.sql
-- Schema : ASSET
--
-- ทำไมต้องมีไฟล์นี้
--   ตาราง DEALER มี 5 แถว แต่กรอกไว้แค่ชื่อกับเลขประจำตัวผู้เสียภาษี
--   คอลัมน์ ประเภท / ชื่อผู้ติดต่อ / เบอร์โทรศัพท์ / ที่อยู่ เป็น "-" เกือบทั้งหมด
--   และแถว id=4 มีข้อมูลขยะจากการทดลองพิมพ์ (ที่อยู่ "ฟ ก ห", เบอร์ 0000000000)
--
-- สิ่งที่ทำ
--   1) เติมข้อมูลให้ผู้ประกอบการเดิม 5 รายให้ครบทุกช่อง (ไม่เปลี่ยนชื่อ ไม่เปลี่ยน id)
--      เพราะ MATERIAL_PROCUREMENT.DEALER_ID และ ASSET.DEALER_ID อ้างถึง id เหล่านี้อยู่
--      ใบรับวัสดุและทะเบียนครุภัณฑ์ที่มีอยู่จึงยังชี้ไปที่เดิม
--   2) เพิ่มผู้ประกอบการใหม่อีก 10 ราย ให้ครบทั้ง 5 ประเภท และกระจาย 10 จังหวัด
--      จะได้มีข้อมูลพอให้เดโมการค้นหาและการแบ่งหน้า (หน้าละ 10 รายการ)
--
-- ประเภทผู้ประกอบการ (App\Models\Dealer::DEALER_TYPES)
--   1 = บริษัท จำกัด   2 = บริษัทมหาชนจำกัด   3 = ห้างหุ้นส่วนจำกัด
--   4 = ร้านค้า        5 = บุคคลธรรมดา
--
-- ที่อยู่ใช้รหัสจริงจาก GLB_PROVINCE / GLB_AMPHUR / GLB_TAMBON
--   ระบบตรวจสอบว่าอำเภออยู่ในจังหวัดนั้นจริงและตำบลอยู่ในอำเภอนั้นจริง
--   ถ้าใส่รหัสมั่วจะแก้ไขผ่านหน้าเว็บไม่ได้ (ขึ้น "อำเภอที่เลือกไม่ถูกต้อง")
--
-- ชื่อบริษัททั้งหมดเป็นชื่อสมมติ ไม่ใช่นิติบุคคลที่มีอยู่จริง
-- เลขประจำตัวผู้เสียภาษีเป็นตัวเลขสมมติเช่นกัน ใช้สำหรับทดสอบระบบเท่านั้น
--
-- คุณสมบัติ
--   - ส่วนอัปเดตรันซ้ำได้ ผลลัพธ์เหมือนเดิมทุกครั้ง
--   - ส่วนเพิ่มจะข้ามถ้ามีชื่อนั้นอยู่แล้ว
--   - ใช้ DEALER_SEQ เหมือนที่ DealerController ใช้ ลำดับจึงไม่ชนกัน
--
-- วิธีรัน (มีภาษาไทย ต้องตั้ง NLS_LANG ก่อน)
--   set NLS_LANG=AMERICAN_AMERICA.AL32UTF8
--   sqlplus asset/<รหัส>@localhost:1521/orclpdb @database/scripts/seed_ass002_dealer_demo.sql
-- =============================================================================
SET SERVEROUTPUT ON;
SET DEFINE OFF;

DECLARE
    v_updated NUMBER := 0;
    v_added   NUMBER := 0;
    v_exists  NUMBER;
    v_id      NUMBER;

    PROCEDURE fill (
        p_id      IN NUMBER,
        p_type    IN VARCHAR2,
        p_tax     IN VARCHAR2,
        p_addr    IN VARCHAR2,
        p_alley   IN VARCHAR2,
        p_street  IN VARCHAR2,
        p_tam     IN NUMBER,
        p_amp     IN NUMBER,
        p_prov    IN NUMBER,
        p_zip     IN VARCHAR2,
        p_contact IN VARCHAR2,
        p_phone   IN VARCHAR2
    ) IS
    BEGIN
        UPDATE DEALER
           SET dealer_type    = p_type,
               dealer_tax_id  = p_tax,
               dealer_addr_no = p_addr,
               dealer_alley   = p_alley,
               dealer_street  = p_street,
               dealer_tam_id  = p_tam,
               dealer_amp_id  = p_amp,
               dealer_prov_id = p_prov,
               dealer_zipcode = p_zip,
               dealer_contact = p_contact,
               dealer_phone   = p_phone,
               updated_by     = 1,
               updated_at     = SYSTIMESTAMP
         WHERE id = p_id;

        v_updated := v_updated + SQL%ROWCOUNT;
    END fill;

    PROCEDURE add_dealer (
        p_name    IN VARCHAR2,
        p_type    IN VARCHAR2,
        p_tax     IN VARCHAR2,
        p_addr    IN VARCHAR2,
        p_alley   IN VARCHAR2,
        p_street  IN VARCHAR2,
        p_tam     IN NUMBER,
        p_amp     IN NUMBER,
        p_prov    IN NUMBER,
        p_zip     IN VARCHAR2,
        p_contact IN VARCHAR2,
        p_phone   IN VARCHAR2
    ) IS
        v_n   NUMBER;
        v_new NUMBER;
    BEGIN
        SELECT COUNT(*) INTO v_n FROM DEALER WHERE dealer_name = p_name;
        IF v_n > 0 THEN RETURN; END IF;

        SELECT DEALER_SEQ.NEXTVAL INTO v_new FROM DUAL;

        INSERT INTO DEALER (
            ID, DEALER_TYPE, DEALER_NAME, DEALER_TAX_ID,
            DEALER_ADDR_NO, DEALER_ALLEY, DEALER_STREET,
            DEALER_TAM_ID, DEALER_AMP_ID, DEALER_PROV_ID, DEALER_ZIPCODE,
            DEALER_CONTACT, DEALER_PHONE,
            CREATED_BY, CREATED_AT, UPDATED_BY, UPDATED_AT
        ) VALUES (
            v_new, p_type, p_name, p_tax,
            p_addr, p_alley, p_street,
            p_tam, p_amp, p_prov, p_zip,
            p_contact, p_phone,
            1, SYSTIMESTAMP, 1, SYSTIMESTAMP
        );

        v_added := v_added + 1;
    END add_dealer;

BEGIN
    -- ── เติมข้อมูลผู้ประกอบการเดิม 5 ราย ────────────────────────────────────
    -- id 1 ขายวัสดุสำนักงาน เป็นผู้ขายในใบรับวัสดุและทะเบียนครุภัณฑ์หลายใบ
    fill(1, '1', '0105563012341', '199/12', 'ซอยรัชดาภิเษก 32', 'ถนนรัชดาภิเษก',
         234, 57, 3, '11000', 'คุณอารยา เจริญทรัพย์', '02-591-8800');

    fill(2, '1', '0105548078523', '88/7', 'ซอยลาดพร้าว 101', 'ถนนลาดพร้าว',
         2, 1, 1, '10200', 'คุณสมศักดิ์ วงศ์สุวรรณ', '02-731-4567');

    fill(3, '1', '0105558091234', '45', 'ซอยพหลโยธิน 24', 'ถนนพหลโยธิน',
         1, 1, 1, '10200', 'คุณธนากร อินทรวิชัย', '02-279-3321');

    -- id 4 เดิมมีข้อมูลขยะจากการทดลองพิมพ์ (ที่อยู่ "ฟ ก ห" เบอร์ 0000000000)
    fill(4, '1', '0105561045678', '272/5', 'ซอยเพชรเกษม 48', 'ถนนเพชรเกษม',
         183, 51, 2, '10270', 'คุณวิภาดา ศรีสุข', '02-455-9120');

    fill(5, '3', '0993562031289', '14/3', '-', 'ถนนประชาราษฎร์',
         285, 63, 4, '12000', 'คุณบุญเลิศ เจริญพาณิชย์', '02-581-7744');

    -- ── เพิ่มผู้ประกอบการใหม่ ครบทั้ง 5 ประเภท ──────────────────────────────
    add_dealer('บริษัท ไอยรา ออฟฟิศ ซัพพลาย จำกัด (มหาชน)', '2', '0107547000123',
        '1010', 'ซอยสุขุมวิท 22', 'ถนนสุขุมวิท', 346, 70, 5, '13000',
        'คุณณัฐพงษ์ ธีรศักดิ์', '035-241-660');

    add_dealer('บริษัท เจริญรุ่งเรือง เครื่องเขียน จำกัด', '1', '0105552067890',
        '77/8', 'ซอยงามวงศ์วาน 18', 'ถนนงามวงศ์วาน', 233, 57, 3, '11000',
        'คุณสุชาดา พงษ์พานิช', '02-952-3311');

    add_dealer('ห้างหุ้นส่วนจำกัด นครพานิช เครื่องใช้สำนักงาน', '3', '0303549001122',
        '256/1', '-', 'ถนนมิตรภาพ', 1480, 198, 19, '30000',
        'คุณประสิทธิ์ นครชัย', '044-253-180');

    add_dealer('ร้านรุ่งเรืองเครื่องเขียน', '4', '3100900456789',
        '42', 'ซอยบางปลาสร้อย 5', 'ถนนเจตน์จำนง', 958, 131, 11, '20000',
        'คุณมานพ รุ่งเรือง', '038-274-905');

    add_dealer('ร้านไอทีสมาร์ทช็อป', '4', '3401200778899',
        '119/4', '-', 'ถนนศรีจันทร์', 2913, 363, 29, '40000',
        'คุณอนุชา สมบูรณ์ทรัพย์', '043-321-447');

    add_dealer('บริษัท ภาคเหนือครุภัณฑ์ จำกัด', '1', '0505556003344',
        '305/7', 'ซอยช้างเผือก 3', 'ถนนช้างเผือก', 4158, 520, 39, '50200',
        'คุณกิตติชัย ล้านนาพาณิชย์', '053-218-770');

    add_dealer('บริษัท อีสานเทคโนโลยี จำกัด (มหาชน)', '2', '0107556000987',
        '888', '-', 'ถนนกลางเมือง', 2914, 363, 29, '40000',
        'คุณพัชรี ภูวดล', '043-445-221');

    add_dealer('ห้างหุ้นส่วนจำกัด ใต้รุ่งเรืองพาณิชย์', '3', '0903547005566',
        '63/12', 'ซอยนิพัทธ์อุทิศ 2', 'ถนนนิพัทธ์อุทิศ', 6874, 852, 71, '90000',
        'คุณสมพร ทองสุข', '074-311-258');

    add_dealer('นายสมชาย ใจกว้าง', '5', '1100500123456',
        '9/21', 'ซอยติวานนท์ 14', 'ถนนติวานนท์', 286, 63, 4, '12000',
        'นายสมชาย ใจกว้าง', '081-234-5678');

    add_dealer('นางสาวพิมพ์ใจ รักงาน', '5', '1909800654321',
        '77', '-', 'ถนนกาญจนวนิช', 6875, 852, 71, '90000',
        'นางสาวพิมพ์ใจ รักงาน', '089-876-5432');

    COMMIT;
    DBMS_OUTPUT.PUT_LINE('=====================================================');
    DBMS_OUTPUT.PUT_LINE('เติมข้อมูลผู้ประกอบการเดิม : ' || v_updated || ' ราย');
    DBMS_OUTPUT.PUT_LINE('เพิ่มผู้ประกอบการใหม่      : ' || v_added || ' ราย');
    SELECT COUNT(*) INTO v_exists FROM DEALER;
    DBMS_OUTPUT.PUT_LINE('รวมทั้งหมด                 : ' || v_exists || ' ราย');
    DBMS_OUTPUT.PUT_LINE('=====================================================');

EXCEPTION
    WHEN OTHERS THEN
        ROLLBACK;
        DBMS_OUTPUT.PUT_LINE('ERROR - rolled back: ' || SQLERRM);
        RAISE;
END;
/

EXIT
