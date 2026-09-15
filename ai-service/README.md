# GUJAJOB AI Forecast Service

บริการพยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน (ASS-003 จัดการทะเบียนครุภัณฑ์)

## หลักการ: ใครรับผิดชอบอะไร

```
Browser → Laravel → Oracle → Laravel เตรียมข้อมูล → FastAPI (ML) → Laravel → UI / รายงาน
```

| ขั้นตอน | ผู้รับผิดชอบ | ที่มา |
|---------|-------------|-------|
| ครุภัณฑ์ใดต้องทดแทน และทดแทนปีใด | **Laravel / ทะเบียนครุภัณฑ์** (ไม่ใช่ AI) | ปีที่ค่าเสื่อมราคาครบอายุ: เดือนเริ่มคิดค่าเสื่อม (จาก `inspect_date`) + `ass_lifetime` ปี − 1 วัน (`AssetDepreciationCalculator`) |
| มูลค่าทดแทนในปีนั้น | **AI (Ridge Regression)** | ราคาจัดซื้อย้อนหลังแยกตามหมวดและปี |
| รวมงบประมาณรายปี / รวมทั้งหมด | AI service | ผลรวมมูลค่าทดแทนที่พยากรณ์ |

หน้าจอ: `GET /asset/ASS-003-manage-asset-registration/replacement-forecast` (ปุ่ม "พยากรณ์งบประมาณทดแทน" ในหน้า ASS-003)
ตารางรายละเอียดแสดงหน้าละ 10 รายการ แต่ยอดรวมและยอดรายปีคำนวณจากครุภัณฑ์ที่ครบกำหนดทั้งหมด

ค่าเสื่อมราคาและมูลค่าคงเหลือคำนวณใน Laravel ด้วย `App\Services\AssetDepreciationCalculator` — สูตรเดียวกับทะเบียนคุมครุภัณฑ์ (ASS-009):
วิธีเส้นตรง ค่าเสื่อมราคาต่อปี = `ass_price / ass_lifetime` คิดตามงวดปีงบประมาณ (1 ต.ค. – 30 ก.ย.) เริ่มต้นเดือนที่ตรวจรับ
(`inspect_date` หลังวันที่ 15 เริ่มเดือนถัดไป) คงเหลือ 1 บาทเมื่อครบอายุ งวดที่ยังไม่สิ้นปีงบประมาณคิดตามจำนวนวันถึงวันที่คำนวณ
ปีที่ครบกำหนดทดแทน = ปีของวันสิ้นอายุ (`AssetDepreciationCalculator::usefulLifeEndSql()`) Laravel แนบค่าเหล่านี้ (`accumulated_depreciation`, `current_value` ฯลฯ)
ให้ผลลัพธ์หลังได้คำตอบจาก AI — **AI service ไม่ใช้ค่าเสื่อมราคาหรือมูลค่าคงเหลือเป็น feature**

- **มูลค่าคงเหลือ** = มูลค่าตามบัญชี ณ วันที่คำนวณ (มูลค่า − ค่าเสื่อมราคาสะสม)
- **มูลค่าทดแทน (AI)** = ราคาจัดซื้อครุภัณฑ์ใหม่ `category_id` (ชื่อครุภัณฑ์) เดียวกันที่คาดการณ์ในปีที่ครบกำหนดทดแทน

- ช่วงพยากรณ์ N ปี = ปีปัจจุบัน + 1 ถึง ปีปัจจุบัน + N (Laravel ส่ง `base_year` จากวันที่ปัจจุบัน ไม่มีการกำหนดปีตายตัว)
- เงื่อนไขเลือกครุภัณฑ์ (ปัจจุบัน, อยู่ใน `App\Services\ReplacementForecastDataService`):
  `inspect_date` ไม่ว่าง, `ass_lifetime > 0`, `ass_status != '3'`, อยู่ในหน่วยงานที่ผู้ใช้มีสิทธิ์เห็น และปีครบกำหนดอยู่ในช่วงพยากรณ์
- ตัวกรอง "หมวดครุภัณฑ์" = `TRIM(ASSET_CATEGORY.asscat_group)` (ตามป้ายชื่อใน ASS-001)
  ส่วน `asscat_name` คือ "ชื่อครุภัณฑ์" ไม่ใช่หมวด ไม่มีตาราง/รหัสหมวดแยก จึงใช้ชื่อหมวดเป็นค่าที่ส่ง (`filter_cat_group`)
- ข้อมูลฝึกสอน: ครุภัณฑ์ทุกรายการในหน่วยงานที่มีสิทธิ์เห็นที่มี `inspect_date` และ `ass_price > 0`
  (ตัวกรองหมวด/หน่วยงานบนหน้าจอใช้กรองเฉพาะครุภัณฑ์ที่จะทดแทน ไม่ได้กรองข้อมูลฝึกสอน)

---

## โมเดล

