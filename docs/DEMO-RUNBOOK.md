# คู่มือเดโมหน้าชั้นเรียน (GUJAJOB)

ตั้งค่าไว้เมื่อ 2026-11-15 หลังกู้ระบบจากโน้ตบุ๊คที่เปิดไม่ติด
ทุกอย่างรันบนเดสก์ท็อป `desktop-ru1cnr0` และเปิดออกอินเทอร์เน็ตผ่าน Cloudflare Tunnel

---

## บัญชีสำหรับ login

รหัสผ่านทุกบัญชีคือ **`1234`** (ระบบใช้ `OraclePlainTextUserProvider` เก็บรหัสแบบ plain text)

| ชื่อผู้ใช้ | บทบาท | หน่วยงาน |
|---|---|---|
| `user01` | เจ้าหน้าที่ฝ่ายพัสดุ | ฝ่ายพัสดุ |
| `user20` | เจ้าหน้าที่กองแผนงาน | กองแผนงาน |
| `province10` | เจ้าหน้าที่ สกจ. | สำนักงานสหกรณ์จังหวัดพระนครศรีอยุธยา |

---

## ลำดับการเปิดระบบก่อนเดโม

ทำตามลำดับนี้ ห้ามข้าม

### 1. ตรวจว่า Oracle เปิดอยู่

```powershell
Get-Service OracleServiceORCL, OracleOraDB19Home1TNSListener
```

ถ้า `Stopped` ให้สั่ง `Start-Service` ทั้งสองตัว

ถ้าเจอ `ORA-01109: database not open` ตอนเปิดเว็บ แปลว่า PDB ไม่ได้เปิด สั่ง (ต้อง Run as Administrator):

```powershell
& "C:\OracleDB\bin\sqlplus.exe" -S "/ as sysdba" "@C:\Users\R\Desktop\gujajob-wepapp\database\oracle\open_pdb.sql"
```

> ปกติไม่ต้องทำแล้ว เพราะสั่ง `save state` ไว้ให้เปิดเองอัตโนมัติ

### 2. เปิดเว็บเซิร์ฟเวอร์

```powershell
cd C:\Users\R\Desktop\gujajob-wepapp
php artisan serve --host=127.0.0.1 --port=8000
```

เปิดค้างไว้ อย่าปิดหน้าต่างนี้

### 3. เปิด Cloudflare Tunnel (หน้าต่างใหม่)

```powershell
& "C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel --url http://127.0.0.1:8000
```

**URL จะเปลี่ยนทุกครั้งที่เปิดใหม่** มองหาบรรทัดที่ขึ้นว่า `https://xxxx.trycloudflare.com`

### 4. ตั้ง APP_URL ให้ตรงกับ URL ที่ได้

แก้ `.env` บรรทัด `APP_URL=` เป็น URL ที่เพิ่งได้ แล้วสั่ง

```powershell
php artisan config:clear
```

ถ้าไม่ทำขั้นนี้ ปุ่ม/ลิงก์บางหน้าจะเด้งกลับไป localhost แล้วเครื่องอาจารย์จะเปิดไม่ได้

---

## หน้าที่ทดสอบแล้วว่าใช้งานได้

| หน้า | เส้นทาง | ข้อมูลที่มี |
|---|---|---|
| MAT-001 จัดการวัสดุ | `/material/MAT-001-manage-material-items` | 101 รายการ |
| MAT-002 รับวัสดุเข้าคลัง | `/material/MAT-002-record-material-receiving` | 100 ใบรับ / 199 รายการ |
| MAT-006 ตั้งยอดคงเหลือ | `/material/MAT-006-record-balance-setting` | 199 ยอดคงเหลือ |
| MAT-007 พิมพ์รายงานวัสดุ | `/material/MAT-007-print-material-report` | PDF / Excel |
| **ASS-003 พยากรณ์งบประมาณ** | `/asset/ASS-003-manage-asset-registration/replacement-forecast` | **ครุภัณฑ์ 53 รายการ** |
| ASS-009 พิมพ์รายงานครุภัณฑ์ | `/asset/ASS-009-print-asset-report` | PDF / Excel |

### ตัวเลขที่ ASS-003 ควรได้ (ไว้เช็คว่าระบบปกติ)

