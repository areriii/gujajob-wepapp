<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <style>
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew.ttf')) }}') format('truetype'); font-weight: normal; font-style: normal; }
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew-Bold.ttf')) }}') format('truetype'); font-weight: bold; font-style: normal; }
        @page { size: A4 portrait; margin: 18mm 15mm 20mm 25mm; }
        {{-- Report title 18pt, heading lines 16pt, table 14pt. --}}
        body { color: #000000; font-family: 'ReportThai', sans-serif; font-size: 14pt; line-height: 1; }
        .report-header { margin-bottom: 12px; text-align: center; }
        .report-header h1 { margin: 0 0 6px; font-size: 18pt; font-weight: bold; }
        .report-header p { margin: 2px 0; font-size: 16pt; }
        .report-header .report-condition { font-size: 14pt; }
        .balance-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .balance-table th,
        .balance-table td { border: 0.75pt solid #000000; padding: 3px 4px; overflow-wrap: break-word; vertical-align: top; }
        {{-- Dompdf breaks Thai at any letter, so header lines are broken by hand. --}}
        .balance-table th { font-weight: bold; text-align: center; vertical-align: middle; white-space: nowrap; }
        .balance-table .col-no { width: 8%; }
        .balance-table .col-code { width: 13%; }
        .balance-table .col-name { width: 32%; }
        .balance-table .col-unit { width: 9%; }
        .balance-table .col-price { width: 12%; }
        .balance-table .col-balance { width: 11%; }
        .balance-table .col-average { width: 15%; }
        .balance-table .number-cell { text-align: right; white-space: nowrap; }
        .balance-table .center-cell { text-align: center; }
        .balance-table .report-no-data { padding: 10px; text-align: center; }
        .report-total { margin: 8px 0 0; text-align: right; font-weight: bold; }
        {{-- Page numbers ("หน้า x / y") are drawn by the controller after rendering: Dompdf has no total-pages counter. --}}
        .report-footer { position: fixed; left: 0; bottom: -12mm; font-size: 12pt; }
    </style>
</head>
<body>
    <div class="report-footer">พิมพ์เมื่อวันที่ {{ $asOfDate }}</div>

    <header class="report-header">
        <h1>รายงานการตั้งยอดคงเหลือวัสดุ</h1>
        <p>หน่วยงาน {{ $orgName }}</p>
        <p>ประจำปีงบประมาณ พ.ศ. {{ $fiscalYear }}</p>
        <p>ณ วันที่ {{ $asOfDate }}</p>
        @if ($searchLine !== '')<p class="report-condition">เงื่อนไขการค้นหา: {{ $searchLine }}</p>@endif
    </header>

    <table class="balance-table">
        <thead>
            <tr>
                <th class="col-no">ลำดับ<br>ที่</th>
                <th class="col-code">รหัสวัสดุ</th>
                <th class="col-name">ชื่อวัสดุ</th>
                <th class="col-unit">หน่วย<br>นับ</th>
                <th class="col-price">ราคาเฉลี่ย<br>ต่อหน่วย<br>(บาท)</th>
                <th class="col-balance">ยอด<br>คงเหลือ</th>
                <th class="col-average">จำนวนเฉลี่ย<br>ในการเบิก<br>ต่อเดือน</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($materials as $material)
                @php
                    $avgPrice = $avgPrices[(int) $material->id] ?? null;
                    $average  = $recommended[(int) $material->id] ?? null;
                @endphp
                <tr>
                    <td class="center-cell">{{ $loop->iteration }}</td>
                    <td>{{ $material->mat_code }}</td>
                    <td>{{ $material->mat_name }}</td>
                    <td class="center-cell">{{ $material->unit }}</td>
                    <td class="number-cell">{{ $avgPrice !== null ? number_format($avgPrice, 2) : '-' }}</td>
                    <td class="number-cell">{{ number_format((float) ($material->current_balance ?? 0)) }}</td>
                    <td class="number-cell">{{ $average !== null ? number_format($average) : '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="report-no-data">ไม่มีข้อมูลที่ตรงกับเงื่อนไข</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="report-total">รวมทั้งสิ้น {{ number_format($materials->count()) }} รายการ</p>
</body>
</html>
