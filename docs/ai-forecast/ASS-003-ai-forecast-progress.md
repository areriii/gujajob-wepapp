# ASS-003 AI Replacement-Budget Forecast — Progress Note

Feature: "พยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน" in ASS-003, plus demo data for ASS-004 / ASS-005.
Last updated: 2026-09-15. Status: **working end to end on dev Oracle + local FastAPI; committed to `main` (commit "feat: enhance AI replacement budget forecasting").**
Local `main` was fast-forwarded to `origin/main` `a138840` (stash → ff → stash pop, no conflicts) to use `AssetDepreciationCalculator`.

> No credentials or `.env` values here. Never commit/push, write to Oracle, run `--apply`, or run a cleanup script without the user's explicit approval.

---

## 1. Architecture

```
Browser (forecast.js)
  → POST /asset/ASS-003-manage-asset-registration/ai-forecast   (AssetController::aiForecastBudget)
      → Oracle via ReplacementForecastDataService
          candidates (SQL end-of-life rule from AssetDepreciationCalculator::usefulLifeEndSql)
          + value per asset as of today (AssetDepreciationCalculator::valueAsOf, from inspect_date)
          training records (year, category_id, price)
      → FastAPI POST /forecast/replacement-budget (ReplacementBudgetForecastService) — prices candidates only
      ← Laravel re-attaches depreciation fields (withDepreciation) + result.depreciation {method, residual_value, as_of_date}
  ← JSON (also stored in session for print)
GET /asset/ASS-003-manage-asset-registration/forecast-print  (AssetController::forecastPrint)
```

- Laravel decides **which assets / which year / accounting value**. FastAPI decides **replacement cost**.
- AI config: `config/services.php` → `ai_forecast` (env keys `AI_SERVICE_URL`, `AI_SERVICE_TIMEOUT`, `AI_FORECAST_DEMO`; code defaults `http://127.0.0.1:8001`, 30 s, demo off).
- Start FastAPI: `cd ai-service` → `.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001`.
- Routes: `replacement-forecast` (page), `ai-forecast` (POST), `forecast-print`.
- Errors: `INVALID_*` 422, `DATABASE_UNAVAILABLE` 503, `AI_SERVICE_UNAVAILABLE` 503, `AI_SERVICE_TIMEOUT` 504, `INSUFFICIENT_*` 422, `AI_SERVICE_ERROR` 502.

---

## 2. Depreciation — single source of truth: `app/Services/AssetDepreciationCalculator.php`

Existing project logic (commit `a138840`, used by the ASS-009 asset control register):
- Straight line; annual = `ass_price / ass_lifetime`; posted per Thai fiscal year (1 Oct – 30 Sep) by month.
- Depreciation starts on the 1st of the acceptance month if the day ≤ 15, otherwise on the 1st of the next month.
- Stops when net value = 1 baht (residual).

