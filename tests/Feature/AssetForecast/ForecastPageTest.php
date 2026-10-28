<?php

/*
 * The AI forecast lives on its own page; ASS-003 only links to it.
 * Oracle is replaced by recordingOracle() (tests/Pest.php); Vite assets are not loaded.
 */

use App\Models\SysUser;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs((new SysUser())->forceFill(['id' => 501, 'org_id' => 382, 'user_name' => 'forecast tester']));
});

afterEach(fn () => DB::purge('oracle'));

function jsonText(string $value): string
{
    return trim(json_encode($value), '"');
}

it('opens the dedicated forecast page with its filters, actions and result area', function () {
    $oracle = recordingOracle(function (string $sql) {
        if (str_contains($sql, 'group_name')) {
            return [(object) ['group_name' => 'ครุภัณฑ์กีฬา/กายภาพ'], (object) ['group_name' => 'ครุภัณฑ์อาวุธ']];
        }
        if (str_contains($sql, "UPPER(zone_flg)='C'")) {
            return [(object) ['org_id' => 3643, 'org_name' => 'กรมส่งเสริมสหกรณ์']];
        }
        if (str_contains($sql, 'zone_flg')) {
            return [(object) ['org_id' => 382, 'zone_flg' => 'C']];
        }

        return [];
    });

    $this->get('/asset/ASS-003-manage-asset-registration/replacement-forecast')
        ->assertOk()
        ->assertSee('<h2>พยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน</h2>', false)
        ->assertSeeInOrder(['พยากรณ์ล่วงหน้า', '1 ปี', '2 ปี', '3 ปี', 'หมวดครุภัณฑ์', 'หน่วยงาน', 'เพิ่มจากมูลค่าตอนซื้อ (%)', 'คำนวณ', 'ผลการพยากรณ์', 'ย้อนกลับ'])
        ->assertDontSee('forecastPrintBtn', false)
        ->assertSee('id="forecastPercent" type="number" min="0" max="1000" step="0.01" value="5"', false)
        ->assertSee('id="forecastCatGroup"', false)
        ->assertSee('id="forecastOrgId"', false)
        ->assertSee('placeholder="ทั้งหมด"', false)
        ->assertSee('id="forecastResult"', false)
        ->assertSee('data-forecast-url="/asset/ASS-003-manage-asset-registration/replacement-forecast/calculate"', false)
        ->assertSee('data-assets-url="/asset/ASS-003-manage-asset-registration/replacement-forecast/assets"', false)
        ->assertSee(jsonText('ครุภัณฑ์กีฬา/กายภาพ'), false)
        ->assertSee(jsonText('กรมส่งเสริมสหกรณ์'), false)
        ->assertSee(jsonText('ทั้งหมด'), false)
        // Standard GUJAJOB back button back to ASS-003, and the blue ASS-003 card/button classes.
        // ย้อนกลับ sits at the bottom right of the ผลการพยากรณ์ frame
        ->assertSeeInOrder(['id="forecastResult"', 'class="form-actions forecast-frame-actions"', '<a class="cancel-btn" href="' . route('asset.registrations.index') . '">ย้อนกลับ</a>', '</section>'], false)
        ->assertSee('class="registration-card forecast-filter-card"', false)
        ->assertSee('class="create-btn" type="button" id="forecastCalcBtn"', false)
        ->assertDontSee('กลับไปหน้าจัดการทะเบียนครุภัณฑ์')
        ->assertDontSee('forecast-card', false)
        // Sidebar keeps "จัดการทะเบียนครุภัณฑ์" active for this page.
        ->assertSee('menu-item active" href="' . route('asset.registrations.index') . '"', false);

    expect(collect($oracle->recorded)->pluck('sql')->implode("\n"))
        ->toContain('asscat_group')
        ->not->toContain('asscat_name');
});

it('shows the last calculation again when coming back from the asset list (?restore=1)', function () {
    recordingOracle(fn (string $sql) => str_contains($sql, 'group_name')
        ? [(object) ['group_name' => 'ครุภัณฑ์คอมพิวเตอร์']]
        : (str_contains($sql, 'zone_flg') ? [(object) ['org_id' => 382, 'zone_flg' => 'C']] : []));
    $session = [
        'replacement_forecast_latest' => ['success' => true, 'forecast_years' => 3, 'total_assets' => 1, 'total_forecast_budget' => 32625, 'years' => [['year' => 2027, 'asset_count' => 1, 'forecast_budget' => 32625]]],
        'replacement_forecast_params' => ['forecast_years' => 3, 'increase_percent' => 12.5, 'filter_cat_group' => 'ครุภัณฑ์คอมพิวเตอร์', 'filter_org_id' => 382],
    ];

    $this->withSession($session)->get('/asset/ASS-003-manage-asset-registration/replacement-forecast?restore=1')
        ->assertOk()
        ->assertSee('<script type="application/json" id="forecastRestored">', false)
        ->assertSee('"total_forecast_budget":32625', false)
        // The conditions of that calculation are filled in again.
        ->assertSee('<option value="3" selected>3 ปี</option>', false)
        ->assertSeeInOrder(['id="forecastCatGroup"', 'value="ครุภัณฑ์คอมพิวเตอร์"', 'id="forecastOrgId"', 'value="382"'], false)
        ->assertSee('id="forecastPercent" type="number" min="0" max="1000" step="0.01" value="12.5"', false);

    // Opened from the menu (no ?restore) the page starts empty, with the default conditions.
    $this->withSession($session)->get('/asset/ASS-003-manage-asset-registration/replacement-forecast')
        ->assertOk()
        ->assertDontSee('forecastRestored', false)
        ->assertSee('<option value="1" selected>1 ปี</option>', false)
        ->assertSee('id="forecastPercent" type="number" min="0" max="1000" step="0.01" value="5"', false);

    // Nothing to restore without a successful calculation (a failed one clears the session too).
    session()->forget(['replacement_forecast_latest', 'replacement_forecast_params']);
    $this->get('/asset/ASS-003-manage-asset-registration/replacement-forecast?restore=1')
        ->assertOk()
        ->assertDontSee('forecastRestored', false);
});

it('shows only a link to the forecast page on the ASS-003 list', function () {
    $this->view('asset.ASS-003-manage-asset-registration.index', [
        'pageTitle'    => 'จัดการทะเบียนครุภัณฑ์',
        'assets'       => new LengthAwarePaginator([], 0, 10),
        'searchBy'     => 'all',
        'keyword'      => '',
        'statusFilter' => '',
        'sort'         => '',
        'direction'    => 'asc',
    ])
        ->assertSee('href="' . route('asset.registrations.replacement-forecast') . '"', false)
        ->assertSee('พยากรณ์งบประมาณทดแทน')
        ->assertDontSee('forecastSection', false)
        ->assertDontSee('forecastCalcBtn', false)
        ->assertDontSee('forecastCatGroup', false)
        ->assertDontSee('ASS-003-manage-asset-registration/forecast.js', false);
});
