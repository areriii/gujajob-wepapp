ALTER TABLE MATERIAL_WITHDRAWN
ADD mat_wd_type  NUMBER(1) DEFAULT 1;

COMMENT ON COLUMN MATERIAL_WITHDRAWN.mat_wd_type IS 'ประเภทการเบิกวัสดุ 1:เบิกจากคลังกลาง/คลังจังหวัด 2:เบิกเพื่อนำไปใช้';

create table material_yearly_check
(id                   number(10)  not null constraint material_yearly_check_pk primary key,
 org_id               number(10) not null,
 fiscal_year          number(4) not null,
 mat_id               number(10) not null,
 inv_amt              number(6),
 inv_price            number(8,2),
 created_by           number(10)     default 1 not null,
 created_at           timestamp(6)   default current_timestamp not null,
 updated_by           number(10)     default 1 not null,
 updated_at           timestamp(6)   default current_timestamp not null,
 constraint mat_yearly_org_fk foreign key (org_id) references glb_organization(org_id),
 constraint mat_yearly_mat_fk foreign key (mat_id) references materials(id)
 );

alter table material_yearly_check allocate extent;

comment on table material_yearly_check is 'ตารางข้อมูลการตรวจนับวัสดุ ณ สิ้นปีงบประมาณ';
comment on column material_yearly_check.id is 'รหัสประจำรายการ (primary key)';
comment on column material_yearly_check.org_id is 'อ้างอิงรหัสประจำรายการหน่วยงาน (GLB_ORGAMIZATION)';
comment on column material_yearly_check.fiscal_year is 'ปีงบประมาณ';
comment on column material_yearly_check.mat_id is 'อ้างอิงรหัสประจำรายการวัสดุ (MATERIALS)';
comment on column material_yearly_check.inv_amt is 'จำนวนวัสดุในคลัง';
comment on column material_yearly_check.inv_price is 'ราคาต่อหน่วย';
comment on column material_yearly_check.created_by is 'รหัสผู้สร้างรายการ';
comment on column material_yearly_check.created_at is 'วันที่สร้างรายการ';
comment on column material_yearly_check.updated_by is 'รหัสผู้ปรับปรุงรายการ';
comment on column material_yearly_check.updated_at is 'วันที่ปรับปรุงรายการ';

create sequence material_yearly_check_seq
start with 1
increment by 1
minvalue 1
maxvalue 9999999999
cycle
order;

create index mat_yearly_org_idx
on material_yearly_check(org_id);

create index mat_yearly_year_idx
on material_yearly_check(fiscal_year);

create index mat_yearly_mat_idx
on material_yearly_check(mat_id);

create table asset_depreciation
(id                   number(10)  not null constraint asset_depreciation_pk primary key,
 ass_id               number(10)  not null,
 cal_date             date,
 depreciation_amt     number(10,2),
 accum_depreciation   number(10,2),
 remain_amt           number(10,2),
 created_by           number(10)     default 1 not null,
 created_at           timestamp(6)   default current_timestamp not null,
 updated_by           number(10)     default 1 not null,
 updated_at           timestamp(6)   default current_timestamp not null,
 constraint asset_depre_asset_fk foreign key (ass_id) references asset(id)
 );

alter table asset_depreciation allocate extent;

comment on table asset_depreciation is 'ตารางข้อมูลค่าเสื่อมราคาของครุภัณฑ์';
comment on column asset_depreciation.id is 'รหัสประจำรายการ (primary key)';
comment on column asset_depreciation.ass_id is 'อ้างอิงรหัสประจำรายการครุภัณฑ์หน่วยงาน (ASSET)';
comment on column asset_depreciation.cal_date is 'วันที่คิดค่าเสื่อมราคา';
comment on column asset_depreciation.depreciation_amt is 'ค่าเสื่อมราคา';
comment on column asset_depreciation.accum_depreciation is 'ค่าเสื่อมราคาสะสม';
comment on column asset_depreciation.remain_amt is 'มูลค่าคงเหลือ';
comment on column asset_depreciation.created_by is 'รหัสผู้สร้างรายการ';
comment on column asset_depreciation.created_at is 'วันที่สร้างรายการ';
comment on column asset_depreciation.updated_by is 'รหัสผู้ปรับปรุงรายการ';
comment on column asset_depreciation.updated_at is 'วันที่ปรับปรุงรายการ';

create sequence asset_depreciation_seq
start with 1
increment by 1
minvalue 1
maxvalue 9999999999
cycle
order;

create index asset_depreciation_asset_idx
on asset_depreciation(ass_id);

