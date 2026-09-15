<?php

use App\Services\AssetDepreciationCalculator;
use Carbon\Carbon;

beforeEach(function () {
    $this->calculator = new AssetDepreciationCalculator();
});

it('keeps the full fiscal-year schedule used by the asset control register', function () {
    // 25,400 baht accepted 15/03/2022 (day <= 15 -> depreciation starts 01/03/2022), 5-year life.
    $schedule = $this->calculator->calculate(25400, 5, Carbon::parse('2022-03-15'), 2565);

    expect($schedule['annual'])->toBe(5080.0)
        ->and($schedule['periods'])->toHaveCount(6)
        ->and($schedule['periods'][0])->toMatchArray([
            'start_date' => '2022-03-01', 'end_date' => '2022-09-30', 'depreciation' => 2963.33, 'accumulated' => 2963.33, 'net_value' => 22436.67,
        ])
        ->and($schedule['periods'][4])->toMatchArray(['end_date' => '2026-09-30', 'accumulated' => 23283.33, 'net_value' => 2116.67])
        ->and($schedule['periods'][5])->toMatchArray(['end_date' => '2027-09-30', 'depreciation' => 2115.67, 'accumulated' => 25399.0, 'net_value' => 1.0]);
});

it('values an asset at the end of a fiscal year exactly like the register row for that year', function () {
    $value = $this->calculator->valueAsOf(25400, 5, '2022-03-15', '2025-09-30');

    expect($value)->toMatchArray([
        'method'                   => 'straight_line_monthly_fiscal_year',
        'original_value'           => 25400.0,
        'useful_life_years'        => 5,
        'annual_depreciation'      => 5080.0,
        'depreciation_start_date'  => '2022-03-01',
        'useful_life_end_date'     => '2027-02-28',
        'replacement_year'         => 2027,
        'as_of_date'               => '2025-09-30',
        'accumulated_depreciation' => 18203.33,
        'remaining_value'          => 7196.67,
        'fully_depreciated'        => false,
    ]);
});

it('depreciates the unfinished fiscal year by day up to the calculation date', function () {
    // 01/10/2025 - 14/09/2026 = 349 days: 5,080 x 349 / 365 = 4,857.32 on top of 18,203.33.
    expect($this->calculator->valueAsOf(25400, 5, '2022-03-15', '2026-09-14'))
        ->toMatchArray(['accumulated_depreciation' => 23060.65, 'remaining_value' => 2339.35, 'fully_depreciated' => false]);
});

it('reaches 1 baht in the last month of the useful life', function () {
    expect($this->calculator->valueAsOf(25400, 5, '2022-03-15', '2027-01-31'))
        ->toMatchArray(['remaining_value' => 423.34, 'fully_depreciated' => false])
        ->and($this->calculator->valueAsOf(25400, 5, '2022-03-15', '2027-02-28'))
        ->toMatchArray(['accumulated_depreciation' => 25399.0, 'remaining_value' => 1.0, 'fully_depreciated' => true])
        ->and($this->calculator->valueAsOf(20100, 5, '2016-03-15', '2026-09-14'))
        ->toMatchArray(['remaining_value' => 1.0, 'fully_depreciated' => true, 'replacement_year' => 2021]);
});

it('starts depreciation in the acceptance month up to the 15th, otherwise in the next month', function (string $inspectDate, int $life, string $start, string $end) {
    expect($this->calculator->depreciationStartDate($inspectDate)->toDateString())->toBe($start)
        ->and($this->calculator->usefulLifeEndDate($inspectDate, $life)->toDateString())->toBe($end);
})->with([
    'on the 15th'           => ['2022-03-15', 5, '2022-03-01', '2027-02-28'],
    'after the 15th'        => ['2022-08-20', 5, '2022-09-01', '2027-08-31'],
    'early January'         => ['2022-01-10', 5, '2022-01-01', '2026-12-31'],
    'late December'         => ['2022-12-20', 5, '2023-01-01', '2027-12-31'],
    'eight-year vehicle'    => ['2019-07-01', 8, '2019-07-01', '2027-06-30'],
]);

it('exposes the same end-of-life rule for SQL candidate selection', function () {
    expect(AssetDepreciationCalculator::usefulLifeEndSql('a.inspect_date', 'a.ass_lifetime'))
        ->toBe("ADD_MONTHS(CASE WHEN EXTRACT(DAY FROM a.inspect_date) <= 15 THEN TRUNC(a.inspect_date, 'MM') ELSE ADD_MONTHS(TRUNC(a.inspect_date, 'MM'), 1) END, a.ass_lifetime * 12) - 1");
});

it('has no depreciation before depreciation starts', function () {
    expect($this->calculator->valueAsOf(9800, 2, '2026-10-01', '2026-09-14'))
        ->toMatchArray(['accumulated_depreciation' => 0.0, 'remaining_value' => 9800.0]);
});

it('does not depreciate an asset recorded at 1 baht or less', function () {
    expect($this->calculator->valueAsOf(1, 2, '2026-08-25', '2028-12-31'))
        ->toMatchArray(['accumulated_depreciation' => 0.0, 'remaining_value' => 1.0]);
});

it('cannot value an asset without a price, an acceptance date or a positive useful life', function (mixed $price, ?string $date, mixed $life) {
    expect($this->calculator->valueAsOf($price, $life, $date, '2026-09-14'))->toBeNull();
})->with([
    'no price'       => [null, '2022-01-01', 5],
    'no date'        => [1000, null, 5],
    'no life'        => [1000, '2022-01-01', null],
    'zero life'      => [1000, '2022-01-01', 0],
    'negative price' => [-5, '2022-01-01', 5],
]);

it('keeps the original value as remain_price when depreciation cannot be calculated yet', function () {
    expect($this->calculator->remainingValue(15000, 5, null, '2026-09-14'))->toBe(15000.0)
        ->and($this->calculator->remainingValue(null, 5, '2022-01-01', '2026-09-14'))->toBeNull()
        ->and($this->calculator->remainingValue(25400, 5, '2022-03-15', '2026-09-14'))->toBe(2339.35);
});
