"""Offline fixture helpers — TEST ONLY.

Emulates the two Oracle queries in App\\Services\\ReplacementForecastDataService so the
AI pipeline can be exercised while the database is unavailable. The fixture data lives
in tests/fixtures/offline_assets.json and must never be imported into Oracle.

The emulation documents the current candidate rule; confirm it against the real
queries once Oracle is reachable.
"""

from __future__ import annotations

import calendar
import json
from datetime import date
from functools import lru_cache
from pathlib import Path
from typing import Iterable, Optional

FIXTURE_PATH = Path(__file__).parent / "fixtures" / "offline_assets.json"


@lru_cache
def load_fixture() -> dict:
    with FIXTURE_PATH.open(encoding="utf-8") as fh:
        return json.load(fh)


def _categories() -> dict[int, dict]:
    return {c["id"]: c for c in load_fixture()["categories"]}


def _shift_date(iso_date: Optional[str], years: int) -> Optional[date]:
    if iso_date is None:
        return None
    original = date.fromisoformat(iso_date)
    year = original.year + years
    day = min(original.day, calendar.monthrange(year, original.month)[1])
    return date(year, original.month, day)


def assets_for_base_year(base_year: int) -> list[dict]:
    """Fixture assets with every date moved so the fixture is relative to base_year."""
    fixture = load_fixture()
    shift = base_year - fixture["fixture_base_year"]
    return [{**a, "inspect_date": _shift_date(a["inspect_date"], shift)} for a in fixture["assets"]]


def replacement_year(inspect_date: date, lifetime_years: int) -> int:
    """EXTRACT(YEAR FROM ADD_MONTHS(inspect_date, ass_lifetime * 12)).

    Adding whole years never moves the month, so only the year changes (29 Feb included).
    """
    return inspect_date.year + lifetime_years


def category_group_of(asset: dict) -> Optional[str]:
    """หมวดครุภัณฑ์ = TRIM(ASSET_CATEGORY.asscat_group) of the asset's category row."""
    group = _categories()[asset["asscat_id"]].get("asscat_group")
    return group.strip() if group and group.strip() else None


def _visible(org_ids: Optional[Iterable[int]]) -> set[int]:
    if org_ids is None:
        return {o["org_id"] for o in load_fixture()["organizations"]}
    return set(org_ids)


def select_candidates(
    base_year: int,
    forecast_years: int,
    category_group: Optional[str] = None,
    org_id: Optional[int] = None,
    visible_org_ids: Optional[Iterable[int]] = None,
) -> list[dict]:
    """Emulates ReplacementForecastDataService::candidateAssets() and its row mapping."""
    categories = _categories()
    organizations = {o["org_id"]: o for o in load_fixture()["organizations"]}
    visible = _visible(visible_org_ids)

    selected = []
    for a in assets_for_base_year(base_year):
        if a["org_id"] not in visible:
            continue
        if a["inspect_date"] is None or a["ass_lifetime"] is None or a["ass_lifetime"] <= 0:
            continue
        # Oracle: a.ass_status != '3' is not true for NULL, so NULL statuses drop out too.
        if a["ass_status"] is None or a["ass_status"] == "3":
            continue
        year = replacement_year(a["inspect_date"], a["ass_lifetime"])
        if not base_year + 1 <= year <= base_year + forecast_years:
            continue
        if category_group is not None and category_group_of(a) != category_group.strip():
            continue
        if org_id is not None and a["org_id"] != org_id:
            continue

        inspected = a["inspect_date"]
        selected.append(
            {
                "asset_id": a["id"],
                "asset_code": a["ass_code"],
                "asset_name": a["ass_desc"],
                "category_id": a["asscat_id"],
                "category_name": categories[a["asscat_id"]]["asscat_name"],
                "category_group": category_group_of(a),
                "organization_id": a["org_id"],
                "organization_name": organizations[a["org_id"]]["org_name"],
                "acceptance_date": f"{inspected.day:02d}/{inspected.month:02d}/{inspected.year + 543}",
                "acquisition_year": inspected.year,
                "acquisition_value": a["ass_price"],
                "current_value": a["remain_price"],
                "forecast_year": year,
            }
        )
    return selected


def training_records(base_year: int, visible_org_ids: Optional[Iterable[int]] = None) -> list[dict]:
    """Emulates ReplacementForecastDataService::trainingRecords().

    Uses every visible organization; the category/organization filters of the screen do not
    narrow the training data.
    """
    categories = _categories()
    visible = _visible(visible_org_ids)

    return [
        {
            "acquisition_year": a["inspect_date"].year,
            "category_id": a["asscat_id"],
            "category_name": categories[a["asscat_id"]]["asscat_name"],
            "acquisition_value": a["ass_price"],
        }
        for a in assets_for_base_year(base_year)
        if a["org_id"] in visible
        and a["inspect_date"] is not None
        and a["ass_price"] is not None
        and a["ass_price"] > 0
    ]


def expected_codes(
    max_offset: int,
    category_group: Optional[str] = None,
    org_id: Optional[int] = None,
    visible_org_ids: Optional[Iterable[int]] = None,
) -> list[str]:
    """Asset codes the fixture annotates as candidates within max_offset years."""
    visible = _visible(visible_org_ids)
    return sorted(
        a["ass_code"]
        for a in load_fixture()["assets"]
        if a["expected_replacement_offset"] is not None
        and a["expected_replacement_offset"] <= max_offset
        and a["org_id"] in visible
        and (category_group is None or category_group_of(a) == category_group)
        and (org_id is None or a["org_id"] == org_id)
    )
