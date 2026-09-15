"""API tests for the FastAPI forecast service."""

from __future__ import annotations

from datetime import date

import pytest
from fastapi.testclient import TestClient

import app.main as main_module
from app.main import app

client = TestClient(app)

ENDPOINT = "/forecast/replacement-budget"
BASE_YEAR = date.today().year

CATEGORIES = {
    1: ("เครื่องคอมพิวเตอร์", 25000.0),
    2: ("เครื่องพิมพ์", 8000.0),
    3: ("โต๊ะทำงาน", 5000.0),
    4: ("เก้าอี้", 3000.0),
    5: ("เครื่องปรับอากาศ", 35000.0),
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_training_records(n: int = 30, years: int = 8) -> list[dict]:
    """Synthetic price history spanning several years and categories."""
    records = []
    for i in range(n):
        category_id = i % len(CATEGORIES) + 1
        name, price = CATEGORIES[category_id]
        year_index = i % years
        records.append(
            {
                "acquisition_year": BASE_YEAR - years + year_index,
                "category_id": category_id,
                "category_name": name,
                "acquisition_value": round(price * 1.03 ** year_index, 2),
            }
        )
    return records


def _make_candidate(asset_id: int, category_id: int, forecast_year: int) -> dict:
    name, price = CATEGORIES[category_id]
    return {
        "asset_id": asset_id,
        "asset_code": f"TEST-{asset_id:04d}",
        "asset_name": f"ครุภัณฑ์ทดสอบ {asset_id}",
        "category_id": category_id,
        "category_name": name,
        "organization_id": 1,
        "organization_name": "หน่วยงานทดสอบ",
        "acceptance_date": "01/01/2563",
        "acquisition_year": 2020,
        "acquisition_value": price,
        "current_value": 1.0,
        "forecast_year": forecast_year,
    }


def _candidates_for_horizon(forecast_years: int) -> list[dict]:
    """Two candidates in every year of the horizon."""
    candidates = []
    for offset in range(1, forecast_years + 1):
        for category_id in (1, 4):
            candidates.append(_make_candidate(len(candidates) + 1, category_id, BASE_YEAR + offset))
    return candidates


def _payload(forecast_years: int = 1, **overrides) -> dict:
    payload = {
        "forecast_years": forecast_years,
        "base_year": BASE_YEAR,
        "training_records": _make_training_records(30),
        "candidate_assets": _candidates_for_horizon(forecast_years),
    }
    payload.update(overrides)
    return payload


# ---------------------------------------------------------------------------
# Health
# ---------------------------------------------------------------------------

def test_health():
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


# ---------------------------------------------------------------------------
# Forecast horizons
# ---------------------------------------------------------------------------

@pytest.mark.parametrize("years", [1, 2, 3])
def test_valid_forecast_years(years: int):
    response = client.post(ENDPOINT, json=_payload(years))
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert data["forecast_years"] == years
    assert data["start_year"] == BASE_YEAR + 1
    assert data["end_year"] == BASE_YEAR + years
    assert [y["year"] for y in data["years"]] == [BASE_YEAR + i for i in range(1, years + 1)]
    assert [y["asset_count"] for y in data["years"]] == [2] * years
    assert data["total_assets"] == 2 * years


@pytest.mark.parametrize("invalid_years", [0, 4, -1, 10, "abc", None])
def test_invalid_forecast_years(invalid_years):
    payload = _payload(1)
    payload["forecast_years"] = invalid_years
    response = client.post(ENDPOINT, json=payload)
    assert response.status_code == 422
    data = response.json()
    assert data["success"] is False
    assert data["code"] == "INVALID_FORECAST_YEARS"


# ---------------------------------------------------------------------------
# Request validation / error handling
# ---------------------------------------------------------------------------

def test_missing_base_year_is_rejected():
    payload = _payload(1)
    del payload["base_year"]
    response = client.post(ENDPOINT, json=payload)
    assert response.status_code == 422
    assert response.json()["code"] == "INVALID_REQUEST"


def test_missing_candidate_field_is_rejected_without_internal_details():
    payload = _payload(1)
    del payload["candidate_assets"][0]["category_id"]
    response = client.post(ENDPOINT, json=payload)
    assert response.status_code == 422
    data = response.json()
    assert data == {
        "success": False,
        "code": "INVALID_REQUEST",
        "message": data["message"],
    }
    assert "detail" not in data
    assert "category_id" not in data["message"]


def test_malformed_json_body_is_rejected():
    response = client.post(ENDPOINT, content=b"{not json", headers={"Content-Type": "application/json"})
    assert response.status_code == 422
    assert response.json()["code"] == "INVALID_REQUEST"


def test_candidate_outside_forecast_period_is_rejected():
    candidates = [_make_candidate(1, 1, BASE_YEAR + 2)]
    response = client.post(ENDPOINT, json=_payload(1, candidate_assets=candidates))
    assert response.status_code == 422
    assert response.json()["code"] == "INVALID_CANDIDATE_YEAR"


def test_unexpected_error_returns_generic_500(monkeypatch):
    def explode(**_kwargs):
        raise RuntimeError("secret internal traceback detail")

    monkeypatch.setattr(main_module, "run_forecast", explode)
    response = client.post(ENDPOINT, json=_payload(1))
    assert response.status_code == 500
    data = response.json()
    assert data["success"] is False
    assert data["code"] == "MODEL_ERROR"
    assert "secret" not in response.text
    assert "Traceback" not in response.text


def test_model_training_failure_returns_model_error(monkeypatch):
    import app.services.replacement_budget_forecast as service

    def broken_training(_records):
        raise ValueError("singular matrix")

    monkeypatch.setattr(service, "train_model", broken_training)
    response = client.post(ENDPOINT, json=_payload(2))
    assert response.status_code == 500
    assert response.json()["code"] == "MODEL_ERROR"
    assert "singular" not in response.text


# ---------------------------------------------------------------------------
# Training data checks
# ---------------------------------------------------------------------------

def test_insufficient_training_data():
    response = client.post(ENDPOINT, json=_payload(1, training_records=_make_training_records(n=3)))
    assert response.status_code == 422
    data = response.json()
    assert data["success"] is False
    assert data["code"] == "INSUFFICIENT_TRAINING_DATA"


def test_training_data_from_a_single_year_is_rejected():
    records = [dict(r, acquisition_year=BASE_YEAR - 1) for r in _make_training_records(20)]
    response = client.post(ENDPOINT, json=_payload(1, training_records=records))
    assert response.status_code == 422
    assert response.json()["code"] == "INSUFFICIENT_YEAR_DIVERSITY"


# ---------------------------------------------------------------------------
# Results
# ---------------------------------------------------------------------------

@pytest.mark.parametrize("years", [1, 2, 3])
def test_empty_candidate_assets(years: int):
    response = client.post(ENDPOINT, json=_payload(years, candidate_assets=[], training_records=[]))
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert data["total_assets"] == 0
    assert data["total_forecast_budget"] == 0.0
    assert data["assets"] == []
    assert data["years"] == [
        {"year": BASE_YEAR + i, "asset_count": 0, "forecast_budget": 0.0} for i in range(1, years + 1)
    ]


def test_successful_prediction():
    payload = _payload(3)
    response = client.post(ENDPOINT, json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert data["total_assets"] == len(payload["candidate_assets"])
    assert len(data["assets"]) == len(payload["candidate_assets"])
    for asset in data["assets"]:
        assert asset["predicted_replacement_cost"] > 0
        assert asset["prediction_basis"] == "category_trend"

    model = data["model"]
    assert model["name"] == "Ridge Regression"
    assert model["features"] == ["acquisition_year", "category_id"]
    assert model["target"] == "log(acquisition_value)"
    assert model["training_records"] == len(payload["training_records"])
    assert model["annual_price_growth_pct"] is not None


def test_total_equals_sum_of_years():
    response = client.post(ENDPOINT, json=_payload(3))
    assert response.status_code == 200
    data = response.json()

    years_total = round(sum(y["forecast_budget"] for y in data["years"]), 2)
    assert data["total_forecast_budget"] == years_total

    assets_total = sum(a["predicted_replacement_cost"] for a in data["assets"])
    assert abs(data["total_forecast_budget"] - assets_total) < 0.01
