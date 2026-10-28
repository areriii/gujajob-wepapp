<?php

namespace App\Services;

/**
 * ASS-003 replacement budget: each asset due for replacement is budgeted at its purchase value plus the
 * percentage entered on the forecast page (once, not per year): 25,000 at 5% = 26,250.
 *
 * Which assets are due, and when, comes from the asset register (ReplacementForecastDataService).
 */
class ReplacementBudgetCalculator
{
    public const DEFAULT_PERCENT = 5.0;

    /**
     * @param  list<array<string, mixed>>  $candidateAssets  assets whose useful life ends in the forecast period
     * @return array<string, mixed>  the result shown on the forecast page, its asset list and the print view
     */
    public function calculate(int $forecastYears, int $baseYear, float $increasePercent, array $candidateAssets): array
    {
        $period = range($baseYear + 1, $baseYear + max(1, $forecastYears));
        $factor = 1 + $increasePercent / 100;

        $assets = array_map(function (array $asset) use ($factor): array {
            $value = $asset['acquisition_value'] ?? null;
            $asset['replacement_budget'] = is_numeric($value) ? round((float) $value * $factor, 2) : null;

            return $asset;
        }, array_values($candidateAssets));

        $years = array_map(function (int $year) use ($assets): array {
            $due = array_filter($assets, fn (array $asset) => (int) ($asset['forecast_year'] ?? 0) === $year);

            return [
                'year'            => $year,
                'asset_count'     => count($due),
                'forecast_budget' => round(array_sum(array_map(fn (array $asset) => (float) ($asset['replacement_budget'] ?? 0), $due)), 2),
            ];
        }, $period);

        $withoutValue = count(array_filter($assets, fn (array $asset) => $asset['replacement_budget'] === null));

        return [
            'success'               => true,
            'forecast_years'        => $forecastYears,
            'base_year'             => $baseYear,
            'start_year'            => $period[0],
            'end_year'              => $period[count($period) - 1],
            'increase_percent'      => $increasePercent,
            'total_assets'          => count($assets),
            'total_forecast_budget' => round(array_sum(array_column($years, 'forecast_budget')), 2),
            'years'                 => $years,
            'assets'                => $assets,
            'warnings'              => $withoutValue > 0
                ? ["ครุภัณฑ์ {$withoutValue} รายการไม่มีมูลค่าตอนซื้อในทะเบียน จึงไม่ได้นับรวมในงบประมาณ"]
                : [],
        ];
    }
}
