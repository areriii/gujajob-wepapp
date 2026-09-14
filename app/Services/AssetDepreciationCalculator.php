<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class AssetDepreciationCalculator
{
    public function calculate(float $cost, int $usefulLife, ?CarbonInterface $startDate, int $fiscalYear): array
    {
        $cost = round(max(0, $cost), 2);
        $life = max(0, $usefulLife);
        $annual = $life > 0 ? round($cost / $life, 2) : 0.0;
        $monthly = round($annual / 12, 2);

        if ($cost <= 0 || $life <= 0 || $startDate === null) {
            return ['annual' => $annual, 'monthly' => $monthly, 'periods' => []];
        }

        $start = $this->effectiveMonthStart($startDate->copy());
        $periods = [];
        $accumulated = 0.0;
        $netValue = $cost;
        $cursor = $start->copy();
        $guard = 0;

        while ($netValue > 1.0 && $guard++ < ($life * 2 + 24)) {
            $periodEnd = $cursor->month >= 10
                ? Carbon::create($cursor->year + 1, 9, 30)->endOfDay()
                : Carbon::create($cursor->year, 9, 30)->endOfDay();

            $months = $this->inclusiveMonths($cursor, $periodEnd);
            $days = 0;
            $depreciation = round($annual * $months / 12, 2);

            if ($periodEnd->day !== $periodEnd->daysInMonth || $cursor->day > 1) {
                $days = $cursor->diffInDays($periodEnd) + 1;
                $depreciation = round($annual * $days / ($cursor->isLeapYear() ? 366 : 365), 2);
            }

            $uncappedDepreciation = $depreciation;
            $depreciation = min($depreciation, round($netValue - 1.0, 2));
            if ($depreciation <= 0) {
                break;
            }

            $label = $depreciation < $uncappedDepreciation
                ? $this->finalPeriodLabel($depreciation, $annual, $cursor)
                : $this->periodLabel($months, $days);

            $accumulated = round($accumulated + $depreciation, 2);
            $netValue = round(max(1.0, $cost - $accumulated), 2);
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

    private function effectiveMonthStart(Carbon $date): Carbon
    {
        return $date->day <= 15
            ? $date->startOfMonth()
            : $date->addMonthNoOverflow()->startOfMonth();
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
