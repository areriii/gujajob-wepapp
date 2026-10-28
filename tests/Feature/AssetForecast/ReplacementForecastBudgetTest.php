<?php

/*
 * ASS-003 replacement budget forecast: assets due for replacement (from the asset register) are budgeted
 * at their purchase value plus the percentage entered on the page.
 * Oracle is replaced by mocks of OrganizationVisibilityService and ReplacementForecastDataService;
 * no AI service is involved and no test data touches the database.
 */

use App\Models\SysUser;
use App\Services\OrganizationVisibilityService;
use App\Services\ReplacementForecastDataService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const FORECAST_ENDPOINT = '/asset/ASS-003-manage-asset-registration/replacement-forecast/calculate';
const FORECAST_PRINT    = '/asset/ASS-003-manage-asset-registration/forecast-print';
const VISIBLE_ORGS      = [9001, 9002, 9003];

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-14 10:00:00'));
    Http::preventStrayRequests();

    $this->actingAs((new SysUser())->forceFill(['id' => 501, 'org_id' => 9001, 'user_name' => 'forecast tester']));

    $this->visibility = Mockery::mock(OrganizationVisibilityService::class);
    $this->visibility->shouldReceive('visibleOrgIds')->with(9001)->andReturn(VISIBLE_ORGS)->byDefault();
    $this->app->instance(OrganizationVisibilityService::class, $this->visibility);

    $this->data = Mockery::mock(ReplacementForecastDataService::class);
    $this->app->instance(ReplacementForecastDataService::class, $this->data);
});

function forecastCandidate(int $id, int $forecastYear, array $overrides = []): array
{
    return array_merge([
        'asset_id'                 => $id,
        'asset_code'               => sprintf('FX-C%03d', $id),
        'asset_name'               => "ครุภัณฑ์ทดสอบ {$id}",
        'category_id'              => 101,
        'category_name'            => 'เครื่องคอมพิวเตอร์ (ทดสอบ)',
        'category_group'           => 'ครุภัณฑ์คอมพิวเตอร์ (ทดสอบ)',
        'organization_id'          => 9001,
        'organization_name'        => 'หน่วยงานทดสอบ ก (FIXTURE)',
        'acceptance_date'          => '10/01/2565',
        'inspect_date'             => '2022-01-10',
        'acquisition_year'         => 2022,
        'acquisition_value'        => 29000.0,
        'useful_life_years'        => 5,
        'end_of_life_date'         => '2027-01-10',
        'annual_depreciation'      => 5799.8,
        'accumulated_depreciation' => 23200.0,
        'current_value'            => 5800.0,
        'stored_remain_price'      => 29000.0,
        'depreciation_method'      => 'straight_line_monthly_fiscal_year',
        'forecast_year'            => $forecastYear,
    ], $overrides);
}

/** One candidate per forecast year. */
function forecastCandidates(int $forecastYears, int $baseYear = 2026): array
{
    return array_map(fn (int $offset) => forecastCandidate($offset, $baseYear + $offset), range(1, $forecastYears));
}

// ---------------------------------------------------------------------------
// Budget = purchase value + percentage
// ---------------------------------------------------------------------------

it('budgets each asset at its purchase value plus the percentage, once', function () {
    $this->data->shouldReceive('candidateAssets')->once()->with(3, 2026, null, null, VISIBLE_ORGS)->andReturn([
        forecastCandidate(1, 2027),                                    // 29,000 x 1.05 = 30,450
        forecastCandidate(2, 2027, ['acquisition_value' => 25000.0]),  // 26,250
        forecastCandidate(3, 2029, ['acquisition_value' => 1000.0]),   // 1,050
    ]);

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 3, 'increase_percent' => 5])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('increase_percent', 5)
        ->assertJsonPath('assets.0.replacement_budget', 30450)
        ->assertJsonPath('assets.1.replacement_budget', 26250)
        ->assertJsonPath('years', [
            ['year' => 2027, 'asset_count' => 2, 'forecast_budget' => 56700],
            ['year' => 2028, 'asset_count' => 0, 'forecast_budget' => 0],
            ['year' => 2029, 'asset_count' => 1, 'forecast_budget' => 1050],
        ])
        ->assertJsonPath('total_assets', 3)
        ->assertJsonPath('total_forecast_budget', 57750)
        // depreciation stays from the asset register
        ->assertJsonPath('assets.0.accumulated_depreciation', 23200)
        ->assertJsonPath('assets.0.current_value', 5800)
        ->assertSessionHas('replacement_forecast_latest.total_forecast_budget', 57750)
        ->assertSessionHas('replacement_forecast_params.increase_percent', 5);

    Http::assertNothingSent();
});

