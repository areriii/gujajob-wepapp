<?php

/*
 * Offline tests for ASS-003 AI forecast (Laravel side).
 * Oracle is replaced by mocks of OrganizationVisibilityService and ReplacementForecastDataService;
 * the FastAPI service is replaced by Http::fake(). No test data touches the database.
 */

use App\Models\SysUser;
use App\Services\OrganizationVisibilityService;
use App\Services\ReplacementForecastDataService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const FORECAST_ENDPOINT = '/asset/ASS-003-manage-asset-registration/ai-forecast';
const FORECAST_PRINT    = '/asset/ASS-003-manage-asset-registration/forecast-print';
const VISIBLE_ORGS      = [9001, 9002, 9003];

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-14 10:00:00'));

    config([
        'services.ai_forecast.url'  => 'http://ai.test',
        'services.ai_forecast.demo' => false,
    ]);

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
        'asset_id'          => $id,
        'asset_code'        => sprintf('FX-C%03d', $id),
        'asset_name'        => "ครุภัณฑ์ทดสอบ {$id}",
        'category_id'       => 101,
        'category_name'     => 'เครื่องคอมพิวเตอร์ (ทดสอบ)',
        'category_group'    => 'ครุภัณฑ์คอมพิวเตอร์ (ทดสอบ)',
        'organization_id'   => 9001,
        'organization_name' => 'หน่วยงานทดสอบ ก (FIXTURE)',
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

/** Asset fields the FastAPI service echoes back (see _build_result in the AI service). */
const AI_ECHOED_ASSET_FIELDS = [
    'asset_id', 'asset_code', 'asset_name', 'category_id', 'category_name', 'category_group', 'organization_id',
    'organization_name', 'acceptance_date', 'acquisition_value', 'current_value', 'forecast_year',
];

/** One candidate per forecast year. */
function forecastCandidates(int $forecastYears, int $baseYear = 2026): array
{
    return array_map(fn (int $offset) => forecastCandidate($offset, $baseYear + $offset), range(1, $forecastYears));
}

function forecastTraining(): array
{
    return array_map(fn (int $i) => [
        'acquisition_year'  => 2016 + $i % 10,
        'category_id'       => 101,
        'category_name'     => 'เครื่องคอมพิวเตอร์ (ทดสอบ)',
        'acquisition_value' => 25000.0 + $i * 100,
    ], range(0, 19));
}

/** Fake FastAPI that answers from the request payload, following the real response contract. */
function fakeForecastAi(float $costPerAsset = 1234567.89): void
{
    Http::fake(['ai.test/*' => function (HttpRequest $request) use ($costPerAsset) {
        $data  = $request->data();
        $years = [];
        for ($year = $data['base_year'] + 1; $year <= $data['base_year'] + $data['forecast_years']; $year++) {
            $count   = count(array_filter($data['candidate_assets'], fn ($a) => $a['forecast_year'] === $year));
            $years[] = ['year' => $year, 'asset_count' => $count, 'forecast_budget' => round($count * $costPerAsset, 2)];
        }

        return Http::response([
            'success'               => true,
            'forecast_years'        => $data['forecast_years'],
            'base_year'             => $data['base_year'],
            'start_year'            => $data['base_year'] + 1,
            'end_year'              => $data['base_year'] + $data['forecast_years'],
            'total_assets'          => count($data['candidate_assets']),
            'total_forecast_budget' => round(array_sum(array_column($years, 'forecast_budget')), 2),
            'years'                 => $years,
            'assets'                => array_map(
                fn ($a) => array_intersect_key($a, array_flip(AI_ECHOED_ASSET_FIELDS))
                    + ['predicted_replacement_cost' => $costPerAsset, 'prediction_basis' => 'category_trend'],
                $data['candidate_assets'],
            ),
            'warnings'              => [],
            'model'                 => ['name' => 'Ridge Regression', 'training_records' => count($data['training_records'])],
        ]);
    }]);
}

