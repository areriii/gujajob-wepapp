<?php

/*
 * Forecast asset list opened from the yearly table of the forecast page: one replacement year,
 * or "แสดงทั้งหมด" for the whole forecast period. It lists the last calculation kept in the session.
 */

use App\Models\SysUser;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs((new SysUser())->forceFill(['id' => 501, 'org_id' => 382, 'user_name' => 'forecast tester']));
    recordingOracle();
});

afterEach(fn () => DB::purge('oracle'));

function forecastAsset(string $code, int $year, ?float $budget): array
{
    return [
        'asset_id' => crc32($code), 'asset_code' => $code, 'category_name' => "ชื่อ {$code}", 'category_group' => 'ครุภัณฑ์คอมพิวเตอร์',
        'organization_name' => 'ฝ่ายพัสดุ', 'acceptance_date' => '15/11/2565', 'inspect_date' => '2022-11-15', 'forecast_year' => $year,
        'acquisition_value' => 25000, 'accumulated_depreciation' => 24999, 'current_value' => 1,
        'replacement_budget' => $budget,
    ];
}

function withLatestForecast(): void
{
    session([
        'replacement_forecast_latest' => [
            'success' => true, 'forecast_years' => 2, 'start_year' => 2027, 'end_year' => 2028, 'increase_percent' => 5.0,
            'years' => [['year' => 2027, 'asset_count' => 2, 'forecast_budget' => 50000], ['year' => 2028, 'asset_count' => 1, 'forecast_budget' => 0]],
            'assets' => [
                forecastAsset('7440-001-0001', 2027, 30000),
                forecastAsset('7440-001-0002', 2027, 20000),
                forecastAsset('7110-002-0009', 2028, null),
            ],
            'depreciation' => ['as_of_date' => '2026-10-01'],
        ],
        'replacement_forecast_params' => ['forecast_years' => 2, 'increase_percent' => 5.0, 'filter_cat_name' => 'ทั้งหมด', 'filter_org_name' => 'ฝ่ายพัสดุ', 'calculated_at' => '01/10/2569 10:00'],
    ]);
}

it('lists the assets of one replacement year', function () {
    withLatestForecast();

    $this->get(route('asset.registrations.forecast-assets', ['year' => 2027]))
        ->assertOk()
        ->assertSee('<h2>รายการครุภัณฑ์ที่ต้องจัดซื้อทดแทน</h2>', false)
        ->assertSee('ครบกำหนดทดแทนปี พ.ศ. 2570')
        ->assertSeeInOrder(['7440-001-0001', '7440-001-0002'])
        ->assertDontSee('7110-002-0009')
        ->assertSee('2 รายการ')
        ->assertSee('50,000.00 บาท')
        ->assertSee('งบประมาณทดแทน (+5%)')
        ->assertSee('มูลค่าตอนซื้อ + 5%')
        ->assertDontSee('(AI)')
        ->assertSee('หน่วยงาน: ฝ่ายพัสดุ')
        ->assertDontSee('มูลค่าคงเหลือ = มูลค่า')
        ->assertDontSee('บวกครั้งเดียว ไม่ได้คำนวณจากมูลค่าคงเหลือ')
        // print and Excel icons in the year row, exporting this year
        ->assertSeeInOrder(['ครบกำหนดทดแทนปี พ.ศ. 2570', 'forecast-export-btn--print', 'forecast-export-btn--excel', 'forecast-ai-summary'], false)
        ->assertSee('href="' . route('asset.registrations.forecast-assets.pdf', ['year' => 2027]) . '"', false)
        ->assertSee('href="' . route('asset.registrations.forecast-assets.excel', ['year' => 2027]) . '"', false)
        // ย้อนกลับ sits at the bottom right inside the frame
        ->assertSeeInOrder(['forecast-frame-actions', 'href="' . e(route('asset.registrations.replacement-forecast', ['restore' => 1])) . '">ย้อนกลับ</a>', '</section>'], false);
});

it('downloads the list as a PDF, in the order shown', function () {
    withLatestForecast();

    $response = $this->get(route('asset.registrations.forecast-assets.pdf', ['year' => 2027, 'sort' => 'replacement_budget', 'direction' => 'asc']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('replacement-forecast-2570.pdf');
});

it('downloads the list as Excel, with the amounts as numbers', function () {
    withLatestForecast();

    $response = $this->get(route('asset.registrations.forecast-assets.excel'))->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('replacement-forecast-all.xlsx');

    $zip = new ZipArchive();
    $zip->open($response->getFile()->getPathname());
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = $zip->getFromName('xl/styles.xml');
    $zip->close();

    expect($sheet)->toContain('รายการครุภัณฑ์ที่ต้องจัดซื้อทดแทน ทั้งหมด (พ.ศ. 2570 – 2571)')
        ->toContain('งบประมาณทดแทน (+5%)')
        ->toContain('7110-002-0009')
        ->toContain('<v>30000</v>')
        ->toContain('รวม 3 รายการ')
        ->toContain('<v>50000</v>')
        ->and($styles)->toContain('<numFmt numFmtId="164" formatCode="#,##0.00"/>');
});

it('refuses to export before a calculation', function () {
    $this->get(route('asset.registrations.forecast-assets.pdf'))->assertNotFound();
    $this->get(route('asset.registrations.forecast-assets.excel'))->assertNotFound();
});

it('shows every asset of the forecast period for "แสดงทั้งหมด"', function () {
    withLatestForecast();

    $this->get(route('asset.registrations.forecast-assets'))
        ->assertOk()
        ->assertSee('ทั้งหมด (พ.ศ. 2570 – 2571)')
        ->assertSeeInOrder(['7440-001-0001', '7440-001-0002', '7110-002-0009'])
        ->assertSee('3 รายการ');
});

it('sorts the list, keeping assets without a budget last', function () {
    withLatestForecast();

    $this->get(route('asset.registrations.forecast-assets', ['sort' => 'replacement_budget', 'direction' => 'asc']))
        ->assertOk()
        ->assertSeeInOrder(['7440-001-0002', '7440-001-0001', '7110-002-0009']);
});

it('asks for a calculation when there is no forecast yet, or the year is not in it', function () {
    $this->get(route('asset.registrations.forecast-assets', ['year' => 2027]))
        ->assertOk()
        ->assertSee('ไม่พบผลการพยากรณ์');

    withLatestForecast();
    $this->get(route('asset.registrations.forecast-assets', ['year' => 2030]))
        ->assertOk()
        ->assertSee('ปี พ.ศ. 2573 ไม่อยู่ในผลการพยากรณ์ล่าสุด');
});
