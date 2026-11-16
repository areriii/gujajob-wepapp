ALTER TABLE ASSET
ADD procure_org_id  NUMBER(10);

COMMENT ON COLUMN ASSET.procure_org_id IS 'อ้างอิงรหัสประจำรายการหน่วยงานที่จัดหาครุภัณฑ์ (GLB_ORGAMIZATION)';
