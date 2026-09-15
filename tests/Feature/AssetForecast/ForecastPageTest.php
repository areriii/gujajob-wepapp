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
        ->assertSeeInOrder(['พยากรณ์ล่วงหน้า', '1 ปี', '2 ปี', '3 ปี', 'หมวดครุภัณฑ์', 'หน่วยงาน', 'คำนวณ', 'จัดพิมพ์รายงาน', 'ผลการพยากรณ์'])
        ->assertSee('id="forecastCatGroup"', false)
        ->assertSee('id="forecastOrgId"', false)
        ->assertSee('placeholder="ทั้งหมด"', false)
        ->assertSee('id="forecastResult"', false)
        ->assertSee('data-forecast-url="/asset/ASS-003-manage-asset-registration/ai-forecast"', false)
        ->assertSee('data-print-url="/asset/ASS-003-manage-asset-registration/forecast-print"', false)
        ->assertSee(jsonText('ครุภัณฑ์กีฬา/กายภาพ'), false)
        ->assertSee(jsonText('กรมส่งเสริมสหกรณ์'), false)
        ->assertSee(jsonText('ทั้งหมด'), false)
        // Standard GUJAJOB back button back to ASS-003, and the blue ASS-003 card/button classes.
        ->assertSee('<a class="cancel-btn" href="' . route('asset.registrations.index') . '">ย้อนกลับ</a>', false)
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
