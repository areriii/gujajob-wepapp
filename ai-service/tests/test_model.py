"""Unit tests for training, prediction and aggregation."""

from __future__ import annotations

import pytest

import app.services.replacement_budget_forecast as service
from app.services.replacement_budget_forecast import (
    BASIS_ASSET_PRICE_TREND,
    BASIS_CATEGORY_TREND,
    BASIS_UNAVAILABLE,
    aggregate_by_year,
    predict_replacement_costs,
    run_forecast,
    train_model,
    validate_training_data,
)

FIRST_YEAR = 2016
LAST_YEAR = 2025


def _history(prices: dict[int, float], growth: float = 0.04, copies: int = 2, price_scale: float = 1.0) -> list[dict]:
    records = []
    for category_id, price in prices.items():
        for year in range(FIRST_YEAR, LAST_YEAR + 1):
            for copy in range(copies):
                noise = 1 + 0.01 * (copy - 0.5)
                records.append(
                    {
                        "acquisition_year": year,
                        "category_id": category_id,
                        "category_name": f"หมวด {category_id}",
                        "acquisition_value": round(price * price_scale * (1 + growth) ** (year - FIRST_YEAR) * noise, 2),
                    }
                )
    return records


def _candidate(asset_id: int, category_id: int, forecast_year: int, **extra) -> dict:
    return {
        "asset_id": asset_id,
        "asset_code": f"T-{asset_id:03d}",
        "asset_name": None,
        "category_id": category_id,
        "category_name": f"หมวด {category_id}",
        "organization_id": 1,
        "organization_name": "หน่วยงานทดสอบ",
        "acceptance_date": None,
        "acquisition_year": 2020,
        "acquisition_value": 9999.0,
        "current_value": 1.0,
        "forecast_year": forecast_year,
        **extra,
    }


# ---------------------------------------------------------------------------
# Training data validation
# ---------------------------------------------------------------------------

def test_validate_passes_with_sufficient_data():
    valid, code, _ = validate_training_data(_history({1: 25000.0}))
    assert valid is True
    assert code == ""


def test_validate_rejects_too_few_records():
    valid, code, _ = validate_training_data(_history({1: 25000.0})[:3])
    assert valid is False
    assert code == "INSUFFICIENT_TRAINING_DATA"


def test_validate_rejects_records_unusable_after_cleaning():
    records = [dict(r, acquisition_value=0.0) for r in _history({1: 25000.0})]
    valid, code, _ = validate_training_data(records)
    assert valid is False
    assert code == "INSUFFICIENT_TRAINING_DATA"


def test_validate_fails_with_single_year():
    records = [
        {"acquisition_year": 2020, "category_id": 1, "category_name": "เครื่องคอมพิวเตอร์", "acquisition_value": 25000.0}
        for _ in range(15)
    ]
    valid, code, _ = validate_training_data(records)
    assert valid is False
    assert code == "INSUFFICIENT_YEAR_DIVERSITY"


# ---------------------------------------------------------------------------
# Training
# ---------------------------------------------------------------------------

def test_model_learns_the_annual_growth_rate():
    model = train_model(_history({1: 25000.0, 2: 3000.0}, growth=0.04))
    assert model.annual_growth_rate == pytest.approx(0.04, abs=0.005)


def test_final_model_is_fitted_on_every_record_including_latest_year():
    records = _history({1: 25000.0})
    # A category that only exists in the latest year must still be known to the final model.
    records += [
        {"acquisition_year": LAST_YEAR, "category_id": 7, "category_name": "หมวด 7", "acquisition_value": 7000.0},
        {"acquisition_year": LAST_YEAR, "category_id": 7, "category_name": "หมวด 7", "acquisition_value": 7100.0},
    ]
    model = train_model(records)
    assert model.training_records == len(records)
    assert "7" in model.known_categories


def test_holdout_evaluation_never_sees_the_test_year():
    records = _history({1: 25000.0, 2: 8000.0}, growth=0.0)
    # Prices triple in the latest year. A model that had seen those rows would score well.
    records = [
        dict(r, acquisition_value=r["acquisition_value"] * 3) if r["acquisition_year"] == LAST_YEAR else r
        for r in records
    ]
    model = train_model(records)
    assert model.evaluation_year == LAST_YEAR
    assert model.evaluation_records == 4
    assert model.mape > 50


def test_holdout_is_skipped_when_data_is_too_small():
    records = _history({1: 25000.0}, copies=1)[-3:] + _history({2: 8000.0}, copies=1)[-8:]
    model = train_model(records)
    assert model.evaluation_year is None
    assert model.mae is None and model.mape is None


# ---------------------------------------------------------------------------
# Prediction: never hard-coded, always derived from training data
# ---------------------------------------------------------------------------

def test_predictions_follow_category_price_levels():
    model = train_model(_history({1: 3000.0, 2: 30000.0}))
    cheap, expensive = predict_replacement_costs(model, [_candidate(1, 1, 2027), _candidate(2, 2, 2027)])
    ratio = expensive["predicted_replacement_cost"] / cheap["predicted_replacement_cost"]
    assert ratio == pytest.approx(10, rel=0.05)
    assert cheap["prediction_basis"] == BASIS_CATEGORY_TREND


