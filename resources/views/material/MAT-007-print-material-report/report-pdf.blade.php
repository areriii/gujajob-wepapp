<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <style>
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew.ttf')) }}') format('truetype'); font-weight: normal; font-style: normal; }
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew-Bold.ttf')) }}') format('truetype'); font-weight: bold; font-style: normal; }
        @page { size: A4 portrait; margin: 12mm; }
        {{-- Report title 18pt, all other text 14pt. --}}
        body { color: #111827; font-family: 'ReportThai', sans-serif; font-size: 14pt; line-height: 1; }
        .material-balance-header { margin-bottom: 14px; text-align: center; }
        .material-balance-header h1 { margin: 0 0 4px; font-size: 18pt; font-weight: bold; }
        .material-balance-header p { margin: 2px 0; }
        .material-balance-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .material-balance-table th,
        .material-balance-table td { border: 1px solid #64748b; padding: 2px 3px; overflow-wrap: break-word; vertical-align: top; }
        .material-balance-table th { font-weight: bold; text-align: center; vertical-align: middle; }
        .material-balance-table th:nth-child(1) { width: 8%; }
        .material-balance-table th:nth-child(2) { width: 16%; }
        .material-balance-table th:nth-child(3) { width: 36%; }
        .material-balance-table th:nth-child(4) { width: 12%; }
        .material-balance-table th:nth-child(5) { width: 14%; }
        .material-balance-table th:nth-child(6) { width: 14%; }
        .material-balance-table .number-cell { text-align: right; white-space: nowrap; }
        .material-balance-table .center-cell { text-align: center; }
        .report-no-data { padding: 10px; text-align: center; }
    </style>
</head>
<body>
    <header class="material-balance-header">
        <h1>รายงานวัสดุคงเหลือ</h1>
        @if ($reportOrgLine !== '')<p>{{ $reportOrgLine }}</p>@endif
        <p>ณ วันที่ {{ $asOfDate }}</p>
    </header>

    <table class="material-balance-table">
        <thead>
            <tr><th>ลำดับที่</th><th>รหัสวัสดุ</th><th>ชื่อวัสดุ</th><th>หน่วยนับ</th><th>ราคาต่อหน่วย</th><th>ปริมาณคงเหลือ</th></tr>
        </thead>
        <tbody>
            @forelse ($materials as $material)
                <tr>
                    <td class="center-cell">{{ $loop->iteration }}</td>
                    <td>{{ $material->mat_code }}</td>
                    <td>{{ $material->mat_name }}</td>
                    <td class="center-cell">{{ $material->unit }}</td>
                    <td class="number-cell">{{ number_format((float) $material->unit_price, 2) }}</td>
                    <td class="number-cell">{{ number_format((float) $material->balance) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="report-no-data">ไม่มีข้อมูลที่ตรงกับเงื่อนไข</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