it('attaches depreciation from the asset register to the AI result rows', function () {
    fakeForecastAi(31000.0);
    $this->data->shouldReceive('candidateAssets')->once()->andReturn([forecastCandidate(1, 2027)]);
    $this->data->shouldReceive('trainingRecords')->once()->andReturn(forecastTraining());

    $response = $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1])->assertOk();

    expect($response->json('assets.0'))->toMatchArray([
        'acquisition_value'          => 29000,
        'accumulated_depreciation'   => 23200,
        'current_value'              => 5800,
        'useful_life_years'          => 5,
        'end_of_life_date'           => '2027-01-10',
        'depreciation_method'        => 'straight_line_monthly_fiscal_year',
        'predicted_replacement_cost' => 31000,
    ])->and($response->json('depreciation'))->toMatchArray([
        'method'         => 'straight_line_monthly_fiscal_year',
        'residual_value' => 1,
        'as_of_date'     => '2026-09-14',
    ]);

    // Depreciation never becomes a model input: training records carry only year, category and price.
    Http::assertSent(fn (HttpRequest $request) => array_keys($request['training_records'][0])
        === ['acquisition_year', 'category_id', 'category_name', 'acquisition_value']);
});

// ---------------------------------------------------------------------------
// Successful forecasts
// ---------------------------------------------------------------------------

it('returns the AI forecast for each horizon', function (int $years) {
    fakeForecastAi();
    $candidates = forecastCandidates($years);

    $this->data->shouldNotReceive('categoryGroupExists');
    $this->data->shouldReceive('candidateAssets')->once()
        ->with($years, 2026, null, null, VISIBLE_ORGS)->andReturn($candidates);
    $this->data->shouldReceive('trainingRecords')->once()->with(VISIBLE_ORGS)->andReturn(forecastTraining());

    $response = $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => $years]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('forecast_years', $years)
        ->assertJsonPath('total_assets', $years)
        ->assertJsonPath('training_data_source', 'database')
        ->assertJsonCount($years, 'years')
        ->assertSessionHas('ai_forecast_latest')
        ->assertSessionHas('ai_forecast_params.forecast_years', $years)
        ->assertSessionHas('ai_forecast_params.filter_cat_name', 'ทั้งหมด')
        ->assertSessionHas('ai_forecast_params.filter_org_name', 'ทั้งหมด');

    expect(array_column($response->json('years'), 'year'))->toBe(range(2027, 2026 + $years))
        ->and($response->json('total_forecast_budget'))
        ->toEqual(round(array_sum(array_column($response->json('years'), 'forecast_budget')), 2));

    Http::assertSent(fn (HttpRequest $request) => $request['forecast_years'] === $years
        && $request['base_year'] === 2026
        && count($request['candidate_assets']) === $years
        && count($request['training_records']) === 20);
})->with([1, 2, 3]);

it('derives the forecast period from the current date instead of a fixed year', function () {
    $this->travelTo(Carbon::parse('2031-01-05 08:00:00'));
    fakeForecastAi();

    $this->data->shouldReceive('candidateAssets')->once()
        ->with(2, 2031, null, null, VISIBLE_ORGS)->andReturn(forecastCandidates(2, 2031));
    $this->data->shouldReceive('trainingRecords')->once()->andReturn(forecastTraining());

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 2])
        ->assertOk()
        ->assertJsonPath('years.0.year', 2032)
        ->assertJsonPath('years.1.year', 2033);

    Http::assertSent(fn (HttpRequest $request) => $request['base_year'] === 2031);
});

