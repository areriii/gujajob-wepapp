@php
    $assetCode = app(\App\Services\AssetDisplayService::class)->displayCode($asset);
    $descriptionLines = collect([
        $asset->ass_desc,
        $asset->ass_model ? 'รุ่น: ' . $asset->ass_model : null,
        $asset->ass_serail ? 'Serial No.: ' . $asset->ass_serail : null,
    ])->filter();
    $document = collect([$asset->ass_contact_no, $asset->ass_contact_date_th])->filter()->implode(' / ');
    $governmentDepartment = strtoupper(trim((string) ($asset->org_zone_flg ?? ''))) === 'C'
        ? 'กรมส่งเสริมสหกรณ์'
        : ($asset->org_name ?? '-');
@endphp
<section class="report-card asset-control-register">
    <div class="asset-control-content">
        <div class="asset-control-heading">
            @if ($showReportTitle ?? true)
                <div class="asset-control-title">ทะเบียนคุมครุภัณฑ์</div>
            @endif
            <div class="asset-control-administration">
                <p><span>ส่วนราชการ</span><strong>{{ $governmentDepartment }}</strong></p>
                <p><span>หน่วยงาน</span><strong>{{ $asset->org_name ?? '-' }}</strong></p>
            </div>
            <p class="report-generated-at">{{ $generatedAt }}</p>
            <div class="asset-control-fields asset-control-fields-left">
                <p><span>ประเภท</span><strong>{{ $asset->asscat_name ?? '-' }}</strong></p>
                <p><span>รหัสครุภัณฑ์</span><strong>{{ $assetCode ?: '-' }}</strong></p>
                <p><span>สถานที่ตั้ง/หน่วยงานผู้รับผิดชอบ</span><strong>{{ $asset->sub_org_name ?? '-' }}</strong></p>
                <p><span>ที่อยู่</span><strong>{{ $asset->dealer_name ?? '-' }}</strong></p>
                <p><span>ชื่อผู้ขาย/ผู้รับจ้าง</span><strong>{{ $asset->dealer_name ?? '-' }}</strong></p>
            </div>
        </div>

        <div class="report-table asset-control-table-wrap">
            <table class="asset-control-table">
                <colgroup>
                    <col style="width: 7%;">
                    <col style="width: 8%;">
                    <col style="width: 28%;">
                    <col style="width: 6%;">
                    <col style="width: 8%;">
                    <col style="width: 8%;">
                    <col style="width: 6%;">
                    <col style="width: 6%;">
                    <col style="width: 7%;">
                    <col style="width: 7%;">
                    <col style="width: 7%;">
                    <col style="width: 8%;">
                </colgroup>
                <thead>
                    <tr>
                        <th rowspan="2">วัน เดือน ปี</th>
                        <th rowspan="2">เลขที่เอกสาร</th>
                        <th colspan="6">รายละเอียดครุภัณฑ์/สิ่งก่อสร้าง</th>
                        <th rowspan="2">ค่าเสื่อมราคา<br>ประจำปี</th>
                        <th rowspan="2">ค่าเสื่อมราคา<br>สะสม</th>
                        <th rowspan="2">มูลค่าสุทธิ</th>
                        <th rowspan="2">หมายเหตุ</th>
                    </tr>
                    <tr>
                        <th>ชื่อสินทรัพย์ คำอธิบายและรายละเอียด<br>ลักษณะ/คุณสมบัติ/ขนาด/ยี่ห้อ/รุ่น/<br>แบบ/หมายเลขเครื่อง/</th>
                        <th>จำนวน<br>(หน่วย)</th>
                        <th>ราคาต่อหน่วย/<br>ชุด/กลุ่ม</th>
                        <th>มูลค่ารวม</th>
                        <th>อายุการ<br>ใช้งาน</th>
                        <th>อัตรา<br>ค่าเสื่อมราคา</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $asset->inspect_date_th ?? '-' }}</td>
                        <td>{{ $document ?: ($asset->inspect_date_th ?? '-') }}</td>
                        <td class="asset-description">{{ $descriptionLines->isNotEmpty() ? $descriptionLines->implode("\n") : '-' }}</td>
                        <td class="number-cell">1</td>
                        <td class="number-cell">{{ $asset->ass_price !== null ? number_format((float) $asset->ass_price, 2) : '-' }}</td>
                        <td class="number-cell">{{ $asset->ass_price !== null ? number_format((float) $asset->ass_price, 2) : '-' }}</td>
                        <td class="number-cell">{{ $asset->ass_lifetime !== null ? number_format((float) $asset->ass_lifetime, 0) : '-' }}</td>
                        <td class="number-cell">{{ $asset->depreciation_rate !== null ? number_format((float) $asset->depreciation_rate, 2) . '%' : '-' }}</td>
                        <td class="number-cell">{{ $asset->annual_depreciation !== null ? number_format((float) $asset->annual_depreciation, 2) : '-' }}</td>
                        <td class="number-cell">0.00</td>
                        <td class="number-cell">{{ $asset->ass_price !== null ? number_format((float) $asset->ass_price, 2) : '-' }}</td>
                        <td>{{ $asset->remarks ?? '-' }}</td>
                    </tr>
                    @foreach (($asset->depreciation_periods ?? []) as $period)
                        <tr class="depreciation-calculation-row">
                            <td></td>
                            <td></td>
                            <td colspan="6">{{ $period['label'] }}</td>
                            <td class="number-cell">{{ number_format((float) $period['depreciation'], 2) }}</td>
                            <td class="number-cell">{{ number_format((float) $period['accumulated'], 2) }}</td>
                            <td class="number-cell">{{ number_format((float) $period['net_value'], 2) }}</td>
                            <td></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($showActions ?? false)
        <div class="report-actions report-register-actions">
            <a href="{{ route('asset.reports.index') }}" class="back-btn"><span>ย้อนกลับ</span></a>
            <button
                type="button"
                class="print-btn"
                data-pdf-url="{{ route('asset.reports.register.download', request()->only(['category_id', 'asset_id'])) }}"
            >
                <svg><use href="#icon-printer"></use></svg><span>พิมพ์</span>
            </button>
        </div>
    @endif
</section>
