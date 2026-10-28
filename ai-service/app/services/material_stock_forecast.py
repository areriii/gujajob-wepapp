"""Recommended stock per store (organization) and material for MAT-006 ("จำนวนเฉลี่ยในการเบิกต่อเดือน").

Laravel sends monthly withdrawal totals per store/material for the calendar months there is history for
(oldest -> newest, the last value is the current month). A ridge regression learns next month's
withdrawal from the 12-month average, the 3-month average, last month and the 12-month trend, pooled over
every store and material. The recommendation is that forecast, rounded up to whole units.

The baseline is the average over the months of history there are, at most the last 12 (history from July
averages 4 months in October). It is used when the history is too thin to learn from (a model needs more
than 12 months), or when the model does not beat it on the most recent months (time-based hold-out).
"""

from __future__ import annotations

import math

import numpy as np

LOOKBACK = 12          # months of history behind every prediction (the "12 เดือนล่าสุด")
HOLDOUT_MONTHS = 6     # most recent months kept back to check the model against the 12-month average
MIN_NONZERO_TARGETS = 12  # months with an actual withdrawal needed before a model is trained
RIDGE_ALPHA = 1.0

METHOD_MODEL = "ridge_regression"
METHOD_AVERAGE = "moving_average_12m"


def _features(history: np.ndarray) -> np.ndarray:
    window = history[-LOOKBACK:]
    slope = float(np.polyfit(np.arange(len(window)), window, 1)[0]) if len(window) >= 2 else 0.0
    return np.array([window.mean(), window[-3:].mean(), window[-1], slope])


def _samples(series: list[np.ndarray], first_target: int = LOOKBACK, last_target: int | None = None):
    """(features, target) for every month that has LOOKBACK months of history before it."""
    rows, targets = [], []
    for monthly in series:
        end = len(monthly) if last_target is None else min(last_target, len(monthly))
        for t in range(first_target, end):
            rows.append(_features(monthly[:t]))
            targets.append(monthly[t])
    return np.array(rows).reshape(-1, 4), np.array(targets)


def _fit_ridge(x: np.ndarray, y: np.ndarray, alpha: float = RIDGE_ALPHA) -> tuple[np.ndarray, float]:
    """Closed-form ridge regression with an unpenalised intercept."""
    x_mean, y_mean = x.mean(axis=0), y.mean()
    xc, yc = x - x_mean, y - y_mean
    weights = np.linalg.solve(xc.T @ xc + alpha * np.eye(x.shape[1]), xc.T @ yc)
    return weights, float(y_mean - x_mean @ weights)


def _predict(model: tuple[np.ndarray, float], x: np.ndarray) -> np.ndarray:
    weights, intercept = model
    return np.clip(x @ weights + intercept, 0.0, None)


def run_stock_forecast(series: list[dict], targets: list[str]) -> dict:
    """series: [{"key": "org:mat", "monthly": [...]}]; targets: keys to recommend for."""
    by_key = {item["key"]: np.asarray(item["monthly"], dtype=float) for item in series}
    histories = [monthly for monthly in by_key.values() if len(monthly) > LOOKBACK and monthly.sum() > 0]

    x_all, y_all = _samples(histories)
    nonzero = int((y_all > 0).sum())
    method, reason, evaluation, model = METHOD_AVERAGE, None, None, None

    if nonzero < MIN_NONZERO_TARGETS:
        reason = "INSUFFICIENT_HISTORY"
    else:
        # Train on everything before the hold-out months, then compare with the 12-month average on them.
        cut = {len(m) - HOLDOUT_MONTHS for m in histories}
        x_train, y_train = _samples(histories, last_target=min(cut))
        x_test, y_test = _samples(histories, first_target=min(cut))
        if len(y_train) == 0 or len(y_test) == 0 or (y_train > 0).sum() < MIN_NONZERO_TARGETS // 2:
            reason = "INSUFFICIENT_HISTORY"
        else:
            candidate = _fit_ridge(x_train, y_train)
            model_mae = float(np.abs(_predict(candidate, x_test) - y_test).mean())
            baseline_mae = float(np.abs(x_test[:, 0] - y_test).mean())
            evaluation = {"model_mae": round(model_mae, 4), "baseline_mae": round(baseline_mae, 4), "holdout_months": HOLDOUT_MONTHS}
            if model_mae <= baseline_mae:
                model, method = _fit_ridge(x_all, y_all), METHOD_MODEL
            else:
                reason = "MODEL_NOT_BETTER"

    recommendations = []
    for key in targets:
        monthly = by_key.get(key)
        if monthly is None or len(monthly) == 0 or monthly[-LOOKBACK:].sum() <= 0:
            continue  # nothing withdrawn in the averaged months: no recommendation
        features = _features(monthly)
        forecast = float(_predict(model, features.reshape(1, -1))[0]) if model is not None else float(features[0])
        recommendations.append({
            "key": key,
            "recommended": int(math.ceil(round(forecast, 6))),
            "forecast": round(forecast, 4),
            "average_12m": round(float(features[0]), 4),
        })

    return {
        "success": True,
        "method": method,
        "reason": reason,
        "training_samples": int(len(y_all)),
        "nonzero_samples": nonzero,
        "evaluation": evaluation,
        "recommendations": recommendations,
    }
