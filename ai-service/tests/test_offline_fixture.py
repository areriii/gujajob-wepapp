"""End-to-end tests on the offline asset fixture (no Oracle required).

tests/fixtures/offline_assets.json mirrors the ASSET / ASSET_CATEGORY / GLB_ORGANIZATION
columns Laravel reads. Candidate selection is emulated by tests/offline_fixture.py.
"""

from __future__ import annotations

from collections import Counter

import pytest
from fastapi.testclient import TestClient

from app.main import app
from tests.offline_fixture import (
    expected_codes,
    load_fixture,
    select_candidates,
    training_records,
)

client = TestClient(app)

ENDPOINT = "/forecast/replacement-budget"
# The fixture is shifted to each base year, proving nothing depends on a fixed calendar year.
BASE_YEARS = [2026, 2031, 2040]


def _annotated_assets() -> list[dict]:
    return load_fixture()["assets"]


def _offset_of(code: str) -> int | None:
    return next(a["expected_replacement_offset"] for a in _annotated_assets() if a["ass_code"] == code)


def _forecast(base_year: int, forecast_years: int, **filters) -> dict:
    payload = {
        "forecast_years": forecast_years,
        "base_year": base_year,
        "training_records": training_records(base_year, filters.get("visible_org_ids")),
        "candidate_assets": select_candidates(base_year, forecast_years, **filters),
    }
    response = client.post(ENDPOINT, json=payload)
    assert response.status_code == 200, response.text
    return response.json()


# ---------------------------------------------------------------------------
# Fixture coverage
# ---------------------------------------------------------------------------

def test_fixture_covers_required_scenarios():
    assets = _annotated_assets()
    candidates = [a for a in assets if a["expected_replacement_offset"] is not None]
    non_candidates = [a for a in assets if a["expected_replacement_offset"] is None]

    assert {a["expected_replacement_offset"] for a in candidates} == {1, 2, 3}
    assert len({a["asscat_id"] for a in candidates}) >= 3
    assert len({a["org_id"] for a in candidates}) >= 3
    assert len({a["ass_lifetime"] for a in candidates}) >= 3
    assert len({a["inspect_date"][:4] for a in candidates}) >= 3
    assert len({a["ass_price"] for a in candidates if a["ass_price"]}) >= 3
    assert len([a for a in non_candidates if a["ass_code"].startswith("FX-N")]) >= 8
    assert all("FIXTURE" in o["org_name"] for o in load_fixture()["organizations"])
    assert all(a["ass_code"].startswith("FX-") for a in assets)


@pytest.mark.parametrize("base_year", BASE_YEARS)
@pytest.mark.parametrize("forecast_years", [1, 2, 3])
def test_candidate_selection_matches_fixture_annotations(base_year: int, forecast_years: int):
    selected = select_candidates(base_year, forecast_years)
    assert sorted(a["asset_code"] for a in selected) == expected_codes(forecast_years)
    for asset in selected:
        assert asset["forecast_year"] == base_year + _offset_of(asset["asset_code"])


# ---------------------------------------------------------------------------
# 1 / 2 / 3-year forecasts
# ---------------------------------------------------------------------------

@pytest.mark.parametrize("base_year", BASE_YEARS)
@pytest.mark.parametrize("forecast_years", [1, 2, 3])
def test_end_to_end_forecast_per_horizon(base_year: int, forecast_years: int):
    data = _forecast(base_year, forecast_years)

    expected_per_offset = Counter(
        a["expected_replacement_offset"]
        for a in _annotated_assets()
        if a["expected_replacement_offset"] is not None and a["expected_replacement_offset"] <= forecast_years
    )

    assert data["forecast_years"] == forecast_years
    assert [y["year"] for y in data["years"]] == [base_year + i for i in range(1, forecast_years + 1)]
    assert [y["asset_count"] for y in data["years"]] == [expected_per_offset[i] for i in range(1, forecast_years + 1)]
    assert data["total_assets"] == sum(expected_per_offset.values())

    # Yearly aggregation and total
    assert data["total_forecast_budget"] == round(sum(y["forecast_budget"] for y in data["years"]), 2)
    for year in data["years"]:
        in_year = [a for a in data["assets"] if a["forecast_year"] == year["year"]]
        assert year["forecast_budget"] == pytest.approx(sum(a["predicted_replacement_cost"] for a in in_year), abs=0.01)

    # Every candidate gets a model-based cost; no non-candidate leaks in.
    assert all(a["predicted_replacement_cost"] > 0 for a in data["assets"])
    assert all(a["prediction_basis"] == "category_trend" for a in data["assets"])
    returned = {a["asset_code"] for a in data["assets"]}
    assert returned == set(expected_codes(forecast_years))
    assert not returned & {"FX-N001", "FX-N002", "FX-N003", "FX-N004", "FX-N005", "FX-N006", "FX-N007", "FX-N008"}

    assert data["model"]["training_records"] == len(training_records(base_year))


