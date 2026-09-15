"""Replacement budget forecast.

Division of responsibility
--------------------------
* Laravel decides WHICH assets need replacement and WHEN, using the asset registry
  (inspect_date + ass_lifetime). This module never changes that decision.
* This module learns how asset prices change over time and predicts what a
  replacement will cost in the year each asset reaches the end of its useful life.

Model
-----
Ridge Regression on log(price) with two features:

* ``acquisition_year`` (numeric)  -> one shared annual price growth rate
* ``category_id``      (one-hot)  -> the price level of each asset category

Predicted replacement cost = exp(model(forecast_year, category)).

Fitting log(price) turns the year coefficient into a percentage growth rate, so a
3,000-baht chair and a 35,000-baht air conditioner grow by the same *rate* rather
than by the same number of baht.
"""

from __future__ import annotations

import logging
import math
from dataclasses import dataclass
from typing import Optional

import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.linear_model import Ridge
from sklearn.metrics import mean_absolute_error
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder

logger = logging.getLogger(__name__)

MIN_TRAINING_RECORDS = 10
MIN_DISTINCT_YEARS = 2
RIDGE_ALPHA = 0.1

# Hold-out evaluation needs at least this many rows on each side of the split.
MIN_EVALUATION_TRAIN_RECORDS = 5
MIN_EVALUATION_TEST_RECORDS = 3

MODEL_NAME = "Ridge Regression"
FEATURES = ["acquisition_year", "category_id"]
TARGET = "log(acquisition_value)"

# How each asset's replacement cost was obtained.
BASIS_CATEGORY_TREND = "category_trend"
BASIS_ASSET_PRICE_TREND = "asset_price_trend"
BASIS_UNAVAILABLE = "unavailable"


@dataclass
class TrainedModel:
    pipeline: Pipeline
    known_categories: set[str]
    annual_growth_rate: float
    training_records: int
    evaluation_year: Optional[int]
    evaluation_records: int
    mae: Optional[float]
    mape: Optional[float]


def _clean_training_df(records: list[dict]) -> pd.DataFrame:
    df = pd.DataFrame(
        records,
        columns=["acquisition_year", "category_id", "category_name", "acquisition_value"],
    )
    df = df.dropna(subset=["acquisition_year", "category_id", "acquisition_value"])
    df = df[df["acquisition_value"].astype(float) > 0].copy()
    df["acquisition_year"] = df["acquisition_year"].astype(int)
    df["acquisition_value"] = df["acquisition_value"].astype(float)
    df["category_key"] = df["category_id"].astype(int).astype(str)
    return df.reset_index(drop=True)


def validate_training_data(records: list[dict]) -> tuple[bool, str, str]:
    """Return (is_valid, error_code, error_message)."""
    if len(records) < MIN_TRAINING_RECORDS:
        return (
            False,
            "INSUFFICIENT_TRAINING_DATA",
            f"ข้อมูลย้อนหลังไม่เพียงพอสำหรับการพยากรณ์ (พบ {len(records)} รายการ ต้องการอย่างน้อย {MIN_TRAINING_RECORDS})",
        )

    df = _clean_training_df(records)

    if len(df) < MIN_TRAINING_RECORDS:
        return (
            False,
            "INSUFFICIENT_TRAINING_DATA",
            f"ข้อมูลย้อนหลังที่ใช้ได้ไม่เพียงพอ (ใช้ได้ {len(df)} รายการ ต้องการอย่างน้อย {MIN_TRAINING_RECORDS})",
        )

    if df["acquisition_year"].nunique() < MIN_DISTINCT_YEARS:
        return (
            False,
            "INSUFFICIENT_YEAR_DIVERSITY",
            "ข้อมูลย้อนหลังต้องมีมากกว่า 1 ปีเพื่อให้โมเดลเรียนรู้แนวโน้มราคา",
        )

    return True, "", ""


def _build_pipeline() -> Pipeline:
    preprocessor = ColumnTransformer(
        transformers=[
            ("num", "passthrough", ["acquisition_year"]),
            (
                "cat",
                OneHotEncoder(handle_unknown="ignore", sparse_output=False),
                ["category_key"],
            ),
        ]
    )
    return Pipeline(
        steps=[
            ("preprocessor", preprocessor),
            ("regressor", Ridge(alpha=RIDGE_ALPHA)),
        ]
    )


def _fit(df: pd.DataFrame) -> Pipeline:
    pipeline = _build_pipeline()
    pipeline.fit(df[["acquisition_year", "category_key"]], np.log(df["acquisition_value"].values))
    return pipeline


def _predict_prices(pipeline: Pipeline, years: list[int], category_keys: list[str]) -> np.ndarray:
    X = pd.DataFrame({"acquisition_year": years, "category_key": category_keys})
    return np.exp(pipeline.predict(X))


