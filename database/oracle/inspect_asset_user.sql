-- ตรวจว่า user ASSET ใน PDB orclpdb มีตาราง/ข้อมูลอะไรอยู่บ้าง
-- ใช้: sqlplus / as sysdba @inspect_asset_user.sql
alter session set container = ORCLPDB;
set heading on feedback off pagesize 100 linesize 110
col TABLE_NAME format a32
col TABLESPACE_NAME format a20

prompt ===== จำนวน object ของ ASSET =====
select object_type, count(*) as qty
from   dba_objects
where  owner = 'ASSET'
group  by object_type
order  by object_type;

prompt ===== ตารางของ ASSET (num_rows อาจเป็น NULL ถ้ายังไม่เคย gather stats) =====
select table_name, tablespace_name, num_rows
from   dba_tables
where  owner = 'ASSET'
order  by table_name;

prompt ===== พื้นที่ที่ ASSET ใช้จริง (MB) =====
select nvl(round(sum(bytes)/1024/1024, 2), 0) as used_mb
from   dba_segments
where  owner = 'ASSET';

exit
