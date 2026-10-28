"""Recommended stock (MAT-006 จำนวนเฉลี่ยในการเบิกต่อเดือน) — model and fallback to the 12-month average."""

from __future__ import annotations

import math

from app.services.material_stock_forecast import METHOD_AVERAGE, METHOD_MODEL, run_stock_forecast


def _months(values: list[float], length: int = 36) -> list[float]:
    """Pad with zero months in front so the series has `length` months, newest last."""
    return [0.0] * (length - len(values)) + values


def test_short_history_averages_over_the_months_there_are():
    # Three months of history: (20 + 5 + 5) / 3 = 10, not / 12.
    result = run_stock_forecast([{"key": "1310:80", "monthly": [20.0, 5.0, 5.0]}], ["1310:80"])

    assert result["method"] == METHOD_AVERAGE
    assert result["reason"] == "INSUFFICIENT_HISTORY"
    assert result["recommendations"][0]["recommended"] == 10
    assert result["recommendations"][0]["average_12m"] == 10.0


def test_history_from_july_averages_four_months_in_october():
    # Jul, Aug, Sep, Oct: withdrawals only in July.
    series = [
        {"key": "1310:89", "monthly": [2.0, 0.0, 0.0, 0.0]},  # 2 / 4 = 0.5 -> 1
        {"key": "1310:80", "monthly": [5.0, 0.0, 0.0, 0.0]},  # 5 / 4 = 1.25 -> 2
    ]

    by_key = {r["key"]: r for r in run_stock_forecast(series, ["1310:89", "1310:80"])["recommendations"]}

    assert by_key["1310:89"]["recommended"] == 1
    assert by_key["1310:80"]["recommended"] == 2


def test_after_a_year_only_the_last_12_months_are_averaged():
    # 13 months of history: the oldest month (24) drops out, 12 months of 1 remain.
    result = run_stock_forecast([{"key": "65:1", "monthly": [24.0] + [1.0] * 12}], ["65:1"])

    assert result["recommendations"][0]["average_12m"] == 1.0
    assert result["recommendations"][0]["recommended"] == 1


def test_no_withdrawal_in_the_last_12_months_gets_no_recommendation():
    series = [{"key": "65:1", "monthly": _months([4.0] + [0.0] * 12)}]  # last withdrawal 13 months ago

    assert run_stock_forecast(series, ["65:1"])["recommendations"] == []


def test_a_rising_demand_is_learnt_and_beats_the_average():
    # Ten stores whose monthly withdrawals grow steadily: the 12-month average lags behind.
    series = [
        {"key": f"{org}:7", "monthly": [float(org + month) for month in range(36)]}
        for org in range(1, 11)
    ]

    result = run_stock_forecast(series, ["1:7"])

    assert result["method"] == METHOD_MODEL
    assert result["evaluation"]["model_mae"] < result["evaluation"]["baseline_mae"]
    recommendation = result["recommendations"][0]
    # Next month for store 1 is 1 + 36 = 37; the 12-month average is only 30.5.
    assert recommendation["average_12m"] == 30.5
    assert abs(recommendation["forecast"] - 37) < 1
    assert recommendation["recommended"] == math.ceil(recommendation["forecast"])


def test_every_series_trains_but_only_targets_are_recommended():
    series = [{"key": f"{org}:7", "monthly": [float(org + month) for month in range(36)]} for org in range(1, 11)]

    result = run_stock_forecast(series, ["3:7", "99:9"])

    assert [r["key"] for r in result["recommendations"]] == ["3:7"]
    assert result["training_samples"] == 10 * (36 - 12)
