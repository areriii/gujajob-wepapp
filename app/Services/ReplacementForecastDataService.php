<?php

namespace App\Services;

use App\Models\AssetCategory;
use Illuminate\Support\Facades\DB;

/**
 * Oracle queries for the ASS-003 replacement budget forecast.
 *
 * Replacement timing and มูลค่าคงเหลือ come from the asset register through AssetDepreciationCalculator
 * (the same depreciation rules as the ASS-009 asset control register), starting from the acceptance date
 * (inspect_date). The AI service only predicts how much a replacement will cost.
 */
class ReplacementForecastDataService
{
    public function __construct(
        private readonly AssetDepreciationCalculator $depreciation = new AssetDepreciationCalculator(),
    ) {}

    /**
     * Assets whose useful life ends between base year + 1 and base year + forecast years,
     * with their depreciation as of today.
     *
     * @param  string|null  $categoryGroup  หมวดครุภัณฑ์ (ASSET_CATEGORY.asscat_group); null = ทั้งหมด
     * @param  int[]  $visibleOrgIds
     * @return list<array<string, mixed>>
     */
    public function candidateAssets(
        int $forecastYears,
        int $baseYear,
        ?string $categoryGroup,
        ?int $orgId,
        array $visibleOrgIds,
    ): array {
        $endYearExpr = 'EXTRACT(YEAR FROM ' . AssetDepreciationCalculator::usefulLifeEndSql('a.inspect_date', 'a.ass_lifetime') . ')';

        $query = DB::connection('oracle')->table('ASSET AS a')
            ->join('ASSET_CATEGORY AS c', 'a.asscat_id', '=', 'c.id')
            ->leftJoin('GLB_ORGANIZATION AS org', 'a.org_id', '=', 'org.org_id')
            ->whereIn('a.org_id', $visibleOrgIds)
            ->whereNotNull('a.inspect_date')
            ->whereNotNull('a.ass_lifetime')
            ->where('a.ass_lifetime', '>', 0)
            ->where('a.ass_status', '!=', '3')
            ->whereRaw("{$endYearExpr} >= ?", [$baseYear + 1])
            ->whereRaw("{$endYearExpr} <= ?", [$baseYear + $forecastYears])
            ->select([
                'a.id',
                'a.ass_code',
                'a.ass_desc',
                'c.id AS category_id',
                'c.asscat_name AS category_name',
                DB::raw('TRIM(c.asscat_group) AS category_group'),
                'a.org_id AS organization_id',
                'org.org_name AS organization_name',
                // Year + 543 as a number: adding INTERVAL '543' YEAR to 29 Feb raises ORA-01839.
                DB::raw("TO_CHAR(a.inspect_date, 'DD/MM/') || TO_CHAR(EXTRACT(YEAR FROM a.inspect_date) + 543) AS acceptance_date"),
                DB::raw("TO_CHAR(a.inspect_date, 'YYYY-MM-DD') AS inspect_date_iso"),
                DB::raw('EXTRACT(YEAR FROM a.inspect_date) AS acquisition_year'),
                'a.ass_lifetime AS useful_life_years',
                'a.ass_price AS acquisition_value',
                'a.remain_price AS stored_remain_price',
                DB::raw("{$endYearExpr} AS forecast_year"),
            ]);

        if ($categoryGroup !== null) {
            $query->whereRaw('TRIM(c.asscat_group) = ?', [trim($categoryGroup)]);
        }

        if ($orgId !== null && in_array($orgId, $visibleOrgIds, true)) {
            $query->where('a.org_id', $orgId);
        }

        $asOf = now();

        return $query->get()->map(function ($r) use ($asOf) {
            $value = $this->depreciation->valueAsOf(
                $r->acquisition_value,
                $r->useful_life_years ?? null,
                $r->inspect_date_iso ?? null,
                $asOf,
            );
            $storedRemain = isset($r->stored_remain_price) ? (float) $r->stored_remain_price : null;

            return [
                'asset_id'                 => (int) $r->id,
                'asset_code'               => $r->ass_code ?? '-',
                'asset_name'               => $r->ass_desc,
                'category_id'              => (int) $r->category_id,
                'category_name'            => $r->category_name ?? '-',
                'category_group'           => $r->category_group,
                'organization_id'          => (int) $r->organization_id,
                'organization_name'        => $r->organization_name,
                'acceptance_date'          => $r->acceptance_date,
                'inspect_date'             => $r->inspect_date_iso ?? null,
                'acquisition_year'         => $r->acquisition_year !== null ? (int) $r->acquisition_year : null,
                'acquisition_value'        => $r->acquisition_value !== null ? (float) $r->acquisition_value : null,
                'useful_life_years'        => isset($r->useful_life_years) ? (int) $r->useful_life_years : null,
                'depreciation_start_date'  => $value['depreciation_start_date'] ?? null,
                'end_of_life_date'         => $value['useful_life_end_date'] ?? null,
                'annual_depreciation'      => $value['annual_depreciation'] ?? null,
                'accumulated_depreciation' => $value['accumulated_depreciation'] ?? null,
                // มูลค่าคงเหลือ today, from the depreciation rules; the stored column is kept only for comparison.
                'current_value'            => $value['remaining_value'] ?? $storedRemain,
                'stored_remain_price'      => $storedRemain,
                'depreciation_method'      => $value['method'] ?? null,
                'forecast_year'            => (int) $r->forecast_year,
            ];
        })->values()->all();
    }

    /**
     * Historical purchase prices used to train the AI model. Covers every visible
     * organization; the screen's category/organization filters do not narrow it.
     *
     * @param  int[]  $visibleOrgIds
     * @return list<array{acquisition_year: int, category_id: int, category_name: string, acquisition_value: float}>
     */
    public function trainingRecords(array $visibleOrgIds): array
    {
        return DB::connection('oracle')->table('ASSET AS a')
            ->join('ASSET_CATEGORY AS c', 'a.asscat_id', '=', 'c.id')
            ->whereIn('a.org_id', $visibleOrgIds)
            ->whereNotNull('a.inspect_date')
            ->whereNotNull('a.ass_price')
            ->where('a.ass_price', '>', 0)
            ->select([
                DB::raw('EXTRACT(YEAR FROM a.inspect_date) AS acquisition_year'),
                'a.asscat_id AS category_id',
                'c.asscat_name AS category_name',
                'a.ass_price AS acquisition_value',
            ])
            ->get()
            ->map(fn ($r) => [
                'acquisition_year'  => (int) $r->acquisition_year,
                'category_id'       => (int) $r->category_id,
                'category_name'     => $r->category_name ?? '-',
                'acquisition_value' => (float) $r->acquisition_value,
            ])->values()->all();
    }

    public function categoryGroupExists(string $categoryGroup): bool
    {
        return AssetCategory::groupExists($categoryGroup);
    }

    public function organizationName(int $orgId): ?string
    {
        return DB::connection('oracle')->table('GLB_ORGANIZATION')
            ->where('org_id', $orgId)
            ->value('org_name');
    }
}