it('filters by หมวดครุภัณฑ์ (asscat_group) and organization for each horizon', function (int $years) {
    fakeForecastAi();
    $group = 'ครุภัณฑ์ไฟฟ้าและวิทยุ (ทดสอบ)';

    $this->data->shouldReceive('categoryGroupExists')->once()->with($group)->andReturnTrue();
    $this->data->shouldReceive('organizationName')->once()->with(9002)->andReturn('หน่วยงานทดสอบ ข (FIXTURE)');
    $this->data->shouldReceive('candidateAssets')->once()
        ->with($years, 2026, $group, 9002, VISIBLE_ORGS)
        ->andReturn([forecastCandidate(6, 2026 + $years, [
            'category_id'     => 105,
            'category_name'   => 'กล้องวงจรปิด (ทดสอบ)',
            'category_group'  => $group,
            'organization_id' => 9002,
        ])]);
    // Training data is never narrowed by the screen filters.
    $this->data->shouldReceive('trainingRecords')->once()->with(VISIBLE_ORGS)->andReturn(forecastTraining());

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => $years, 'filter_cat_group' => $group, 'filter_org_id' => 9002])
        ->assertOk()
        ->assertJsonPath('forecast_years', $years)
        ->assertJsonPath('total_assets', 1)
        ->assertJsonPath('assets.0.category_group', $group)
        ->assertSessionHas('ai_forecast_params.filter_cat_name', $group)
        ->assertSessionHas('ai_forecast_params.filter_org_name', 'หน่วยงานทดสอบ ข (FIXTURE)');

    Http::assertSent(fn (HttpRequest $request) => $request['candidate_assets'][0]['category_group'] === $group);
})->with([1, 2, 3]);

it('treats an empty category as ทั้งหมด', function () {
    fakeForecastAi();
    $this->data->shouldNotReceive('categoryGroupExists');
    $this->data->shouldReceive('candidateAssets')->once()
        ->with(1, 2026, null, null, VISIBLE_ORGS)->andReturn(forecastCandidates(1));
    $this->data->shouldReceive('trainingRecords')->once()->andReturn(forecastTraining());

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'filter_cat_group' => ''])
        ->assertOk()
        ->assertSessionHas('ai_forecast_params.filter_cat_name', 'ทั้งหมด');
});

it('returns zero budget for every year without calling the AI service when no asset is due', function () {
    Http::fake();
    $this->data->shouldReceive('candidateAssets')->once()->andReturn([]);
    $this->data->shouldNotReceive('trainingRecords');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 3])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('total_assets', 0)
        ->assertJsonPath('total_forecast_budget', 0)
        ->assertJsonPath('years', [
            ['year' => 2027, 'asset_count' => 0, 'forecast_budget' => 0],
            ['year' => 2028, 'asset_count' => 0, 'forecast_budget' => 0],
            ['year' => 2029, 'asset_count' => 0, 'forecast_budget' => 0],
        ])
        ->assertSessionHas('ai_forecast_latest');

    Http::assertNothingSent();
});

it('labels results that were trained on demo data', function () {
    config(['services.ai_forecast.demo' => true]);
    fakeForecastAi();

    $this->data->shouldReceive('candidateAssets')->once()->andReturn(forecastCandidates(1));
    $this->data->shouldReceive('trainingRecords')->once()->andReturn([]);

    $response = $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1])
        ->assertOk()
        ->assertJsonPath('training_data_source', 'demo');

    expect($response->json('warnings.0'))->toContain('DEMO');
    Http::assertSent(fn (HttpRequest $request) => count($request['training_records']) > 0);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('rejects an invalid forecast horizon', function (mixed $years) {
    Http::fake();
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => $years])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'INVALID_FORECAST_YEARS')
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ระยะเวลาพยากรณ์'))
        ->assertJsonValidationErrors('forecast_years');

    Http::assertNothingSent();
})->with([0, 4, -1, 'abc', null]);

it('rejects a malformed category filter with JSON instead of a redirect', function () {
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'filter_cat_group' => ['a', 'b']])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_REQUEST')
        ->assertJsonValidationErrors('filter_cat_group');
});

it('rejects an organization the user cannot see', function () {
    Http::fake();
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'filter_org_id' => 7777])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_ORGANIZATION');

    Http::assertNothingSent();
});

