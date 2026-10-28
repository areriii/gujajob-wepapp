<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <style>
        {{-- Dompdf only registers @font-face from a plain absolute path; bold needs its own file. --}}
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew.ttf')) }}') format('truetype'); font-weight: normal; font-style: normal; }
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew-Bold.ttf')) }}') format('truetype'); font-weight: bold; font-style: normal; }

        @page { margin: 8mm; }

        {{-- Report title 18pt, all other text 14pt. --}}
        body { color: #111827; font-family: 'ReportThai', sans-serif; font-size: 14pt; line-height: 1; }
        .title { font-size: 18pt; font-weight: bold; text-align: center; }
        .conditions { margin: 4px 0 8px; text-align: center; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #64748b; padding: 2px; vertical-align: top; overflow-wrap: break-word; }
        th { font-weight: bold; text-align: center; vertical-align: middle; }
        .num { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .total td { font-weight: bold; }
        th:nth-child(1) { width: 4%; }
        th:nth-child(2) { width: 11%; }
        th:nth-child(3) { width: 12%; }
        th:nth-child(4) { width: 12%; }
        th:nth-child(5) { width: 12%; }
        th:nth-child(6) { width: 8%; }
        th:nth-child(7) { width: 8%; }
        th:nth-child(8) { width: 8%; }
        th:nth-child(9) { width: 8%; }
        th:nth-child(10) { width: 8%; }
        th:nth-child(11) { width: 9%; }
    </style>
</head>
<body>
    @php $money = fn ($value) => is_numeric($value) ? number_format((float) $value, 2) : '-'; @endphp

    <div class="title">รายการครุภัณฑ์ที่ต้องจัดซื้อทดแทน {{ $heading }}</div>
    <div class="conditions">{{ $conditions }}</div>

    <table>
        <thead>
            <tr>
                <th>ลำดับ</th>
                <th>รหัสครุภัณฑ์</th>
                <th>ชื่อครุภัณฑ์</th>
                <th>หมวดครุภัณฑ์</th>
                <th>หน่วยงาน</th>
                <th>วันที่<br>ตรวจรับ</th>
                <th>ปีที่ครบ<br>กำหนด<br>ทดแทน</th>
                <th>มูลค่า</th>
                <th>ค่าเสื่อมราคา<br>สะสม</th>
                <th>มูลค่า<br>คงเหลือ</th>
                <th>งบประมาณ<br>ทดแทน<br>(+{{ $percent }}%)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($assets as $asset)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $asset['display_code'] ?? $asset['asset_code'] ?? '-' }}</td>
                    <td>{{ $asset['category_name'] ?? '-' }}</td>
                    <td>{{ $asset['category_group'] ?? '-' }}</td>
                    <td>{{ $asset['organization_name'] ?? '-' }}</td>
                    <td class="center">{{ $asset['acceptance_date'] ?? '-' }}</td>
                    <td class="center">พ.ศ. {{ (int) ($asset['forecast_year'] ?? 0) + 543 }}</td>
                    <td class="num">{{ $money($asset['acquisition_value'] ?? null) }}</td>
                    <td class="num">{{ $money($asset['accumulated_depreciation'] ?? null) }}</td>
                    <td class="num">{{ $money($asset['current_value'] ?? null) }}</td>
                    <td class="num">{{ $money($asset['replacement_budget'] ?? null) }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="center">ไม่พบครุภัณฑ์ที่คาดว่าจะถึงกำหนดทดแทน</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="10">รวม {{ number_format($assets->count()) }} รายการ</td>
                <td class="num">{{ number_format($totalBudget, 2) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
