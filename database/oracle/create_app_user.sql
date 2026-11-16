-- สร้าง user แอปใน PDB orclpdb
-- ใช้: sqlplus / as sysdba @create_app_user.sql <password>
alter session set container = ORCLPDB;
create user asset identified by "&1"
  default tablespace USERS
  temporary tablespace TEMP
  quota unlimited on USERS;
grant create session, create table, create sequence, create view,
      create trigger, create procedure to asset;
select username, account_status from dba_users where username = 'ASSET';
exit