def _evaluate_holdout(df: pd.DataFrame) -> Optional[tuple[int, int, float, Optional[float]]]:
    """Train on every year before the latest one, test on the latest year.

    Returns (evaluation_year, test_records, mae, mape) or None when the split would
    leave too little data. The test year is never seen during this fit.
    """
    latest_year = int(df["acquisition_year"].max())
    train_df = df[df["acquisition_year"] < latest_year]
    test_df = df[
        (df["acquisition_year"] == latest_year)
        & df["category_key"].isin(set(train_df["category_key"]))
    ]

    if (
        len(train_df) < MIN_EVALUATION_TRAIN_RECORDS
        or len(test_df) < MIN_EVALUATION_TEST_RECORDS
        or train_df["acquisition_year"].nunique() < MIN_DISTINCT_YEARS
    ):
        return None

    pipeline = _fit(train_df)
    y_true = test_df["acquisition_value"].values
    y_pred = _predict_prices(
        pipeline, test_df["acquisition_year"].tolist(), test_df["category_key"].tolist()
    )

    mae = float(mean_absolute_error(y_true, y_pred))
    mape = float(np.mean(np.abs((y_true - y_pred) / y_true)) * 100)
    return latest_year, len(test_df), mae, mape


def train_model(records: list[dict]) -> TrainedModel:
    """Evaluate on a time-based hold-out, then fit the final model on all records."""
    df = _clean_training_df(records)
    evaluation = _evaluate_holdout(df)

    # The final model uses every record, including the most recent year.
    pipeline = _fit(df)
    year_coefficient = float(pipeline.named_steps["regressor"].coef_[0])

    return TrainedModel(
        pipeline=pipeline,
        known_categories=set(df["category_key"]),
        annual_growth_rate=math.exp(year_coefficient) - 1,
        training_records=len(df),
        evaluation_year=evaluation[0] if evaluation else None,
        evaluation_records=evaluation[1] if evaluation else 0,
        mae=evaluation[2] if evaluation else None,
        mape=evaluation[3] if evaluation else None,
    )


def predict_replacement_costs(model: TrainedModel, candidate_assets: list[dict]) -> list[dict]:
    """Predict the replacement cost of every candidate asset in its forecast year.

    * Category seen in training: price trend of that category (the model).
    * Category not seen, but the asset has its own original price: that price grown by
      the learned annual growth rate from acquisition year to forecast year.
    * Otherwise the cost cannot be predicted and is left as None.
    """
    if not candidate_assets:
        return []

    category_keys = [str(int(a["category_id"])) for a in candidate_assets]
    trend_costs = _predict_prices(
        model.pipeline, [int(a["forecast_year"]) for a in candidate_assets], category_keys
    )

    results: list[dict] = []
    for asset, category_key, trend_cost in zip(candidate_assets, category_keys, trend_costs):
        acquisition_value = asset.get("acquisition_value")
        acquisition_year = asset.get("acquisition_year")

        if category_key in model.known_categories:
            cost: Optional[float] = float(trend_cost)
            basis = BASIS_CATEGORY_TREND
        elif acquisition_value and acquisition_value > 0 and acquisition_year:
            years_ahead = int(asset["forecast_year"]) - int(acquisition_year)
            cost = float(acquisition_value) * (1 + model.annual_growth_rate) ** years_ahead
            basis = BASIS_ASSET_PRICE_TREND
        else:
            cost = None
            basis = BASIS_UNAVAILABLE

        if cost is not None and not math.isfinite(cost):
            raise ValueError(f"Non-finite prediction for asset {asset.get('asset_code')}")

        results.append(
            {
                **asset,
                "predicted_replacement_cost": round(cost, 2) if cost is not None else None,
                "prediction_basis": basis,
            }
        )

    return results


def forecast_period(base_year: int, forecast_years: int) -> list[int]:
    return list(range(base_year + 1, base_year + forecast_years + 1))


def aggregate_by_year(
    predicted_assets: list[dict], base_year: int, forecast_years: int
) -> tuple[list[dict], float]:
    """Group predicted costs by forecast year. Every year of the period is listed, even
    when it has no assets. The total is the sum of the yearly budgets."""
    years: list[dict] = []
    for year in forecast_period(base_year, forecast_years):
        in_year = [a for a in predicted_assets if a["forecast_year"] == year]
        budget = sum(
            a["predicted_replacement_cost"]
            for a in in_year
            if a["predicted_replacement_cost"] is not None
        )
        years.append({"year": year, "asset_count": len(in_year), "forecast_budget": round(budget, 2)})

    total = round(sum(y["forecast_budget"] for y in years), 2)
    return years, total


