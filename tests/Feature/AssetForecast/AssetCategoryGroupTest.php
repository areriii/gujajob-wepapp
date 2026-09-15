<?php

/*
 * The forecast "หมวดครุภัณฑ์" dropdown and category filter use ASSET_CATEGORY.asscat_group.
 *
 * Oracle is offline, so the 'oracle' connection is replaced by a recording Oci8Connection:
 * queries are compiled with the real yajra OracleGrammar, recorded, and answered with canned
 * rows. The connection never opens a database session and nothing is written anywhere.
 */

use App\Models\AssetCategory;
use App\Services\ReplacementForecastDataService;
use Illuminate\Support\Facades\DB;

afterEach(fn () => DB::purge('oracle'));

// ---------------------------------------------------------------------------
// Dropdown source
// ---------------------------------------------------------------------------

it('loads หมวดครุภัณฑ์ names from ASSET_CATEGORY.asscat_group, not asset names', function () {
    $oracle = recordingOracle([
        (object) ['group_name' => 'ครุภัณฑ์กีฬา'],
        (object) ['group_name' => 'ครุภัณฑ์สำนักงาน'],
    ]);

    expect(AssetCategory::groupNames())->toBe(['ครุภัณฑ์กีฬา', 'ครุภัณฑ์สำนักงาน'])
        ->and($oracle->recorded)->toHaveCount(1);

    expect($oracle->recorded[0]['sql'])
        ->toContain('ASSET_CATEGORY')
        ->toContain('DISTINCT TRIM(asscat_group) AS group_name')
        ->toContain('LENGTH(TRIM(asscat_group)) > 0')
        ->toContain('order by group_name')
        ->not->toContain('asscat_name');
});

it('builds dropdown options whose value and visible label are the category name', function () {
    recordingOracle([
        (object) ['group_name' => 'ครุภัณฑ์กีฬา'],
        (object) ['group_name' => 'ครุภัณฑ์ไฟฟ้าและวิทยุ'],
    ]);

    expect(AssetCategory::groupOptions())->toBe([
        ['value' => 'ครุภัณฑ์กีฬา', 'label' => 'ครุภัณฑ์กีฬา', 'searchText' => 'ครุภัณฑ์กีฬา'],
        ['value' => 'ครุภัณฑ์ไฟฟ้าและวิทยุ', 'label' => 'ครุภัณฑ์ไฟฟ้าและวิทยุ', 'searchText' => 'ครุภัณฑ์ไฟฟ้าและวิทยุ'],
    ]);
});

it('renders the forecast dropdown with the ทั้งหมด placeholder and category options', function () {
    $options  = [['value' => 'ครุภัณฑ์กีฬา', 'label' => 'ครุภัณฑ์กีฬา', 'searchText' => 'ครุภัณฑ์กีฬา']];
    $jsonText = fn (string $value) => trim(json_encode($value), '"');

    $this->blade(
        '<x-searchable-select name="forecast_cat_group" id="forecastCatGroup" placeholder="ทั้งหมด" :options="$options" selected="" />',
        ['options' => $options],
    )
        ->assertSee('placeholder="ทั้งหมด"', false)
        ->assertSee('id="forecastCatGroup"', false)
        ->assertSee('value=""', false)
        ->assertSee($jsonText('ครุภัณฑ์กีฬา'), false);
});

it('validates a selected category against asscat_group', function () {
    $oracle = recordingOracle([(object) ['exists' => 1]]);

    expect(AssetCategory::groupExists('  ครุภัณฑ์กีฬา '))->toBeTrue();
    expect($oracle->recorded[0]['sql'])->toContain('TRIM(asscat_group) = ?')->not->toContain('asscat_name');
    expect($oracle->recorded[0]['bindings'])->toBe(['ครุภัณฑ์กีฬา']);

    $oracle->cannedRows = [];
    expect(AssetCategory::groupExists('ลู่วิ่งออกกำลังกาย'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Forecast candidate query
// ---------------------------------------------------------------------------

it('filters forecast candidates by หมวดครุภัณฑ์ and organization', function (int $years) {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-14 10:00:00'));
    $oracle = recordingOracle([(object) [
        'id'                  => 1,
        'ass_code'            => 'FX-0001',
        'ass_desc'            => null,
        'category_id'         => 101,
        'category_name'       => 'ลู่วิ่งออกกำลังกาย',
        'category_group'      => 'ครุภัณฑ์กีฬา',
        'organization_id'     => 9002,
        'organization_name'   => 'หน่วยงานทดสอบ ข (FIXTURE)',
        'acceptance_date'     => '01/01/2565',
        'inspect_date_iso'    => '2022-01-01',
        'acquisition_year'    => 2022,
        'useful_life_years'   => 5,
        'acquisition_value'   => '55000',
        'stored_remain_price' => '55000',
        'forecast_year'       => 2026 + $years,
    ]]);

    $candidates = (new ReplacementForecastDataService())
        ->candidateAssets($years, 2026, 'ครุภัณฑ์กีฬา', 9002, [9001, 9002]);

    ['sql' => $sql, 'bindings' => $bindings] = $oracle->recorded[0];

    expect($sql)
        ->toContain('TRIM(c.asscat_group) = ?')
        ->toContain('TRIM(c.asscat_group) AS category_group')
        // Replacement year = end of the useful life under the project's depreciation rules.
        ->toContain(\App\Services\AssetDepreciationCalculator::usefulLifeEndSql('a.inspect_date', 'a.ass_lifetime'));

    expect($bindings)
        ->toContain('ครุภัณฑ์กีฬา')
        ->toContain(9002)
        ->toContain(2027)
        ->toContain(2026 + $years)
        ->not->toContain(101);

    expect($candidates[0]['category_group'])->toBe('ครุภัณฑ์กีฬา')
        ->and($candidates[0]['category_name'])->toBe('ลู่วิ่งออกกำลังกาย')
        ->and($candidates[0]['forecast_year'])->toBe(2026 + $years);

    // มูลค่าคงเหลือ comes from AssetDepreciationCalculator, not from the stored remain_price column.
    $expected = (new \App\Services\AssetDepreciationCalculator())->valueAsOf(55000, 5, '2022-01-01', '2026-09-14');
    expect($sql)->toContain("TO_CHAR(a.inspect_date, 'YYYY-MM-DD') AS inspect_date_iso");
    expect($candidates[0])->toMatchArray([
        'inspect_date'             => '2022-01-01',
        'useful_life_years'        => 5,
        'depreciation_start_date'  => '2022-01-01',
        'end_of_life_date'         => '2026-12-31',
        'accumulated_depreciation' => $expected['accumulated_depreciation'],
        'current_value'            => $expected['remaining_value'],
        'stored_remain_price'      => 55000.0,
        'depreciation_method'      => 'straight_line_monthly_fiscal_year',
    ])->and($candidates[0]['current_value'])->toBeLessThan(55000.0);
})->with([1, 2, 3]);

it('applies no category restriction when ทั้งหมด is selected', function () {
    $oracle = recordingOracle();

    (new ReplacementForecastDataService())->candidateAssets(3, 2026, null, null, [9001, 9002]);

    expect($oracle->recorded[0]['sql'])->not->toContain('TRIM(c.asscat_group) = ?')
        ->and($oracle->recorded[0]['bindings'])->not->toContain('ครุภัณฑ์กีฬา');
});