กด **คำนวณ** โดยไม่เลือกตัวกรอง (1 ปี, เพิ่ม 5%) ต้องได้

- ครุภัณฑ์ที่คาดว่าจะทดแทน **7 รายการ**
- งบประมาณรวม **907,200.00 บาท** (ปี พ.ศ. 2570)

ถ้าได้ไม่ตรงนี้ แปลว่าข้อมูลหรือการตั้งค่าเพี้ยน

---

## เรื่องที่ต้องระวัง

- **URL เปลี่ยนทุกครั้งที่ปิด-เปิด tunnel** ถ้าจะส่ง URL ให้อาจารย์ล่วงหน้า ต้องเปิด tunnel ค้างไว้ห้ามปิด
- **เครื่องนี้ต้องเปิดตลอดตอนเดโม** ถ้าเครื่องดับ/เน็ตหลุด เว็บจะล่มทันที เพราะแอปกับฐานข้อมูลรันอยู่บนเครื่องนี้
- `APP_DEBUG=false` ตั้งไว้แล้ว อย่าเปิดเป็น `true` ขณะที่ tunnel เปิดอยู่ เพราะหน้า error จะโชว์รหัสผ่านฐานข้อมูล
- ปิด tunnel ทุกครั้งหลังเดโมจบ เพื่อไม่ให้เว็บค้างเปิดสู่สาธารณะ
- ดู error ได้ที่ `storage/logs/laravel.log`

---

## ข้อมูลที่ยังว่างอยู่

ตารางเหล่านี้ไม่มีข้อมูล เพราะ dump ที่กู้มาไม่มีข้อมูลส่วนนี้

- `MATERIAL_WITHDRAWN` / `MATERIAL_WITHDRAWN_LIST` (การเบิกวัสดุ MAT-003)
- `MATERIAL_INSPECTION` (การรับโอน MAT-004)
- `ASSET_SELLING` (การจำหน่ายครุภัณฑ์ ASS-006/007/008)
- `DEALER` (ผู้ประกอบการ)

ถ้าจะเดโมหน้าพวกนี้ ต้อง **สร้างรายการสดหน้าชั้น** หรือบอกให้เตรียม seed เพิ่ม

---

## การกู้ระบบ (ถ้าต้องตั้งใหม่ทั้งหมด)

ไฟล์ที่เกี่ยวข้องอยู่ใน `database/oracle/`

```
open_pdb.sql                 เปิด PDB (ใช้ sysdba)
reset_app_user_password.sql  รีเซ็ตรหัส user ASSET (ใช้ sysdba)
inspect_asset_user.sql       ตรวจว่ามี schema/ข้อมูลอะไรอยู่
post_import_gap_fill.sql     เติมตาราง/คอลัมน์ที่ dump ไม่มี
script_update_utf8.sql       patch ที่อาจารย์ให้มา (แปลงเป็น UTF-8 แล้ว)
script_update2_utf8.sql      patch ที่อาจารย์ให้มา (แปลงเป็น UTF-8 แล้ว)
```

ลำดับการกู้: `imp` dump → `mat003_withdraw_type_add.sql` → `script_update*_utf8.sql` → `post_import_gap_fill.sql` → seed จาก `database/scripts/`

คำสั่ง import (ต้องตั้ง `NLS_LANG` ให้ตรง ไม่งั้นภาษาไทยเพี้ยนทั้งฐาน)

```powershell
$env:NLS_LANG = "AMERICAN_AMERICA.TH8TISASCII"
& "C:\OracleDB\bin\imp.exe" asset/<รหัส>@localhost:1521/orclpdb `
    FILE=storage\app\oracle_import\asset.dmp FROMUSER=ASSET TOUSER=ASSET IGNORE=Y
```

> dump ต้นทางเป็น charset `TH8TISASCII` (Oracle 11.2) ส่วนฐานปลายทางเป็น `AL32UTF8`

**หมายเหตุ**: seed script ส่วนใหญ่ไม่มี `exit` ปิดท้าย ถ้ารันด้วย `@file` sqlplus จะค้างรอ input
ให้รันแบบต่อ exit ให้แทน เช่น ใน Git Bash:
`{ cat script.sql; echo; echo "exit"; } | sqlplus -S -L "$CONN"`
