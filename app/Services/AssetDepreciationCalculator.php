<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Asset depreciation rules shared by the asset control register (ASS-009), ASS-003 remain_price and the
 * replacement budget forecast: straight line, annual = cost / useful life, posted in Thai fiscal-year
 * periods (1 Oct – 30 Sep), starting at the acceptance month (the next month after the 15th),
 * until a net value of 1 baht remains.
 */
class AssetDepreciationCalculator
{
    /** Book value kept at the end of the useful life (baht). */
    public const RESIDUAL_VALUE = 1.0;

    public const METHOD = 'straight_line_monthly_fiscal_year';

    /**
     * Depreciation schedule by fiscal year until the net value reaches 1 baht. With $asOf the schedule stops
     * on that date, and a fiscal year that has not finished yet is depreciated by day up to that date.
     */
    public function calculate(float $cost, int $usefulLife, ?CarbonInterface $startDate, int $fiscalYear, ?CarbonInterface $asOf = null): array
    {
        $cost = round(max(0, $cost), 2);
        $life = max(0, $usefulLife);
        $annual = $life > 0 ? round($cost / $life, 2) : 0.0;
        $monthly = round($annual / 12, 2);

        if ($cost <= 0 || $life <= 0 || $startDate === null) {
            return ['annual' => $annual, 'monthly' => $monthly, 'periods' => []];
        }

        $start = $this->effectiveMonthStart($startDate->copy());
        $limit = $asOf !== null ? Carbon::parse($asOf->toDateString())->endOfDay() : null;
        $periods = [];
        $accumulated = 0.0;
        $netValue = $cost;
        $cursor = $start->copy();
        $guard = 0;

        while ($netValue > self::RESIDUAL_VALUE && $guard++ < ($life * 2 + 24)) {
            if ($limit !== null && $cursor->gt($limit)) {
                break;
            }

            $periodEnd = $cursor->month >= 10
                ? Carbon::create($cursor->year + 1, 9, 30)->endOfDay()
                : Carbon::create($cursor->year, 9, 30)->endOfDay();

            if ($limit !== null && $periodEnd->gt($limit)) {
                $periodEnd = $limit->copy();
            }

            $months = $this->inclusiveMonths($cursor, $periodEnd);
            $days = 0;
            $depreciation = round($annual * $months / 12, 2);

            if ($periodEnd->day !== $periodEnd->daysInMonth || $cursor->day > 1) {
                // Whole days: in Carbon 3 diffInDays() is fractional when the end is 23:59:59.
                $days = (int) $cursor->copy()->startOfDay()->diffInDays($periodEnd->copy()->startOfDay()) + 1;
                $depreciation = round($annual * $days / ($cursor->isLeapYear() ? 366 : 365), 2);
                $months = 0; // a day-based period is labelled in days only

            }

            $uncappedDepreciation = $depreciation;
            $depreciation = min($depreciation, round($netValue - self::RESIDUAL_VALUE, 2));
            if ($depreciation <= 0) {
                break;
            }

            $label = $depreciation < $uncappedDepreciation
                ? $this->finalPeriodLabel($depreciation, $annual, $cursor)
                : $this->periodLabel($months, $days);

            $accumulated = round($accumulated + $depreciation, 2);
            $netValue = round(max(self::RESIDUAL_VALUE, $cost - $accumulated), 2);
            $periods[] = [
                'label' => $label,
                'depreciation' => $depreciation,
                'accumulated' => $accumulated,
                'net_value' => $netValue,
                'start_date' => $cursor->toDateString(),
                'end_date' => $periodEnd->toDateString(),
            ];

            $cursor = $periodEnd->copy()->addDay()->startOfDay();
        }

        return ['annual' => $annual, 'monthly' => $monthly, 'periods' => $periods];
    }

