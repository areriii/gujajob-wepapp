-- ตรวจว่าใน PDB orclpdb มี schema/ข้อมูลของโปรเจกต์อยู่ไหม
alter session set container = ORCLPDB;
set heading on feedback off pagesize 80 linesize 110
col USERNAME format a25
col OWNER format a25

prompt === user ที่ไม่ใช่ของระบบ ===
select username, account_status, created
from   dba_users
where  oracle_maintained = 'N'
order  by username;

prompt === จำนวนตารางต่อ schema (เฉพาะที่ไม่ใช่ระบบ) ===
select owner, count(*) as tables
from   dba_tables
where  owner in (select username from dba_users where oracle_maintained = 'N')
group  by owner
order  by owner;

prompt === มีตารางหลักของโปรเจกต์ไหม ===
select owner, table_name
from   dba_tables
where  table_name in ('ASSET','MATERIALS','SYS_USER','CG_REF_CODES','MATERIAL_INVENTORY')
order  by owner, table_name;

exit