it('uses the percentage entered on the page', function (int|string $percent, int $budget) {
    $this->data->shouldReceive('candidateAssets')->once()->andReturn(forecastCandidates(1));

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'increase_percent' => $percent])
        ->assertOk()
        ->assertJsonPath('assets.0.replacement_budget', $budget)
        ->assertJsonPath('total_forecast_budget', $budget);
})->with([
    '0%'    => [0, 29000],
    '12.5%' => ['12.5', 32625],
    '100%'  => [100, 58000],
]);

it('derives the forecast period from the current date instead of a fixed year', function () {
    $this->travelTo(Carbon::parse('2027-02-01 09:00:00'));
    $this->data->shouldReceive('candidateAssets')->once()->with(2, 2027, null, null, VISIBLE_ORGS)->andReturn([]);

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 2, 'increase_percent' => 5])
        ->assertOk()
        ->assertJsonPath('start_year', 2028)
        ->assertJsonPath('end_year', 2029);
});

it('filters by หมวดครุภัณฑ์ (asscat_group) and organization', function () {
    $group = 'ครุภัณฑ์ไฟฟ้าและวิทยุ (ทดสอบ)';
    $this->data->shouldReceive('categoryGroupExists')->once()->with($group)->andReturnTrue();
    $this->data->shouldReceive('organizationName')->once()->with(9002)->andReturn('หน่วยงานทดสอบ ข (FIXTURE)');
    $this->data->shouldReceive('candidateAssets')->once()
        ->with(2, 2026, $group, 9002, VISIBLE_ORGS)
        ->andReturn([forecastCandidate(6, 2028, ['category_group' => $group, 'organization_id' => 9002])]);

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 2, 'increase_percent' => 5, 'filter_cat_group' => $group, 'filter_org_id' => 9002])
        ->assertOk()
        ->assertJsonPath('total_assets', 1)
        ->assertJsonPath('assets.0.category_group', $group)
        ->assertSessionHas('replacement_forecast_params.filter_cat_name', $group)
        ->assertSessionHas('replacement_forecast_params.filter_org_name', 'หน่วยงานทดสอบ ข (FIXTURE)')
        // The raw filter values fill the page in again when coming back from the asset list.
        ->assertSessionHas('replacement_forecast_params.filter_cat_group', $group)
        ->assertSessionHas('replacement_forecast_params.filter_org_id', 9002);
});

it('treats an empty category as ทั้งหมด', function () {
    $this->data->shouldNotReceive('categoryGroupExists');
    $this->data->shouldReceive('candidateAssets')->once()->with(1, 2026, null, null, VISIBLE_ORGS)->andReturn(forecastCandidates(1));

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'increase_percent' => 5, 'filter_cat_group' => ''])
        ->assertOk()
        ->assertSessionHas('replacement_forecast_params.filter_cat_name', 'ทั้งหมด');
});

it('returns zero budget for every year when no asset is due', function () {
    $this->data->shouldReceive('candidateAssets')->once()->andReturn([]);

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 3, 'increase_percent' => 5])
        ->assertOk()
        ->assertJsonPath('total_assets', 0)
        ->assertJsonPath('total_forecast_budget', 0)
        ->assertJsonPath('years', [
            ['year' => 2027, 'asset_count' => 0, 'forecast_budget' => 0],
            ['year' => 2028, 'asset_count' => 0, 'forecast_budget' => 0],
            ['year' => 2029, 'asset_count' => 0, 'forecast_budget' => 0],
        ])
        ->assertSessionHas('replacement_forecast_latest');
});

it('leaves out of the budget, with a warning, an asset without a purchase value', function () {
    $this->data->shouldReceive('candidateAssets')->once()->andReturn([
        forecastCandidate(1, 2027),
        forecastCandidate(2, 2027, ['acquisition_value' => null]),
    ]);

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'increase_percent' => 5])
        ->assertOk()
        ->assertJsonPath('total_assets', 2)
        ->assertJsonPath('assets.1.replacement_budget', null)
        ->assertJsonPath('total_forecast_budget', 30450)
        ->assertJsonPath('warnings.0', 'ครุภัณฑ์ 1 รายการไม่มีมูลค่าตอนซื้อในทะเบียน จึงไม่ได้นับรวมในงบประมาณ');
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('rejects an invalid forecast horizon', function (mixed $years) {
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => $years, 'increase_percent' => 5])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'INVALID_FORECAST_YEARS')
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ระยะเวลาพยากรณ์'))
        ->assertJsonValidationErrors('forecast_years');
})->with([0, 4, -1, 'abc', null]);

it('rejects a missing or out-of-range percentage', function (mixed $percent) {
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'increase_percent' => $percent])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_PERCENT')
        ->assertJsonPath('message', fn (string $message) => str_contains($message, '% ที่เพิ่มจากมูลค่าตอนซื้อ'))
        ->assertJsonValidationErrors('increase_percent');
})->with([null, '', -1, 1001, 'abc']);

it('rejects a malformed category filter with JSON instead of a redirect', function () {
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'increase_percent' => 5, 'filter_cat_group' => ['a', 'b']])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_REQUEST')
        ->assertJsonValidationErrors('filter_cat_group');
});

