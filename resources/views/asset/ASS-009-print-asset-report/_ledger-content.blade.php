<div class="asset-ledger-heading">
    <div class="asset-ledger-title">รายงานทะเบียนครุภัณฑ์ ประจำปีงบประมาณ พ.ศ. {{ $fiscalYear }}</div>
    @if ($reportOrgLine !== '')
        <div class="asset-ledger-org">{{ $reportOrgLine }}</div>
    @endif
</div>

<div class="report-table asset-ledger-table-wrap">
    <table class="asset-ledger-report-table">
        <thead>
            <tr>
                <th>ลำดับ</th>
                <th>ปีงบ<br>ประมาณ</th>
                <th>วันที่<br>ตรวจรับ</th>
                <th>หมวด<br>ครุภัณฑ์</th>
                <th>ประเภท<br>ครุภัณฑ์</th>
                <th>รหัสครุภัณฑ์</th>
                <th>รายการ/<br>รายละเอียด</th>
                <th>ยี่ห้อ/รุ่น</th>
                <th>Serial No.</th>
                <th>จำนวน</th>
                <th>หน่วย<br>นับ</th>
                <th>ราคา<br>ที่ได้มา<br>ต่อหน่วย</th>
                <th>กลุ่ม/<br>ฝ่าย</th>
                <th>หมายเหตุ</th>
                <th>สถานะ</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($assets as $asset)
                @php
                    $assetCode = app(\App\Services\AssetDisplayService::class)->displayCode($asset);
                @endphp
                <tr>
                    <td class="center-cell">{{ $loop->iteration }}</td>
                    <td class="center-cell">{{ $fiscalYear }}</td>
                    <td class="center-cell">{{ $asset->inspect_date_th ?? '-' }}</td>
                    <td>{{ $asset->asscat_group ?? '-' }}</td>
                    <td>{{ $asset->asscat_name ?? '-' }}</td>
                    <td>{{ $assetCode ?: '-' }}</td>
                    <td>{{ $asset->ass_desc ?? '-' }}</td>
                    <td>{{ $asset->ass_model ?? '-' }}</td>
                    <td>{{ $asset->ass_serail ?? '-' }}</td>
                    <td class="center-cell">1</td>
                    <td class="center-cell">{{ $asset->asscat_unit ?? '-' }}</td>
                    <td class="number-cell">{{ $asset->ass_price !== null ? number_format((float) $asset->ass_price, 2) : '-' }}</td>
                    <td>{{ $asset->sub_org_name ?? '-' }}</td>
                    <td>{{ $asset->remarks ?? '-' }}</td>
                    <td class="center-cell">{{ $asset->status_label ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="15" class="report-no-data">ไม่มีข้อมูลที่ตรงกับเงื่อนไข</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
