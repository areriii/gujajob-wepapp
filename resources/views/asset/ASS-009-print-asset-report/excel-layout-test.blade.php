@php
    $first = $assets[0] ?? [];
    $widths = collect($layout['cols'])->values();
    $periods = $first['depreciation_periods'] ?? [];
@endphp
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>Excel Layout Test</title>
    <style>
        body { margin: 20px; color: #18212f; font: 14px Arial, sans-serif; }
        h1, h2 { margin: 0 0 12px; }
        h2 { margin-top: 24px; font-size: 18px; }
        .report { border: 1px solid #c7ced8; padding: 20px; overflow-x: auto; }
        .title { text-align: center; font-size: 22px; font-weight: 700; margin-bottom: 18px; }
        .meta { display: grid; grid-template-columns: 155px 1fr 155px 1fr; gap: 8px 12px; margin-bottom: 20px; }
        .meta strong { text-align: right; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #667085; padding: 7px 5px; vertical-align: top; overflow-wrap: anywhere; }
        th { text-align: center; vertical-align: middle; background: #f1f4f8; white-space: pre-line; }
        td.detail { text-align: left; white-space: pre-line; }
        td.money { text-align: right; white-space: nowrap; }
        .debug { border-top: 1px solid #c7ced8; margin-top: 20px; padding-top: 12px; }
        .debug table { table-layout: auto; }
        code { font-family: Consolas, monospace; }
    </style>
</head>
<body>
    <h1>Excel Layout Test</h1>
    <div class="report">
        <div class="title">ทะเบียนคุมครุภัณฑ์</div>
        <div class="meta">
            <strong>ส่วนราชการ</strong><span>{{ $first['government_department'] ?? '-' }}</span>
            <strong>หน่วยงาน</strong><span>{{ $first['org_name'] ?? '-' }}</span>
            <strong>ประเภท</strong><span>{{ $first['asscat_name'] ?? '-' }}</span>
            <strong>รหัสครุภัณฑ์</strong><span>{{ $first['asset_code'] ?? '-' }}</span>
            <strong>สถานที่ตั้ง/หน่วยงานผู้รับผิดชอบ</strong><span>{{ $first['sub_org_name'] ?? '-' }}</span>
            <strong>ที่อยู่</strong><span>{{ $first['dealer_name'] ?? '-' }}</span>
            <strong>ชื่อผู้ขาย/ผู้รับจ้าง</strong><span>{{ $first['dealer_name'] ?? '-' }}</span>
        </div>
        <table>
            <colgroup>
                @foreach ($widths as $column)<col style="width: {{ $column['width'] }}ch">@endforeach
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
                    <td>{{ $first['inspect_date_th'] ?? '-' }}</td>
                    <td>{{ $first['document'] ?? '-' }}</td>
                    <td class="detail">{{ $first['description'] ?? '-' }}</td>
                    <td>1</td>
                    <td class="money">{{ $first['ass_price'] ?? '0.00' }}</td>
                    <td class="money">{{ $first['ass_price'] ?? '0.00' }}</td>
                    <td>{{ $first['ass_lifetime'] ?? '0' }}</td>
                    <td>{{ $first['depreciation_rate'] ?? '0.00' }}%</td>
                    <td class="money">{{ $first['annual_depreciation'] ?? '0.00' }}</td>
                    <td class="money">0.00</td>
                    <td class="money">{{ $first['ass_price'] ?? '0.00' }}</td>
                    <td>{{ $first['remarks'] ?? '-' }}</td>
                </tr>
                @foreach ($periods as $period)
                    <tr>
                        <td colspan="2"></td>
                        <td colspan="6" class="detail">{{ $period['label'] ?? '-' }}</td>
                        <td class="money">{{ $period['depreciation'] ?? '0.00' }}</td>
                        <td class="money">{{ $period['accumulated'] ?? '0.00' }}</td>
                        <td class="money">{{ $period['net_value'] ?? '0.00' }}</td>
                        <td></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <section class="debug">
        <h2>Excel Layout Debug</h2>
        <p>Sheet range: <code>A1:L{{ count($layout['rows']) }}</code> | Logical columns: {{ count($widths) }} | Header rows: 2 | Calculation rows: {{ count($periods) }}</p>
        <p>Merged ranges: <code>{{ implode(', ', $layout['merges']) }}</code></p>
        <table>
            <thead><tr><th>Column</th><th>Width</th><th>Logical field</th></tr></thead>
            <tbody>
                @foreach (['วัน เดือน ปี','เลขที่เอกสาร','รายละเอียดสินทรัพย์','จำนวน','ราคาต่อหน่วย','มูลค่ารวม','อายุการใช้งาน','อัตราค่าเสื่อมราคา','ค่าเสื่อมราคาประจำปี','ค่าเสื่อมราคาสะสม','มูลค่าสุทธิ','หมายเหตุ'] as $index => $label)
                    <tr><td><code>{{ chr(65 + $index) }}</code></td><td>{{ $widths[$index]['width'] }}</td><td>{{ $label }}</td></tr>
                @endforeach
            </tbody>
        </table>
        @foreach ($periods as $index => $period)
            <p><code>Row {{ 12 + $index }}</code>: detail merge <code>C{{ 12 + $index }}:H{{ 12 + $index }}</code>, text “{{ $period['label'] }}”, I={{ $period['depreciation'] }}, J={{ $period['accumulated'] }}, K={{ $period['net_value'] }}</p>
        @endforeach
    </section>
</body>
</html>