def test_predictions_scale_with_training_prices():
    candidates = [_candidate(1, 1, 2027), _candidate(2, 2, 2028)]
    base = predict_replacement_costs(train_model(_history({1: 3000.0, 2: 30000.0})), candidates)
    doubled = predict_replacement_costs(
        train_model(_history({1: 3000.0, 2: 30000.0}, price_scale=2.0)), candidates
    )
    for original, scaled in zip(base, doubled):
        assert scaled["predicted_replacement_cost"] == pytest.approx(2 * original["predicted_replacement_cost"], rel=1e-4)


def test_prediction_grows_with_forecast_year():
    model = train_model(_history({1: 25000.0}, growth=0.05))
    year1, year3 = predict_replacement_costs(model, [_candidate(1, 1, 2027), _candidate(2, 1, 2029)])
    ratio = year3["predicted_replacement_cost"] / year1["predicted_replacement_cost"]
    assert ratio == pytest.approx((1 + model.annual_growth_rate) ** 2, rel=1e-3)
    assert ratio == pytest.approx(1.05 ** 2, rel=0.02)


def test_known_category_prediction_does_not_copy_asset_values():
    model = train_model(_history({1: 25000.0}))
    a, b = predict_replacement_costs(
        model,
        [
            _candidate(1, 1, 2027, acquisition_value=100.0, current_value=1.0),
            _candidate(2, 1, 2027, acquisition_value=900000.0, current_value=500000.0),
        ],
    )
    assert a["predicted_replacement_cost"] == b["predicted_replacement_cost"]
    assert a["predicted_replacement_cost"] not in (100.0, 900000.0, 1.0, 500000.0)


def test_unseen_category_uses_asset_price_and_learned_growth():
    model = train_model(_history({1: 25000.0}, growth=0.04))
    (asset,) = predict_replacement_costs(
        model, [_candidate(1, 999, 2027, acquisition_year=2020, acquisition_value=10000.0)]
    )
    expected = 10000.0 * (1 + model.annual_growth_rate) ** 7
    assert asset["prediction_basis"] == BASIS_ASSET_PRICE_TREND
    assert asset["predicted_replacement_cost"] == pytest.approx(expected, abs=0.01)


def test_unseen_category_without_price_is_unavailable_and_excluded_from_budget():
    result = run_forecast(
        forecast_years=1,
        base_year=2026,
        training_records=_history({1: 25000.0}),
        candidate_assets=[
            _candidate(1, 1, 2027),
            _candidate(2, 999, 2027, acquisition_value=None, acquisition_year=None),
        ],
    )
    assert result["success"] is True
    unavailable = next(a for a in result["assets"] if a["asset_code"] == "T-002")
    known = next(a for a in result["assets"] if a["asset_code"] == "T-001")
    assert unavailable["prediction_basis"] == BASIS_UNAVAILABLE
    assert unavailable["predicted_replacement_cost"] is None
    assert result["years"][0]["asset_count"] == 2
    assert result["years"][0]["forecast_budget"] == known["predicted_replacement_cost"]
    assert len(result["warnings"]) == 1


# ---------------------------------------------------------------------------
# Aggregation
# ---------------------------------------------------------------------------

def test_aggregate_lists_every_year_including_empty_years():
    assets = [
        {"forecast_year": 2027, "predicted_replacement_cost": 100.10},
        {"forecast_year": 2029, "predicted_replacement_cost": 50.05},
    ]
    years, total = aggregate_by_year(assets, base_year=2026, forecast_years=3)
    assert years == [
        {"year": 2027, "asset_count": 1, "forecast_budget": 100.10},
        {"year": 2028, "asset_count": 0, "forecast_budget": 0.0},
        {"year": 2029, "asset_count": 1, "forecast_budget": 50.05},
    ]
    assert total == 150.15


def test_total_equals_sum_of_yearly_budgets_with_cents():
    assets = [
        {"forecast_year": 2027 + i % 3, "predicted_replacement_cost": round(1234.567 + i * 0.37, 2)}
        for i in range(200)
    ]
    years, total = aggregate_by_year(assets, base_year=2026, forecast_years=3)
    assert total == round(sum(y["forecast_budget"] for y in years), 2)
    assert total == pytest.approx(sum(a["predicted_replacement_cost"] for a in assets), abs=0.01)


# ---------------------------------------------------------------------------
# run_forecast
# ---------------------------------------------------------------------------

def test_run_forecast_without_candidates_does_not_need_training_data():
    result = run_forecast(forecast_years=2, base_year=2030, training_records=[], candidate_assets=[])
    assert result["success"] is True
    assert result["years"] == [
        {"year": 2031, "asset_count": 0, "forecast_budget": 0.0},
        {"year": 2032, "asset_count": 0, "forecast_budget": 0.0},
    ]


def test_run_forecast_rejects_candidates_outside_period():
    result = run_forecast(
        forecast_years=1,
        base_year=2026,
        training_records=_history({1: 25000.0}),
        candidate_assets=[_candidate(1, 1, 2026)],
    )
    assert result["success"] is False
    assert result["code"] == "INVALID_CANDIDATE_YEAR"


def test_run_forecast_reports_prediction_failure(monkeypatch):
    def broken_prediction(_model, _candidates):
        raise ValueError("boom")

    monkeypatch.setattr(service, "predict_replacement_costs", broken_prediction)
    result = run_forecast(
        forecast_years=1,
        base_year=2026,
        training_records=_history({1: 25000.0}),
        candidate_assets=[_candidate(1, 1, 2027)],
    )
    assert result == {"success": False, "code": "MODEL_ERROR", "message": result["message"]}
    assert "boom" not in result["message"]
