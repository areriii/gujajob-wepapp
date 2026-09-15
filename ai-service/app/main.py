from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.schemas import ForecastRequest
from app.services.replacement_budget_forecast import run_forecast

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):  # noqa: ARG001
    yield


app = FastAPI(
    title="GUJAJOB AI Forecast Service",
    version="1.1.0",
    lifespan=lifespan,
)


def _error(code: str, message: str, status_code: int) -> JSONResponse:
    return JSONResponse({"success": False, "code": code, "message": message}, status_code=status_code)


@app.exception_handler(RequestValidationError)
async def request_validation_handler(request: Request, exc: RequestValidationError):  # noqa: ARG001
    locations = [".".join(str(part) for part in err.get("loc", ())) for err in exc.errors()]
    logger.warning("Invalid forecast request fields: %s", locations[:20])

    if any("forecast_years" in loc for loc in locations):
        return _error("INVALID_FORECAST_YEARS", "ระยะเวลาพยากรณ์ต้องเป็น 1, 2 หรือ 3 ปี", 422)
    return _error("INVALID_REQUEST", "ข้อมูลคำขอพยากรณ์ไม่ถูกต้องหรือไม่ครบถ้วน", 422)


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):  # noqa: ARG001
    logger.error("Unhandled error", exc_info=exc)
    return _error("MODEL_ERROR", "เกิดข้อผิดพลาดในการพยากรณ์", 500)


@app.get("/health")
def health():
    return {"status": "ok"}


# Sync handler: model training is CPU-bound, so FastAPI runs it in a worker thread.
@app.post("/forecast/replacement-budget")
def forecast_replacement_budget(payload: ForecastRequest):
    try:
        result = run_forecast(
            forecast_years=payload.forecast_years,
            base_year=payload.base_year,
            training_records=[r.model_dump() for r in payload.training_records],
            candidate_assets=[a.model_dump() for a in payload.candidate_assets],
        )
    except Exception:
        logger.exception("Unexpected forecast error")
        return _error("MODEL_ERROR", "เกิดข้อผิดพลาดในการพยากรณ์", 500)

    if not result.get("success"):
        status_code = 500 if result.get("code") == "MODEL_ERROR" else 422
        return JSONResponse(result, status_code=status_code)

    return result