it('rejects a value that is not a หมวดครุภัณฑ์, such as an asset name', function () {
    $this->data->shouldReceive('categoryGroupExists')->once()->with('ลู่วิ่งออกกำลังกาย')->andReturnFalse();
    $this->data->shouldNotReceive('candidateAssets');

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1, 'filter_cat_group' => 'ลู่วิ่งออกกำลังกาย'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_CATEGORY');
});

// ---------------------------------------------------------------------------
// Failures
// ---------------------------------------------------------------------------

it('returns a friendly error when Oracle is unavailable', function (string $failingStep) {
    Http::fake();
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

    $response = $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1])
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'DATABASE_UNAVAILABLE')
        ->assertSessionMissing('ai_forecast_latest');

    expect($response->getContent())->not->toContain('ORA-')
        ->not->toContain('GUJAJOB')
        ->not->toContain('select');
    Http::assertNothingSent();
})->with(['visibility', 'candidates']);

it('returns 503 when the AI service is unreachable and clears the previous printable result', function () {
    Http::fake(['ai.test/*' => Http::failedConnection()]);
    $this->data->shouldReceive('candidateAssets')->andReturn(forecastCandidates(1));
    $this->data->shouldReceive('trainingRecords')->andReturn(forecastTraining());

    $this->withSession(['ai_forecast_latest' => ['success' => true], 'ai_forecast_params' => []])
        ->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1])
        ->assertStatus(503)
        ->assertJsonPath('code', 'AI_SERVICE_UNAVAILABLE')
        ->assertSessionMissing('ai_forecast_latest');
});

it('returns 504 when the AI service times out', function () {
    Http::fake(['ai.test/*' => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds')]);
    $this->data->shouldReceive('candidateAssets')->andReturn(forecastCandidates(1));
    $this->data->shouldReceive('trainingRecords')->andReturn(forecastTraining());

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1])
        ->assertStatus(504)
        ->assertJsonPath('code', 'AI_SERVICE_TIMEOUT');
});

it('forwards insufficient training data as a user-facing 422', function () {
    Http::fake(['ai.test/*' => Http::response([
        'success' => false,
        'code'    => 'INSUFFICIENT_TRAINING_DATA',
        'message' => 'ข้อมูลย้อนหลังไม่เพียงพอสำหรับการพยากรณ์ (พบ 3 รายการ ต้องการอย่างน้อย 10)',
    ], 422)]);
    $this->data->shouldReceive('candidateAssets')->andReturn(forecastCandidates(1));
    $this->data->shouldReceive('trainingRecords')->andReturn(array_slice(forecastTraining(), 0, 3));

    $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_TRAINING_DATA')
        ->assertSessionMissing('ai_forecast_latest');
});

it('never exposes Python errors from the AI service', function () {
    Http::fake(['ai.test/*' => Http::response('Traceback (most recent call last): ValueError at /srv/app.py', 500)]);
    $this->data->shouldReceive('candidateAssets')->andReturn(forecastCandidates(1));
    $this->data->shouldReceive('trainingRecords')->andReturn(forecastTraining());

    $response = $this->postJson(FORECAST_ENDPOINT, ['forecast_years' => 1])
        ->assertStatus(502)
        ->assertJsonPath('code', 'AI_SERVICE_ERROR');

    expect($response->getContent())->not->toContain('Traceback')->not->toContain('app.py');
});

// ---------------------------------------------------------------------------
// Print report
// ---------------------------------------------------------------------------

