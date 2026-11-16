-- เปิด PDB orclpdb และจำสถานะไว้ให้เปิดเองทุกครั้งที่ Oracle restart
-- ใช้เมื่อเจอ ORA-01109: database not open
set linesize 120 pagesize 50
select name, open_mode from v$pdbs;
alter pluggable database orclpdb open;
alter pluggable database orclpdb save state;
select name, open_mode from v$pdbs;
exit
