from __future__ import annotations

from typing import Optional

from pydantic import BaseModel, Field

MIN_FORECAST_YEARS = 1
MAX_FORECAST_YEARS = 3


class TrainingRecord(BaseModel):
    """One historical purchase: an asset's original price in the year it was accepted."""

    acquisition_year: int = Field(..., ge=1900, le=3000)
    category_id: int
    category_name: str
    acquisition_value: float


class CandidateAsset(BaseModel):
    """An existing asset whose useful life ends in forecast_year.

    Laravel selects candidates and their forecast_year from the asset registry
    (inspect_date + ass_lifetime). The AI service never changes that decision.
    """

    asset_id: int
    asset_code: str
    asset_name: Optional[str] = None
    category_id: int
    category_name: str
    # หมวดครุภัณฑ์ (ASSET_CATEGORY.asscat_group) — display only, not a model feature
    category_group: Optional[str] = None
    organization_id: Optional[int] = None
    organization_name: Optional[str] = None
    acceptance_date: Optional[str] = None
    acquisition_year: Optional[int] = None
    acquisition_value: Optional[float] = None
    current_value: Optional[float] = None
    forecast_year: int


class ForecastRequest(BaseModel):
    forecast_years: int = Field(..., ge=MIN_FORECAST_YEARS, le=MAX_FORECAST_YEARS)
    # The year the forecast is made in. Forecast years are base_year + 1 .. base_year + forecast_years.
    base_year: int = Field(..., ge=1900, le=3000)
    training_records: list[TrainingRecord]
    candidate_assets: list[CandidateAsset]
