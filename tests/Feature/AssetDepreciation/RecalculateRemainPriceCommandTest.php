<?php

/*
 * asset:recalculate-remain-price is a dry run unless --apply is given.
 * Oracle is replaced by recordingOracle() (tests/Pest.php): selects are answered with canned rows and any
 * write would reach the fake PDO, which throws — so a passing dry run proves nothing was written.
 */

use Illuminate\Support\Facades\DB;

afterEach(fn () => DB::purge('oracle'));

it('lists assets whose stored remain_price differs from the depreciation rules without writing', function () {
    $oracle = recordingOracle([
        (object) ['id' => 87, 'ass_code' => 'AI-TEST-007', 'ass_price' => '25400', 'ass_lifetime' => '5', 'remain_price' => '25400', 'ass_status' => '2', 'inspect_date_iso' => '2022-03-15'],
        (object) ['id' => 81, 'ass_code' => 'AI-TEST-001', 'ass_price' => '20100', 'ass_lifetime' => '5', 'remain_price' => '1', 'ass_status' => '2', 'inspect_date_iso' => '2016-03-15'],
        (object) ['id' => 9, 'ass_code' => 'NO-DATE', 'ass_price' => '5000', 'ass_lifetime' => '5', 'remain_price' => '5000', 'ass_status' => '1', 'inspect_date_iso' => null],
    ]);

    $expected = (new \App\Services\AssetDepreciationCalculator())->valueAsOf(25400, 5, '2022-03-15', '2026-09-14');

    $this->artisan('asset:recalculate-remain-price', ['--as-of' => '2026-09-14'])
        ->expectsOutputToContain('1 asset(s) with a different remain_price')
        // The table row for AI-TEST-007 (one output line) carries the recalculated value.
        ->expectsOutputToContain((string) $expected['remaining_value'])
        ->expectsOutputToContain('Dry run')
        ->doesntExpectOutputToContain('AI-TEST-001')
        ->doesntExpectOutputToContain('NO-DATE')
        ->assertSuccessful();

    expect($oracle->recorded)->toHaveCount(1)
        ->and(strtolower($oracle->recorded[0]['sql']))->toStartWith('select')->not->toContain('update');
});