| รายการ | รายละเอียด |
|--------|-----------|
| Algorithm | Ridge Regression (scikit-learn Pipeline), `alpha = 0.1` |
| Features | `acquisition_year` (ตัวเลข), `category_id` (One-Hot) |
| Target | `log(acquisition_value)` — log ของราคาจัดซื้อ (`ASSET.ass_price`) |
| Prediction | มูลค่าทดแทน = `exp(model(forecast_year, category_id))` |
| Evaluation | Time-based hold-out: ฝึกด้วยปีก่อนหน้า ทดสอบกับปีล่าสุด (ไม่ใช้ข้อมูลปีทดสอบในการฝึก) — MAE, MAPE |
| โมเดลที่ใช้พยากรณ์จริง | ฝึกใหม่ด้วยข้อมูลทั้งหมด รวมปีล่าสุด หลังประเมินผลแล้ว |

**ทำไมใช้ log(price):** ราคาครุภัณฑ์เปลี่ยนแปลงเป็น *อัตราร้อยละ* ไม่ใช่จำนวนบาทเท่ากันทุกหมวด
การใช้ log ทำให้สัมประสิทธิ์ของปีคือ "อัตราการเปลี่ยนแปลงราคาเฉลี่ยต่อปี" (แสดงใน `model.annual_price_growth_pct`)
และผลพยากรณ์ไม่ติดลบ ในการทดสอบย้อนหลังกับข้อมูลตัวอย่าง ความคลาดเคลื่อน (MAPE) ลดลงจากประมาณ 20–34% (ราคาตรง) เหลือประมาณ 1.5–9%
ตัวเลขนี้มาจากข้อมูลสังเคราะห์ ต้องประเมินใหม่กับข้อมูลจริง

**Organization** ไม่ใช่ feature — ใช้เป็นตัวกรองของผู้ใช้เท่านั้น

### กรณีพิเศษ (`prediction_basis`)

| ค่า | ความหมาย |
|-----|---------|
| `category_trend` | ปกติ: พยากรณ์จากแนวโน้มราคาของหมวด |
| `asset_price_trend` | หมวดไม่มีข้อมูลราคาย้อนหลัง: มูลค่า (`ass_price`) ของครุภัณฑ์ × (1 + อัตราเฉลี่ย)^(ปีทดแทน − ปีที่ได้มา) พร้อมคำเตือน |
| `unavailable` | ไม่มีทั้งข้อมูลหมวดและมูลค่าของครุภัณฑ์: ไม่รวมในงบประมาณ พร้อมคำเตือน |

**มูลค่า / มูลค่าคงเหลือไม่ใช่ feature ของโมเดล:** `acquisition_value` เป็น *target* ของข้อมูลฝึกสอน (ใช้เป็น feature ด้วยจะรั่วคำตอบ)
และไม่มีข้อมูลคู่ "ครุภัณฑ์เดิม → ครุภัณฑ์ที่ซื้อทดแทน" ให้เรียนรู้ ส่วนมูลค่าคงเหลือ/ค่าเสื่อมราคาสะสมเป็นการปันส่วนต้นทุนทางบัญชี
(ฟังก์ชันของมูลค่า วันที่ และอายุการใช้งาน) และในปีที่ครบกำหนดทดแทนมีค่า 1 บาทเสมอ จึงไม่มีข้อมูลเรื่องราคาตลาดในอนาคต
ครุภัณฑ์ `category_id` และปีเดียวกันจึงได้มูลค่าทดแทนเท่ากัน

---

## ติดตั้งและเปิดใช้งาน (Windows)

ใช้ virtual environment เดิมที่ `ai-service\.venv` (Python 3.12)

```powershell
cd ai-service
.venv\Scripts\python.exe -m pip install -r requirements.txt
.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001 --reload
```

macOS / Linux: ใช้ `.venv/bin/python` แทน

ทดสอบ: `GET http://127.0.0.1:8001/health` → `{"status": "ok"}`

---

## Environment Variables (Laravel `.env`)

```env
AI_SERVICE_URL=http://127.0.0.1:8001
AI_SERVICE_TIMEOUT=30
AI_FORECAST_DEMO=false
```

`AI_FORECAST_DEMO=true` จะใช้ `data/demo_training.csv` เป็นข้อมูลฝึกสอน **เฉพาะเมื่อ Oracle ไม่มีข้อมูลราคาย้อนหลัง**
ผลลัพธ์จะถูกระบุว่าเป็น DEMO ทั้งบนหน้าจอและในรายงาน ห้ามใช้ในการตั้งงบประมาณจริง

---

## API

### `GET /health`
```json
{"status": "ok"}
```

### `POST /forecast/replacement-budget`

**Request**
```json
{
  "forecast_years": 3,
  "base_year": 2026,
  "training_records": [
    {"acquisition_year": 2020, "category_id": 1, "category_name": "เครื่องคอมพิวเตอร์", "acquisition_value": 26500}
  ],
  "candidate_assets": [
    {
      "asset_id": 1, "asset_code": "ASS-0001", "asset_name": "เครื่องคอมพิวเตอร์โน้ตบุ๊ก",
      "category_id": 1, "category_name": "เครื่องคอมพิวเตอร์",
      "organization_id": 10, "organization_name": "สำนักงานกลาง",
      "acceptance_date": "15/01/2563", "acquisition_year": 2020,
      "acquisition_value": 26500.00, "current_value": 5000.00,
      "forecast_year": 2027
    }
  ]
}
```

