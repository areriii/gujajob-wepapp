-- รีเซ็ตรหัสผ่าน user ASSET ให้ตรงกับ DB_PASSWORD ใน .env
-- ใช้: sqlplus / as sysdba @reset_app_user_password.sql <password>
alter session set container = ORCLPDB;
alter user asset identified by "&1" account unlock;
-- เผื่อ user เดิมยังไม่มีสิทธิ์ครบ
grant create session, create table, create sequence, create view,
      create trigger, create procedure to asset;
alter user asset quota unlimited on USERS;
select username, account_status from dba_users where username = 'ASSET';
exit