Extended in this task (no new formula):
- `calculate(..., ?CarbonInterface $asOf = null)` — optional cut-off; the unfinished fiscal year is depreciated by day (the class's own day rule). Default path unchanged for the register.
- `valueAsOf(cost, life, startDate, asOf)` → annual, accumulated, remaining, depreciation start, useful-life end date, replacement year.
- `remainingValue()` (for `remain_price`), `depreciationStartDate()`, `usefulLifeEndDate()` (= start + life years − 1 day), `usefulLifeEndSql()` (Oracle equivalent), constants `RESIDUAL_VALUE`, `METHOD`.

Used by: forecast candidates/values, `AssetController::store()`/`update()` (`remain_price`), `php artisan asset:recalculate-remain-price [--as-of] [--id=*] [--apply]` (dry run by default).

**Start date difference (open):** the ASS-009 register passes `ASSET.created_at` as start date; the forecast, remain_price and the command use `inspect_date` (วันที่ตรวจรับ).

Candidate rule: `inspect_date` not null, `ass_lifetime > 0`, `ass_status != '3'`, visible org, year of useful-life end in [base+1, base+N], base = `now()->year`.

---

## 3. ML model (unchanged)

Ridge Regression (`alpha 0.1`), features `acquisition_year` + `category_id` (one-hot = ASSET_CATEGORY.id / ชื่อครุภัณฑ์), target `log(ass_price)`, prediction `exp(pipeline.predict(X))` in `_predict_prices()` with `X = (forecast_year, category_id)`.
- Not features: original value (training target → leakage; no old→replacement pairs), `remain_price`/accumulated depreciation (accounting allocation, always 1 baht at the replacement year).
- Same category + year ⇒ same มูลค่าทดแทน (AI). Decision: keep the model.
- Data 2026-09-14: 62 training records, 2016–2026, growth 2.39 %/yr, MAPE 1.91 % (hold-out 2026, 6 records).

---

## 4. UI (implemented)

- Separate forecast page; red "ย้อนกลับ"; system blue; dropdowns not clipped; 10 rows per page with totals over all rows.
- Sorting on every header of the detail table (ลำดับ … มูลค่าทดแทน (AI), 11 columns) and the yearly table (ปี, จำนวน, งบประมาณ): same markup/icons/tooltips/aria as `components/sortable-th.blade.php` + `components/sort-icon.css`; client-side `<button class="sort-link">`; full list sorted before pagination; empty values last; totals row fixed.
- "ราคาทุนเดิม" → "มูลค่า" (page, print, notes, Python warning text). New column ค่าเสื่อมราคาสะสม. Definitions of มูลค่าคงเหลือ vs มูลค่าทดแทน (AI) on page and print.

---

## 5. Oracle development data (real rows, synthetic content)

1. **AI-TEST**: ASSET 81–120 (`AI-TEST-001..040`), `database/scripts/ai_forecast_test_data*.sql` (cleanup not run). Stored remain_price still = price.
2. **Allocation demo**: `database/test-data/asset_allocation_demo_data.sql` (executed), cleanup `..._cleanup.sql` (**not run**).
   - ASSET 121–133 `DEMO-ASSET-001..013` (REMARKS `[ALLOCATION DEMO DATA]`), org 382 ฝ่ายพัสดุ (child of 1310 กองคลัง); remain_price aligned with the calculator as of 2026-09-14 (UPDATE limited to these 13 rows).
   - ASSET_ASSIGNMENT 11–17 (REMARK marker), ASSET_ASSIGNMENT_LIST 14–20.
   - ASS-004 selectable: 001–007 (007's assignment 11 is cancelled). ASS-005 waiting: 008→1310, 009→378, 010→1312, 011→1310. Received: 012 (→1310, sub-org 381, status 2), 013 (→378, sub-org 383, net 1 baht, status 3).
   - Not used: duplicate names กองคลัง 4524 / ฝ่ายพัสดุ 4534.
- Test login: SYS_USER 1 (org 382, zone C).

---

## 6. Tests (2026-09-14)

- Python `ai-service`: 70 passed.
- Laravel `php artisan test`: 72/73 — only pre-existing `tests/Feature/ExampleTest` (302) fails.
  New: `tests/Unit/AssetDepreciationCalculatorTest.php`, `tests/Feature/AssetDepreciation/RecalculateRemainPriceCommandTest.php`; updated `AiForecastBudgetTest`, `AssetCategoryGroupTest`.
- Live E2E as user 1 (real Oracle + FastAPI): 1/2/3 years × all / category / org / category+org, all 200; totals = sum of years = sum of assets; per-asset depreciation equals `valueAsOf`; 3-year all/all 22 assets, 4,972,032.88 (2570: 7 / 2571: 8 / 2572: 7).
- ASS-004/005 pages requested through the HTTP kernel as user 1 show the demo rows above.
- Sorting verified with the real `forecast.js` + CSS on the Vite dev server (mocked response); the authenticated page needs a manual check.

---

## 7. Open issues / decisions

1. Depreciation start date: register uses `created_at`, forecast/remain_price use `inspect_date` — choose one.
2. Stored `remain_price` is stale for 46 older assets (dry run); `--apply` needs approval; status 3 is not set automatically when value reaches 1.
3. Candidate rule excludes only status 3 (4/5/6 can be candidates); assets already past end of life are not candidates.
4. Calendar year used for the replacement year while depreciation uses fiscal-year periods.
5. 1-baht placeholder prices in training; sparse categories; local demo flag on.
6. ASS-005 list is paginated — search "DEMO-ASSET". Receiving one asset marks its whole assignment received (existing behaviour).

---

## 8. Next steps

1. Manual UI review in the logged-in app (forecast width/sorting/labels, ASS-004/005 demo rows).
2. Decide items 1–4 above.
3. Cleanup scripts only when asked.
4. Re-run pytest, `php artisan test`, one live forecast after any change.
