<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <style>
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew.ttf')) }}') format('truetype'); font-weight: normal; font-style: normal; }
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew-Bold.ttf')) }}') format('truetype'); font-weight: bold; font-style: normal; }
        @page { size: A4 portrait; margin: 18mm 15mm 20mm 25mm; }
        {{-- Report title 18pt, heading lines 16pt, tables 14pt. --}}
        body { color: #000000; font-family: 'ReportThai', sans-serif; font-size: 14pt; line-height: 1; }
        .report-header { margin-bottom: 10px; text-align: center; }
        .report-header h1 { margin: 0 0 6px; font-size: 18pt; font-weight: bold; }
        .report-header p { margin: 2px 0; font-size: 16pt; }
        .material-line { margin: 0 0 8px; font-size: 16pt; }
        .material-line strong { margin-right: 4px; }
        .material-line span { margin-right: 18px; }
        .report-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .report-table th,
        .report-table td { border: 0.75pt solid #000000; padding: 3px 4px; overflow-wrap: break-word; vertical-align: top; }
        {{-- Dompdf breaks Thai at any letter, so header lines are broken by hand. --}}
        .report-table th { font-weight: bold; text-align: center; vertical-align: middle; white-space: nowrap; }
        .summary-table { margin-bottom: 14px; }
        .summary-table td { text-align: center; font-size: 16pt; font-weight: bold; }
        .section-title { margin: 0 0 6px; font-size: 16pt; font-weight: bold; }
        .ledger-table .col-date { width: 13%; }
        .ledger-table .col-doc { width: 14%; }
        .ledger-table .col-ref { width: 12%; }
        .ledger-table .col-detail { width: 14%; }
        .ledger-table .col-operator { width: 19%; }
        .ledger-table .col-in,
        .ledger-table .col-out { width: 9%; }
        .ledger-table .col-balance { width: 10%; }
        .ledger-table .operator-role { font-size: 12pt; }
        .number-cell { text-align: right; white-space: nowrap; }
        .center-cell { text-align: center; }
        .report-no-data { padding: 10px; text-align: center; }
        .report-total { margin: 8px 0 0; text-align: right; font-weight: bold; }
        {{-- Page numbers ("หน้า x / y") are drawn by the controller after rendering: Dompdf has no total-pages counter. --}}
        .report-footer { position: fixed; left: 0; bottom: -12mm; font-size: 12pt; }
    </style>
</head>
<body>
    <div class="report-footer">พิมพ์เมื่อวันที่ {{ $asOfDate }}</div>

    <header class="report-header">
        <h1>ทะเบียนคุมวัสดุ</h1>
        <p>หน่วยงาน {{ $orgName }}</p>
        <p>ประจำปีงบประมาณ พ.ศ. {{ $fiscalYear }}</p>
        @if ($periodLine !== '')<p>{{ $periodLine }}</p>@endif
    </header>

    <p class="material-line">
        <span><strong>รหัสวัสดุ</strong> {{ $material->mat_code }}</span>
        <span><strong>ชื่อวัสดุ</strong> {{ $material->mat_name }}</span>
        <span><strong>หน่วยนับ</strong> {{ $material->unit ?: '-' }}</span>
    </p>

    <table class="report-table summary-table">
        <thead>
            <tr>
                <th>ยอดยกมา</th>
                <th>รับเข้าสะสม</th>
                <th>เบิกจ่ายสะสม</th>
                <th>ยอดคงเหลือ</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ number_format($summary['forward']) }}</td>
                <td>{{ number_format($summary['in_total']) }}</td>
                <td>{{ number_format($summary['out_total']) }}</td>
                <td>{{ number_format($summary['balance']) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="section-title">ประวัติการเคลื่อนไหว</p>

    <table class="report-table ledger-table">
        <thead>
            <tr>
                <th class="col-date">วันที่</th>
                <th class="col-doc">เลขที่รายการ</th>
                <th class="col-ref">อ้างอิง<br>(เลขที่เอกสาร)</th>
                <th class="col-detail">รายการ</th>
                <th class="col-operator">ผู้ทำรายการ</th>
                <th class="col-in">รับ</th>
                <th class="col-out">จ่าย</th>
                <th class="col-balance">คงเหลือ</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="center-cell" style="white-space: nowrap;">{{ $row['date'] }}</td>
                    <td>{{ $row['doc_code'] }}</td>
                    <td>{{ $row['reference_no'] !== '' ? $row['reference_no'] : '-' }}</td>
                    <td>{{ $row['detail'] }}</td>
                    <td>
                        {{ $row['operator'] !== '' ? $row['operator'] : '-' }}
                        @if ($row['operator_role'] !== '')<br><span class="operator-role">{{ $row['operator_role'] }}</span>@endif
                    </td>
                    <td class="number-cell">{{ $row['in_qty'] }}</td>
                    <td class="number-cell">{{ $row['out_qty'] }}</td>
                    <td class="number-cell">{{ $row['balance'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="report-no-data">ไม่พบรายการเคลื่อนไหว</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="report-total">รวมทั้งสิ้น {{ number_format(count($rows)) }} รายการ</p>
</body>
</html>