def test_predicted_cost_differs_by_category_and_is_not_the_registry_price():
    data = _forecast(2026, 3)
    costs = {a["asset_code"]: a["predicted_replacement_cost"] for a in data["assets"]}
    assert costs["FX-C003"] > costs["FX-C005"]  # air conditioner vs desk
    for asset in data["assets"]:
        assert asset["predicted_replacement_cost"] not in (asset["acquisition_value"], asset["current_value"])


# ---------------------------------------------------------------------------
# Filters
# ---------------------------------------------------------------------------

ELECTRICAL_GROUP = "ครุภัณฑ์ไฟฟ้าและวิทยุ (ทดสอบ)"


def test_fixture_groups_are_categories_not_asset_names():
    categories = load_fixture()["categories"]
    groups = {c["asscat_group"] for c in categories}
    names = {c["asscat_name"] for c in categories}
    assert len(groups) == 3
    assert not groups & names


def test_category_group_filter_limits_candidates_but_not_training_data():
    unfiltered = _forecast(2026, 3)
    filtered = _forecast(2026, 3, category_group=ELECTRICAL_GROUP)

    assert sorted(a["asset_code"] for a in filtered["assets"]) == expected_codes(3, category_group=ELECTRICAL_GROUP)
    assert {a["category_group"] for a in filtered["assets"]} == {ELECTRICAL_GROUP}
    # One หมวดครุภัณฑ์ spans several ASSET_CATEGORY rows (asset types).
    assert len({a["category_id"] for a in filtered["assets"]}) > 1
    assert filtered["model"]["training_records"] == unfiltered["model"]["training_records"]


@pytest.mark.parametrize("forecast_years", [1, 2, 3])
def test_category_group_and_organization_filters_per_horizon(forecast_years: int):
    data = _forecast(2026, forecast_years, category_group=ELECTRICAL_GROUP, org_id=9001)
    expected = expected_codes(forecast_years, category_group=ELECTRICAL_GROUP, org_id=9001)

    assert sorted(a["asset_code"] for a in data["assets"]) == expected
    assert all(a["category_group"] == ELECTRICAL_GROUP and a["organization_id"] == 9001 for a in data["assets"])
    assert [y["year"] for y in data["years"]] == [2026 + i for i in range(1, forecast_years + 1)]
    assert data["total_forecast_budget"] == round(sum(y["forecast_budget"] for y in data["years"]), 2)


def test_organization_filter_limits_candidates():
    data = _forecast(2026, 3, org_id=9002)
    assert sorted(a["asset_code"] for a in data["assets"]) == expected_codes(3, org_id=9002)
    assert {a["organization_id"] for a in data["assets"]} == {9002}


def test_visible_organizations_limit_candidates_and_training():
    data = _forecast(2026, 3, visible_org_ids=[9001])
    assert sorted(a["asset_code"] for a in data["assets"]) == expected_codes(3, visible_org_ids=[9001])
    assert data["model"]["training_records"] == len(training_records(2026, [9001]))


def test_filters_without_matching_assets_return_zero_for_every_year():
    data = _forecast(2026, 3, category_group="ครุภัณฑ์สำนักงาน (ทดสอบ)", org_id=9003)
    assert data["total_assets"] == 0
    assert data["total_forecast_budget"] == 0.0
    assert [y["forecast_budget"] for y in data["years"]] == [0.0, 0.0, 0.0]
