<?php

/*
 * ยอดคงเหลือแนะนำ (MAT-006): the AI service forecasts next month's withdrawal per store and material;
 * otherwise it is the average over the months of history there are, at most the last 12.
 * Oracle is replaced by recordingOracle() (tests/Pest.php); the AI service by Http::fake().
 */

use App\Services\MaterialStockRecommendationService as Recommendations;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

afterEach(fn () => DB::purge('oracle'));

/**
 * @param int $historyMonths calendar months since the first received withdrawal, this month included
 * @param array<int, array{0:int, 1:int, 2:int, 3:float}> $rows [store org, material, months ago (0 = this month), quantity]
 */
function withdrawalHistory(int $historyMonths, array $rows): Yajra\Oci8\Oci8Connection
{
    return recordingOracle(function (string $sql) use ($historyMonths, $rows) {
        if (str_contains($sql, 'MIN(w.mat_wd_date)')) {
            return [(object) ['months' => $historyMonths ?: null]];
        }

        return array_map(fn (array $row) => (object) array_combine(['src_org_id', 'mat_id', 'bucket', 'qty'], $row), $rows);
    });
}

function aiDown(): void
{
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
}

function recommendOn(string $today, ?int $orgId, array $matIds): array
{
    return app(Recommendations::class)->recommend($orgId, $matIds, Carbon::parse($today));
}

it('averages over the months since history began: July to October is 4 months', function () {
    $oracle = withdrawalHistory(4, [
        [1310, 89, 3, 2],   // July: 2 / 4 = 0.5  -> 1
        [1310, 80, 3, 5],   // July: 5 / 4 = 1.25 -> 2
    ]);
    aiDown();

    $result = recommendOn('2026-10-01', 1310, [89, 80, 16]);

    expect($result['values'])->toBe([89 => 1, 80 => 2])
        ->and($result['method'])->toBe(Recommendations::METHOD_AVERAGE)
        ->and($result['note'])->toContain('4 เดือนล่าสุด')
        ->and($result['note'])->toContain('ใช้งาน AI Service ไม่ได้');

    // Calendar months counted from the first of this month; the series starts 3 months back (July).
    $series = collect($oracle->recorded)->last();
    expect($series['bindings'])->toBe(['2026-10-01', 2, '2026-10-01', -3, '2026-10-01']);
});

it('divides by the months there are: (20 + 5 + 5) / 3 = 10', function () {
    withdrawalHistory(3, [[1310, 80, 2, 20], [1310, 80, 1, 5], [1310, 80, 0, 5]]);
    aiDown();

    expect(recommendOn('2026-09-15', 1310, [80])['values'])->toBe([80 => 10]);
});

it('settles on the last 12 months once there is more than a year of history', function () {
    $rows = [[65, 1, 12, 24]]; // 13 months ago is no longer averaged
    foreach (range(0, 11) as $monthsAgo) {
        $rows[] = [65, 1, $monthsAgo, 1];
    }
    withdrawalHistory(13, $rows);
    aiDown();

    $result = recommendOn('2027-07-10', 65, [1]);

    expect($result['values'])->toBe([1 => 1])
        ->and($result['note'])->toContain('12 เดือนล่าสุด');
});

it('uses the AI forecast when the service has learnt from the history', function () {
    withdrawalHistory(20, [[1310, 89, 0, 2], [1310, 89, 5, 3], [65, 1, 1, 7]]);
    Http::fake(['*/forecast/material-stock' => Http::response([
        'success' => true, 'method' => 'ridge_regression', 'reason' => null,
        'recommendations' => [['key' => '1310:89', 'recommended' => 4, 'forecast' => 3.6]],
    ])]);

    $result = recommendOn('2026-10-01', 1310, [89]);

    expect($result['values'])->toBe([89 => 4])
        ->and($result['method'])->toBe(Recommendations::METHOD_AI)
        ->and($result['note'])->toContain('ประวัติการเบิก 20 เดือน');

    // Every store/material history goes to the service (one value per month of history, newest last).
    Http::assertSent(function (Request $request) {
        $series = collect($request['series'])->keyBy('key');

        return $request['targets'] === ['1310:89']
            && $series->keys()->sort()->values()->all() === ['1310:89', '65:1']
            && count($series['1310:89']['monthly']) === 20
            && $series['1310:89']['monthly'][19] === 2.0
            && $series['1310:89']['monthly'][14] === 3.0;
    });
});

it('passes on the AI service\'s reason when it keeps to the average', function () {
    withdrawalHistory(4, [[1310, 89, 3, 2]]);
    Http::fake(['*/forecast/material-stock' => Http::response([
        'success' => true, 'method' => 'moving_average_12m', 'reason' => 'INSUFFICIENT_HISTORY',
        'recommendations' => [['key' => '1310:89', 'recommended' => 1]],
    ])]);

    $result = recommendOn('2026-10-01', 1310, [89]);

    expect($result['values'])->toBe([89 => 1])
        ->and($result['note'])->toContain('ประวัติการเบิกยังน้อย');
});

it('adds up every organization\'s recommendation when all organizations are shown', function () {
    withdrawalHistory(4, [[1310, 80, 0, 5], [0, 80, 2, 13]]); // 5/4 -> 2, 13/4 -> 4
    aiDown();

    expect(recommendOn('2026-10-01', null, [80])['values'])->toBe([80 => 6]);
});

it('recommends nothing for materials not withdrawn in the averaged months, without asking the AI', function () {
    withdrawalHistory(20, [[1310, 89, 14, 9]]);

    expect(recommendOn('2026-10-01', 1310, [89])['values'])->toBe([]);
    Http::assertNothingSent();
});

it('recommends nothing before the first received withdrawal', function () {
    $oracle = withdrawalHistory(0, []);

    expect(recommendOn('2026-10-01', 1310, [89])['values'])->toBe([])
        ->and($oracle->recorded)->toHaveCount(1); // only the history check
    Http::assertNothingSent();
});

it('asks nothing for an empty page', function () {
    $oracle = recordingOracle();

    expect(app(Recommendations::class)->recommend(1310, [])['values'])->toBe([])
        ->and($oracle->recorded)->toBe([]);
});