    /**
     * Book value of one asset on a date (default: today), taken from the same schedule as calculate().
     *
     * @return array{
     *     method: string,
     *     original_value: float,
     *     useful_life_years: int,
     *     annual_depreciation: float,
     *     depreciation_start_date: string,
     *     useful_life_end_date: string,
     *     replacement_year: int,
     *     as_of_date: string,
     *     accumulated_depreciation: float,
     *     remaining_value: float,
     *     fully_depreciated: bool
     * }|null  null when the cost, start date or a positive useful life is missing
     */
    public function valueAsOf(
        float|int|string|null $cost,
        int|string|null $usefulLife,
        CarbonInterface|string|null $startDate,
        CarbonInterface|string|null $asOf = null,
    ): ?array {
        if ($cost === null || $cost === '' || !is_numeric($cost) || (float) $cost < 0
            || $usefulLife === null || $usefulLife === '' || (int) $usefulLife <= 0
            || $startDate === null || $startDate === '') {
            return null;
        }

        $originalValue = round((float) $cost, 2);
        $life = (int) $usefulLife;
        $start = $this->toDate($startDate);
        $asOfDate = $this->toDate($asOf ?? now());

        $schedule = $this->calculate($originalValue, $life, $start, $this->fiscalYearOf($asOfDate), $asOfDate);
        $last = $schedule['periods'] === [] ? null : $schedule['periods'][count($schedule['periods']) - 1];
        $remaining = (float) ($last['net_value'] ?? $originalValue);
        $end = $this->usefulLifeEndDate($start, $life);

        return [
            'method'                   => self::METHOD,
            'original_value'           => $originalValue,
            'useful_life_years'        => $life,
            'annual_depreciation'      => $schedule['annual'],
            'depreciation_start_date'  => $this->depreciationStartDate($start)->toDateString(),
            'useful_life_end_date'     => $end->toDateString(),
            'replacement_year'         => $end->year,
            'as_of_date'               => $asOfDate->toDateString(),
            'accumulated_depreciation' => (float) ($last['accumulated'] ?? 0.0),
            'remaining_value'          => $remaining,
            'fully_depreciated'        => $originalValue > self::RESIDUAL_VALUE && $remaining <= self::RESIDUAL_VALUE,
        ];
    }

    /**
     * Value for ASSET.remain_price. Without an acceptance date or useful life nothing can be depreciated,
     * so the original value is kept.
     */
    public function remainingValue(
        float|int|string|null $cost,
        int|string|null $usefulLife,
        CarbonInterface|string|null $startDate,
        CarbonInterface|string|null $asOf = null,
    ): ?float {
        if ($cost === null || $cost === '' || !is_numeric($cost)) {
            return null;
        }

        return $this->valueAsOf($cost, $usefulLife, $startDate, $asOf)['remaining_value'] ?? round((float) $cost, 2);
    }

    /** First day of the first depreciation month. */
    public function depreciationStartDate(CarbonInterface|string $startDate): Carbon
    {
        return $this->effectiveMonthStart($this->toDate($startDate));
    }

    /** Last day of the final depreciation month: when the net value reaches 1 baht. */
    public function usefulLifeEndDate(CarbonInterface|string $startDate, int $usefulLife): Carbon
    {
        return $this->depreciationStartDate($startDate)->addMonthsNoOverflow($usefulLife * 12)->subDay();
    }

    /** Oracle expression for usefulLifeEndDate(), used to select replacement candidates in SQL. */
    public static function usefulLifeEndSql(string $startDateColumn, string $lifetimeColumn): string
    {
        return "ADD_MONTHS(CASE WHEN EXTRACT(DAY FROM {$startDateColumn}) <= 15 THEN TRUNC({$startDateColumn}, 'MM') "
            . "ELSE ADD_MONTHS(TRUNC({$startDateColumn}, 'MM'), 1) END, {$lifetimeColumn} * 12) - 1";
    }

    private function effectiveMonthStart(Carbon $date): Carbon
    {
        return $date->day <= 15
            ? $date->startOfMonth()
            : $date->addMonthNoOverflow()->startOfMonth();
    }

    private function toDate(CarbonInterface|string $date): Carbon
    {
        return Carbon::parse($date instanceof CarbonInterface ? $date->toDateString() : $date)->startOfDay();
    }

    /** Buddhist-era fiscal year, as in AssetReportController. */
    private function fiscalYearOf(Carbon $date): int
    {
        return $date->month >= 10 ? $date->year + 544 : $date->year + 543;
    }

    private function inclusiveMonths(Carbon $start, Carbon $end): int
    {
        return max(0, (($end->year - $start->year) * 12) + $end->month - $start->month + 1);
    }

    private function periodLabel(int $months, int $days): string
    {
        $parts = [];
        $years = intdiv($months, 12);
        $remainingMonths = $months % 12;
        if ($years > 0) $parts[] = $years . ' ปี';
        if ($remainingMonths > 0) $parts[] = $remainingMonths . ' เดือน';
        if ($days > 0) $parts[] = $days . ' วัน';
        return 'คำนวณค่าเสื่อมราคา ระยะเวลา ' . ($parts ? implode(' ', $parts) : '0 วัน');
    }

    private function finalPeriodLabel(float $depreciation, float $annual, Carbon $start): string
    {
        if ($annual <= 0) {
            return $this->periodLabel(0, 0);
        }

        $monthly = $annual / 12;
        $months = (int) floor(($depreciation + 0.000001) / $monthly);
        $remaining = max(0, $depreciation - ($months * $monthly));
        $daysInYear = $start->isLeapYear() ? 366 : 365;
        $daily = $annual / $daysInYear;
        $days = $daily > 0 ? (int) round($remaining / $daily) : 0;

        return $this->periodLabel($months, $days);
    }
}