def _model_info(model: Optional[TrainedModel], training_records: int) -> dict:
    return {
        "name": MODEL_NAME,
        "features": FEATURES,
        "target": TARGET,
        "alpha": RIDGE_ALPHA,
        "training_records": model.training_records if model else training_records,
        "annual_price_growth_pct": round(model.annual_growth_rate * 100, 2) if model else None,
        "evaluation_year": model.evaluation_year if model else None,
        "evaluation_records": model.evaluation_records if model else 0,
        "mae": round(model.mae, 2) if model and model.mae is not None else None,
        "mape": round(model.mape, 2) if model and model.mape is not None else None,
    }


def _build_result(
    forecast_years: int,
    base_year: int,
    predicted_assets: list[dict],
    model: Optional[TrainedModel],
    training_records: int,
) -> dict:
    years, total = aggregate_by_year(predicted_assets, base_year, forecast_years)
    period = forecast_period(base_year, forecast_years)

    warnings: list[str] = []
    fallback_count = sum(1 for a in predicted_assets if a["prediction_basis"] == BASIS_ASSET_PRICE_TREND)
    unavailable_count = sum(1 for a in predicted_assets if a["prediction_basis"] == BASIS_UNAVAILABLE)
    if fallback_count:
        warnings.append(
            f"ครุภัณฑ์ {fallback_count} รายการอยู่ในหมวดที่ไม่มีข้อมูลราคาย้อนหลัง "
            "จึงประมาณจากมูลค่าของครุภัณฑ์ปรับด้วยอัตราการเปลี่ยนแปลงราคาเฉลี่ย"
        )
    if unavailable_count:
        warnings.append(
            f"ครุภัณฑ์ {unavailable_count} รายการไม่สามารถพยากรณ์มูลค่าทดแทนได้ "
            "(ไม่มีข้อมูลราคาย้อนหลังของหมวดและไม่มีมูลค่าของครุภัณฑ์) จึงไม่ถูกรวมในงบประมาณ"
        )

    ordered = sorted(predicted_assets, key=lambda a: (a["forecast_year"], str(a.get("asset_code") or "")))

    return {
        "success": True,
        "forecast_years": forecast_years,
        "base_year": base_year,
        "start_year": period[0],
        "end_year": period[-1],
        "total_assets": len(predicted_assets),
        "total_forecast_budget": total,
        "years": years,
        "assets": [
            {
                "asset_id": a.get("asset_id"),
                "asset_code": a.get("asset_code") or "-",
                "asset_name": a.get("asset_name"),
                "category_id": a.get("category_id"),
                "category_name": a.get("category_name") or "-",
                "category_group": a.get("category_group"),
                "organization_id": a.get("organization_id"),
                "organization_name": a.get("organization_name"),
                "acceptance_date": a.get("acceptance_date"),
                "acquisition_value": a.get("acquisition_value"),
                "current_value": a.get("current_value"),
                "forecast_year": a["forecast_year"],
                "predicted_replacement_cost": a["predicted_replacement_cost"],
                "prediction_basis": a["prediction_basis"],
            }
            for a in ordered
        ],
        "warnings": warnings,
        "model": _model_info(model, training_records),
    }


def run_forecast(
    forecast_years: int,
    base_year: int,
    training_records: list[dict],
    candidate_assets: list[dict],
) -> dict:
    """Main entry point called by the FastAPI endpoint."""
    period = forecast_period(base_year, forecast_years)
    outside = [
        str(a.get("asset_code") or a.get("asset_id"))
        for a in candidate_assets
        if not period[0] <= int(a["forecast_year"]) <= period[-1]
    ]
    if outside:
        return {
            "success": False,
            "code": "INVALID_CANDIDATE_YEAR",
            "message": f"พบครุภัณฑ์ {len(outside)} รายการที่ปีครบกำหนดทดแทนอยู่นอกช่วงที่พยากรณ์",
        }

    # No candidates: nothing to price, so the model is not needed.
    if not candidate_assets:
        return _build_result(forecast_years, base_year, [], None, len(training_records))

    valid, error_code, error_message = validate_training_data(training_records)
    if not valid:
        return {"success": False, "code": error_code, "message": error_message}

    try:
        model = train_model(training_records)
    except Exception:
        logger.exception("Model training failed")
        return {"success": False, "code": "MODEL_ERROR", "message": "เกิดข้อผิดพลาดระหว่างการเรียนรู้โมเดล"}

    try:
        predicted_assets = predict_replacement_costs(model, candidate_assets)
    except Exception:
        logger.exception("Prediction failed")
        return {"success": False, "code": "MODEL_ERROR", "message": "เกิดข้อผิดพลาดระหว่างการพยากรณ์"}

    return _build_result(forecast_years, base_year, predicted_assets, model, model.training_records)