function printableForecast(): array
{
    return [
        'success'               => true,
        'forecast_years'        => 3,
        'base_year'             => 2026,
        'start_year'            => 2027,
        'end_year'              => 2029,
        'total_assets'          => 2,
        'total_forecast_budget' => 1234567.89,
        'years'                 => [
            ['year' => 2027, 'asset_count' => 1, 'forecast_budget' => 1200000.0],
            ['year' => 2028, 'asset_count' => 0, 'forecast_budget' => 0.0],
            ['year' => 2029, 'asset_count' => 1, 'forecast_budget' => 34567.89],
        ],
        'assets'                => [
            forecastCandidate(1, 2027, ['predicted_replacement_cost' => 1200000.0, 'prediction_basis' => 'category_trend']),
            forecastCandidate(2, 2029, [
                'asset_name'                 => '<script>alert(1)</script>',
                'predicted_replacement_cost' => 34567.89,
                'prediction_basis'           => 'asset_price_trend',
            ]),
        ],
        'warnings'              => ['ครุภัณฑ์ 1 รายการอยู่ในหมวดที่ไม่มีข้อมูลราคาย้อนหลัง'],
        'model'                 => [
            'name'                    => 'Ridge Regression',
            'training_records'        => 78,
            'annual_price_growth_pct' => 3.5,
            'evaluation_year'         => 2025,
            'mape'                    => 4.2,
        ],
        'training_data_source'  => 'database',
    ];
}

it('renders the printable report from the last calculation', function () {
    $response = $this->withSession([
        'ai_forecast_latest' => printableForecast(),
        'ai_forecast_params' => [
            'forecast_years'  => 3,
            'filter_cat_name' => 'ครุภัณฑ์คอมพิวเตอร์ (ทดสอบ)',
            'filter_org_name' => 'หน่วยงานทดสอบ ก (FIXTURE)',
            'calculated_at'   => '14/09/2569 10:00',
        ],
    ])->get(FORECAST_PRINT);

    $response->assertOk()
        ->assertSee('รายงานพยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน')
        ->assertSee('14/09/2569')
        ->assertSee('พ.ศ. 2570 – 2572')
        ->assertSeeInOrder(['หมวดครุภัณฑ์', 'ครุภัณฑ์คอมพิวเตอร์ (ทดสอบ)', 'หน่วยงาน'])
        ->assertSeeInOrder(['FX-C001', 'เครื่องคอมพิวเตอร์ (ทดสอบ)', 'ครุภัณฑ์คอมพิวเตอร์ (ทดสอบ)'])
        ->assertSee('หน่วยงานทดสอบ ก (FIXTURE)')
        ->assertSee('1,234,567.89')
        ->assertSee('1,200,000.00')
        ->assertSee('34,567.89')
        ->assertSee('29,000.00')
        ->assertSee('23,200.00')
        ->assertSee('5,800.00')
        ->assertSeeInOrder(['มูลค่า (บาท)', 'ค่าเสื่อมราคาสะสม (บาท)', 'มูลค่าคงเหลือ (บาท)', 'มูลค่าทดแทน (AI) (บาท)'])
        ->assertSee('ไม่ได้คำนวณจากมูลค่าคงเหลือ')
        ->assertDontSee('ราคาทุนเดิม')
        ->assertSee('FX-C001')
        ->assertSee('ประกอบการวางแผนงบประมาณ')
        ->assertSee('ครุภัณฑ์ 1 รายการอยู่ในหมวดที่ไม่มีข้อมูลราคาย้อนหลัง')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('sidebar', false)
        ->assertDontSee('forecastCalcBtn', false);
});

it('renders the printable report when no asset is due', function () {
    $this->withSession([
        'ai_forecast_latest' => app(\App\Services\ReplacementBudgetForecastService::class)->emptyResult(1, 2026),
        'ai_forecast_params' => ['forecast_years' => 1, 'filter_cat_name' => 'ทั้งหมด', 'filter_org_name' => 'ทั้งหมด'],
    ])->get(FORECAST_PRINT)
        ->assertOk()
        ->assertSee('ไม่พบครุภัณฑ์ที่คาดว่าจะถึงกำหนดทดแทนในช่วงเวลาที่เลือก')
        ->assertSee('0.00');
});

it('returns 404 when printing before any successful calculation', function (?array $stored) {
    $this->withSession(['ai_forecast_latest' => $stored])
        ->get(FORECAST_PRINT)
        ->assertNotFound();
})->with([
    'nothing stored' => [null],
    'failed result'  => [['success' => false, 'code' => 'AI_SERVICE_UNAVAILABLE']],
]);