`forecast_year` ของทุกรายการต้องอยู่ระหว่าง `base_year + 1` ถึง `base_year + forecast_years`

**Response (200)** — ย่อ
```json
{
  "success": true,
  "forecast_years": 3, "base_year": 2026, "start_year": 2027, "end_year": 2029,
  "total_assets": 1,
  "total_forecast_budget": 27830.12,
  "years": [
    {"year": 2027, "asset_count": 1, "forecast_budget": 27830.12},
    {"year": 2028, "asset_count": 0, "forecast_budget": 0.0},
    {"year": 2029, "asset_count": 0, "forecast_budget": 0.0}
  ],
  "assets": [{"asset_code": "ASS-0001", "forecast_year": 2027, "predicted_replacement_cost": 27830.12, "prediction_basis": "category_trend", "...": "..."}],
  "warnings": [],
  "model": {
    "name": "Ridge Regression", "features": ["acquisition_year", "category_id"], "target": "log(acquisition_value)",
    "alpha": 0.1, "training_records": 120, "annual_price_growth_pct": 3.4,
    "evaluation_year": 2025, "evaluation_records": 14, "mae": 812.4, "mape": 4.1
  }
}
```

`years` แสดงครบทุกปีในช่วงพยากรณ์ (ปีที่ไม่มีครุภัณฑ์ = 0) และ `total_forecast_budget` = ผลรวมของ `years[].forecast_budget`

**Errors** — `{"success": false, "code": "...", "message": "..."}` (ไม่มี stack trace / รายละเอียด Python)

| HTTP | code | สาเหตุ |
|------|------|--------|
| 422 | `INVALID_FORECAST_YEARS` | `forecast_years` ไม่ใช่ 1–3 |
| 422 | `INVALID_REQUEST` | ข้อมูลไม่ครบ/ผิดรูปแบบ |
| 422 | `INVALID_CANDIDATE_YEAR` | ปีทดแทนอยู่นอกช่วงพยากรณ์ |
| 422 | `INSUFFICIENT_TRAINING_DATA` | ข้อมูลฝึกสอนน้อยกว่า 10 รายการ |
| 422 | `INSUFFICIENT_YEAR_DIVERSITY` | ข้อมูลฝึกสอนมีเพียงปีเดียว |
| 500 | `MODEL_ERROR` | ฝึก/พยากรณ์ไม่สำเร็จ |

Laravel (`App\Services\ReplacementBudgetForecastService`) แปลงผลเป็นข้อความสำหรับผู้ใช้อีกชั้น
และเพิ่มกรณี `AI_SERVICE_UNAVAILABLE` (503), `AI_SERVICE_TIMEOUT` (504), `AI_SERVICE_ERROR` (502), `DATABASE_UNAVAILABLE` (503)

---

## การทดสอบ

```powershell
# Python (ไม่ต้องใช้ Oracle)
cd ai-service
.venv\Scripts\python.exe -m pytest tests/ -v

# Laravel (Oracle และ FastAPI ถูก mock)
cd ..
php artisan test tests/Feature/AssetForecast
```

| ไฟล์ | ครอบคลุม |
|------|---------|
| `tests/test_forecast.py` | API: health, 1/2/3 ปี, horizon ไม่ถูกต้อง, validation, error handling |
| `tests/test_model.py` | การฝึก, hold-out ไม่รั่วข้อมูล, ผลพยากรณ์มาจากข้อมูล (ไม่ hard-code), การรวมรายปี |
| `tests/test_offline_fixture.py` | End-to-end บน fixture: ครุภัณฑ์ปีที่ 1/2/3, รายการที่ไม่ใช่ candidate, ตัวกรองหมวด/หน่วยงาน |
| `tests/Feature/AssetForecast/*` (Laravel) | Controller ↔ FastAPI (mock), Oracle ล่ม, AI ล่ม/timeout, รายงานพิมพ์ |

### ข้อมูลทดสอบแบบออฟไลน์

`tests/fixtures/offline_assets.json` — **ใช้ทดสอบเท่านั้น ห้ามนำเข้า Oracle**
ชื่อฟิลด์ตรงกับคอลัมน์ `ASSET` / `ASSET_CATEGORY` / `GLB_ORGANIZATION` รหัสทั้งหมดขึ้นต้นด้วย `FX-`
และแต่ละรายการระบุ `expected_replacement_offset` (ปีที่ต้องถูกเลือก) พร้อมเหตุผล
`tests/offline_fixture.py` จำลองเงื่อนไขของ query ใน Laravel — ต้องตรวจสอบกับ Oracle จริงอีกครั้ง

`data/demo_training.csv` — ข้อมูลราคาตัวอย่างสำหรับ `AI_FORECAST_DEMO=true` เท่านั้น