it('rejects an organization the user cannot see', function () {
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'increase_percent' => 5, 'filter_org_id' => 7777])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_ORGANIZATION');
});

it('rejects a value that is not a หมวดครุภัณฑ์, such as an asset name', function () {
    $this->data->shouldReceive('categoryGroupExists')->once()->with('ลู่วิ่งออกกำลังกาย')->andReturnFalse();
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'increase_percent' => 5, 'filter_cat_group' => 'ลู่วิ่งออกกำลังกาย'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_CATEGORY');
});

// ---------------------------------------------------------------------------
// Failures
// ---------------------------------------------------------------------------

it('returns a friendly error when Oracle is unavailable and clears the previous result', function (string $failingStep) {
    $oracleError = new QueryException(
        'oracle',
        'select * from ASSET',
        [],
        new RuntimeException('ORA-12541: TNS:no listener (host=10.0.0.5 user=GUJAJOB)'),
    );

    if ($failingStep === 'visibility') {
        $this->visibility->shouldReceive('visibleOrgIds')->andThrow($oracleError);
    } else {
        $this->data->shouldReceive('candidateAssets')->andThrow($oracleError);
    }

    $response = $this->withSession(['replacement_forecast_latest' => ['success' => true], 'replacement_forecast_params' => []])
        ->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'increase_percent' => 5])
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'DATABASE_UNAVAILABLE')
        ->assertSessionMissing('replacement_forecast_latest');

    expect($response->getContent())->not->toContain('ORA-')
        ->not->toContain('GUJAJOB')
        ->not->toContain('select');
})->with(['visibility', 'candidates']);

// ---------------------------------------------------------------------------
// Print report
// ---------------------------------------------------------------------------

function printableForecast(): array
{
    return app(\App\Services\ReplacementBudgetCalculator::class)->calculate(3, 2026, 5.0, [
        forecastCandidate(1, 2027, ['acquisition_value' => 1200000.0]),
        forecastCandidate(2, 2029, ['asset_name' => '<script>alert(1)</script>']),
    ]) + ['depreciation' => ['as_of_date' => '2026-09-14']];
}

it('renders the printable report from the last calculation', function () {
    $response = $this->withSession([
        'replacement_forecast_latest' => printableForecast(),
        'replacement_forecast_params' => [
            'forecast_years'   => 3,
            'increase_percent' => 5.0,
            'filter_cat_name'  => 'ครุภัณฑ์คอมพิวเตอร์ (ทดสอบ)',
            'filter_org_name'  => 'หน่วยงานทดสอบ ก (FIXTURE)',
            'calculated_at'    => '14/09/2569 10:00',
        ],
    ])->get(FORECAST_PRINT);

    $response->assertOk()
        ->assertSee('รายงานพยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน')
        ->assertSee('งบประมาณทดแทน = มูลค่าตอนซื้อ + 5%')
        ->assertSee('14/09/2569')
        ->assertSee('พ.ศ. 2570 – 2572')
        ->assertSeeInOrder(['เพิ่มจากมูลค่าตอนซื้อ', '5%', 'หมวดครุภัณฑ์', 'ครุภัณฑ์คอมพิวเตอร์ (ทดสอบ)', 'หน่วยงาน'])
        ->assertSeeInOrder(['มูลค่า (บาท)', 'ค่าเสื่อมราคาสะสม (บาท)', 'มูลค่าคงเหลือ (บาท)', 'งบประมาณทดแทน (+5%) (บาท)'])
        ->assertSeeInOrder(['FX-C001', '1,200,000.00', '23,200.00', '5,800.00', '1,260,000.00'])
        ->assertSeeInOrder(['FX-C002', '29,000.00', '30,450.00'])
        ->assertSee('1,290,450.00')
        ->assertDontSee('AI')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('sidebar', false)
        ->assertDontSee('forecastCalcBtn', false);
});

it('renders the printable report when no asset is due', function () {
    $this->withSession([
        'replacement_forecast_latest' => app(\App\Services\ReplacementBudgetCalculator::class)->calculate(1, 2026, 5.0, []),
        'replacement_forecast_params' => ['forecast_years' => 1, 'filter_cat_name' => 'ทั้งหมด', 'filter_org_name' => 'ทั้งหมด'],
    ])->get(FORECAST_PRINT)
        ->assertOk()
        ->assertSee('ไม่พบครุภัณฑ์ที่คาดว่าจะถึงกำหนดทดแทนในช่วงเวลาที่เลือก')
        ->assertSee('0.00');
});

it('returns 404 when printing before any successful calculation', function (?array $stored) {
    $this->withSession(['replacement_forecast_latest' => $stored])
        ->get(FORECAST_PRINT)
        ->assertNotFound();
})->with([
    'nothing stored' => [null],
    'failed result'  => [['success' => false, 'code' => 'DATABASE_UNAVAILABLE']],
]);
