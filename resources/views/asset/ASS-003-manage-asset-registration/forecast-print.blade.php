<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานพยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }

        @page { size: A4 landscape; margin: 10mm; }

        body {
            font-family: "Noto Sans Thai", Arial, Helvetica, sans-serif;
            font-size: 13px;
            color: #111827;
            background: #ffffff;
            margin: 0;
            padding: 0;
        }

        .report-page {
            width: 297mm;
            min-height: 210mm;
            margin: 0 auto;
            padding: 14mm 14mm 12mm;
        }

        /* ── Header ── */
        .report-title {
            text-align: center;
            font-size: 17px;
            font-weight: 800;
            color: #173b8f;
            margin-bottom: 4px;
        }

        .report-subtitle {
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 18px;
        }

        .report-divider {
            border: none;
            border-top: 2px solid #173b8f;
            margin-bottom: 14px;
        }

        /* ── Meta block ── */
        .report-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 24px;
            margin-bottom: 18px;
            font-size: 12px;
        }

        .meta-row {
            display: flex;
            gap: 6px;
        }

        .meta-label {
            color: #6b7280;
            font-weight: 700;
            white-space: nowrap;
            min-width: 120px;
        }

        .meta-value {
            color: #111827;
            font-weight: 600;
        }

        /* ── Warnings ── */
        .report-warning {
            margin-bottom: 14px;
            padding: 8px 12px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #92400e;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }

        .report-warning ul { margin: 0; padding-left: 18px; }

        /* ── Summary boxes ── */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .summary-box {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 10px 12px;
            text-align: center;
        }

        .summary-box .label {
            font-size: 10px;
            font-weight: 700;
            color: #6b7280;
            display: block;
            margin-bottom: 4px;
        }

        .summary-box .value {
            font-size: 18px;
            font-weight: 900;
            color: #173b8f;
            line-height: 1;
        }

        .summary-box.highlight .value {
            color: #6d28d9;
        }

        /* ── Section title ── */
        .section-title {
            font-size: 13px;
            font-weight: 800;
            color: #173b8f;
            margin: 18px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #dfe7f0;
        }

        /* ── Tables ── */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        th {
            background: #173b8f;
            color: #ffffff;
            padding: 8px 8px;
            font-size: 11px;
            font-weight: 700;
            text-align: left;
        }

        td {
            padding: 8px 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        thead { display: table-header-group; }

        .year-table { max-width: 180mm; }

        .total-row td {
            font-weight: 800;
            border-top: 2px solid #173b8f;
            border-bottom: none;
        }

        .num-cell { text-align: right; white-space: nowrap; }
        .center-cell { text-align: center; }

        .ai-cost { color: #6d28d9; font-weight: 800; }
        .muted { color: #9ca3af; }

        /* ── Remark ── */
        .report-remark {
            margin-top: 24px;
            padding: 10px 14px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            font-size: 10px;
            color: #6b7280;
            line-height: 1.6;
        }

        .report-remark p { margin: 0; }

        .empty-msg {
            text-align: center;
            padding: 24px;
            color: #9ca3af;
            font-size: 12px;
        }

        /* ── Print ── */
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .report-page { width: auto; min-height: 0; padding: 0; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
        }

        /* ── Screen only ── */
        @media screen {
            body { background: #f3f4f6; }
            .report-page {
                max-width: calc(100% - 24px);
                margin: 20px auto;
                box-shadow: 0 4px 24px rgba(0,0,0,0.12);
                background: #ffffff;
            }
            .print-btn-bar {
                text-align: center;
                padding: 16px 0 0;
                margin-bottom: 10px;
            }
            .print-btn {
                padding: 8px 24px;
                background: #173b8f;
                color: #ffffff;
                border: none;
                border-radius: 4px;
                font-family: inherit;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
            }
            .print-btn:hover { background: #102d70; }
        }
    </style>
</head>
<body>

@php
    $forecastYears = $result['forecast_years'] ?? ($params['forecast_years'] ?? null);
    $startYear     = $result['start_year'] ?? null;
    $endYear       = $result['end_year'] ?? null;
    $total         = (int) ($result['total_assets'] ?? 0);
    $budget        = (float) ($result['total_forecast_budget'] ?? 0);
    $years         = $result['years'] ?? [];
    $assets        = $result['assets'] ?? [];
    $warnings      = $result['warnings'] ?? [];
    $model         = $result['model'] ?? null;
    $isDemo        = ($result['training_data_source'] ?? 'database') === 'demo';
    $hasFallback   = collect($assets)->contains(fn ($a) => ($a['prediction_basis'] ?? '') === 'asset_price_trend');
    $money         = static fn ($value) => $value !== null && $value !== '' ? number_format((float) $value, 2) : '-';
@endphp

<div class="no-print print-btn-bar">
    <button class="print-btn" type="button" onclick="window.print()">พิมพ์รายงาน</button>
</div>

<div class="report-page">

    {{-- ── Title ── --}}
    <div class="report-title">รายงานพยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน</div>
    <div class="report-subtitle">ผลการพยากรณ์โดย AI (Ridge Regression) · ใช้สำหรับประกอบการวางแผนงบประมาณ</div>
    <hr class="report-divider">

    {{-- ── Meta ── --}}
    <div class="report-meta">
        <div class="meta-row">
            <span class="meta-label">วันที่ออกรายงาน</span>
            <span class="meta-value">{{ $printDate }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">วันที่คำนวณ</span>
            <span class="meta-value">{{ $params['calculated_at'] ?? '-' }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">ระยะเวลาพยากรณ์</span>
            <span class="meta-value">
                {{ $forecastYears ?? '-' }} ปี
                @if ($startYear && $endYear)
                    (พ.ศ. {{ $startYear + 543 }}@if ($endYear !== $startYear) – {{ $endYear + 543 }}@endif)
                @endif
            </span>
        </div>
        <div class="meta-row">
            <span class="meta-label">หมวดครุภัณฑ์</span>
            <span class="meta-value">{{ $params['filter_cat_name'] ?? 'ทั้งหมด' }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">หน่วยงาน</span>
            <span class="meta-value">{{ $params['filter_org_name'] ?? 'ทั้งหมด' }}</span>
        </div>
    </div>

    @if (!empty($warnings))
        <div class="report-warning">
            <ul>
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Summary boxes ── --}}
    <div class="summary-grid">
        <div class="summary-box">
            <span class="label">ระยะเวลาพยากรณ์</span>
            <span class="value">{{ $forecastYears ?? '-' }} ปี</span>
        </div>
        <div class="summary-box">
            <span class="label">จำนวนครุภัณฑ์ที่คาดว่าจะทดแทน</span>
            <span class="value">{{ number_format($total) }} รายการ</span>
        </div>
        <div class="summary-box highlight">
            <span class="label">งบประมาณรวมที่คาดการณ์</span>
            <span class="value">{{ number_format($budget, 2) }} บาท</span>
        </div>
    </div>

    {{-- ── Year table ── --}}
    @if (!empty($years))
        <div class="section-title">ผลการพยากรณ์รายปี</div>
        <table class="year-table">
            <thead>
                <tr>
                    <th>ปี</th>
                    <th class="center-cell">จำนวนครุภัณฑ์</th>
                    <th class="num-cell">งบประมาณที่คาดการณ์ (บาท)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($years as $yr)
                    <tr>
                        <td>พ.ศ. {{ ($yr['year'] ?? 0) + 543 }} (ค.ศ. {{ $yr['year'] ?? '-' }})</td>
                        <td class="center-cell">{{ number_format($yr['asset_count'] ?? 0) }}</td>
                        <td class="num-cell">{{ number_format((float) ($yr['forecast_budget'] ?? 0), 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td>รวม</td>
                    <td class="center-cell">{{ number_format($total) }}</td>
                    <td class="num-cell">{{ number_format($budget, 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- ── Asset details ── --}}
    <div class="section-title">รายละเอียดครุภัณฑ์</div>
    @if (empty($assets))
        <div class="empty-msg">ไม่พบครุภัณฑ์ที่คาดว่าจะถึงกำหนดทดแทนในช่วงเวลาที่เลือก</div>
    @else
        <table>
            <thead>
                <tr>
                    <th class="center-cell">ลำดับ</th>
                    <th>รหัสครุภัณฑ์</th>
                    <th>ชื่อครุภัณฑ์</th>
                    <th>หมวดครุภัณฑ์</th>
                    <th>หน่วยงาน</th>
                    <th class="center-cell">วันที่ตรวจรับ</th>
                    <th class="center-cell">ปีที่ครบกำหนดทดแทน</th>
                    <th class="num-cell">มูลค่า (บาท)</th>
                    <th class="num-cell">ค่าเสื่อมราคาสะสม (บาท)</th>
                    <th class="num-cell">มูลค่าคงเหลือ (บาท)</th>
                    <th class="num-cell">มูลค่าทดแทน (AI) (บาท)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($assets as $a)
                    @php $basis = $a['prediction_basis'] ?? 'category_trend'; @endphp
                    <tr>
                        <td class="center-cell">{{ $loop->iteration }}</td>
                        <td>{{ $a['asset_code'] ?? '-' }}</td>
                        <td>{{ $a['category_name'] ?? '-' }}</td>
                        <td>{{ $a['category_group'] ?? '-' }}</td>
                        <td>{{ $a['organization_name'] ?? '-' }}</td>
                        <td class="center-cell">{{ $a['acceptance_date'] ?? '-' }}</td>
                        <td class="center-cell">พ.ศ. {{ ($a['forecast_year'] ?? 0) + 543 }}</td>
                        <td class="num-cell">{{ $money($a['acquisition_value'] ?? null) }}</td>
                        <td class="num-cell">{{ $money($a['accumulated_depreciation'] ?? null) }}</td>
                        <td class="num-cell">{{ $money($a['current_value'] ?? null) }}</td>
                        <td class="num-cell ai-cost">
                            @if ($basis === 'unavailable' || ($a['predicted_replacement_cost'] ?? null) === null)
                                <span class="muted">ไม่สามารถพยากรณ์ได้</span>
                            @else
                                {{ $money($a['predicted_replacement_cost']) }}@if ($basis === 'asset_price_trend') *@endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ── Remark ── --}}
    <div class="report-remark">
        <p>
            <strong>หมายเหตุ:</strong>
            รายงานนี้เป็นผลการพยากรณ์เพื่อใช้ประกอบการวางแผนงบประมาณจัดซื้อครุภัณฑ์ทดแทนเท่านั้น ไม่ใช่ราคาจัดซื้อจริง
            ผลการพยากรณ์อาจคลาดเคลื่อนตามปัจจัยทางเศรษฐกิจและราคาตลาดที่เปลี่ยนแปลง กรุณาใช้ดุลยพินิจในการตัดสินใจจัดซื้อจริง
        </p>
        <p>
            ปีที่ครบกำหนดทดแทน = ปีที่ค่าเสื่อมราคาครบอายุการใช้งานตามทะเบียน (เริ่มคิดเดือนที่ตรวจรับ ถ้าหลังวันที่ 15 เริ่มเดือนถัดไป)
        </p>
        <p>
            <strong>มูลค่าคงเหลือ</strong> = มูลค่า − ค่าเสื่อมราคาสะสม
            @if (!empty($result['depreciation']['as_of_date']))
                ณ วันที่ {{ \Illuminate\Support\Carbon::parse($result['depreciation']['as_of_date'])->format('d/m/') . (\Illuminate\Support\Carbon::parse($result['depreciation']['as_of_date'])->year + 543) }}
            @endif
            (หลักเกณฑ์เดียวกับทะเบียนคุมครุภัณฑ์: วิธีเส้นตรง มูลค่า ÷ อายุการใช้งาน คิดตามปีงบประมาณ เริ่มเดือนที่ตรวจรับ ถ้าหลังวันที่ 15 เริ่มเดือนถัดไป คงเหลือ 1 บาทเมื่อครบอายุ)
        </p>
        <p>
            <strong>มูลค่าทดแทน (AI)</strong> = ราคาจัดซื้อครุภัณฑ์ใหม่ชื่อครุภัณฑ์เดียวกันที่คาดการณ์ในปีที่ครบกำหนดทดแทน
            พยากรณ์ด้วย Ridge Regression จากราคาจัดซื้อย้อนหลังแยกตามชื่อครุภัณฑ์และปี ไม่ได้คำนวณจากมูลค่าคงเหลือ
        </p>
        @if ($hasFallback)
            <p>* หมวดที่ไม่มีข้อมูลราคาย้อนหลัง ประมาณจากมูลค่าของครุภัณฑ์ปรับด้วยอัตราการเปลี่ยนแปลงราคาเฉลี่ย</p>
        @endif
        @if (is_array($model) && !empty($model['training_records']))
            <p>
                โมเดล: {{ $model['name'] ?? 'AI' }}
                · ข้อมูลฝึกสอน: {{ number_format((int) $model['training_records']) }} รายการ
                @if (($model['annual_price_growth_pct'] ?? null) !== null)
                    · อัตราการเปลี่ยนแปลงราคาเฉลี่ย: {{ number_format((float) $model['annual_price_growth_pct'], 2) }}% ต่อปี
                @endif
                @if (($model['mape'] ?? null) !== null && ($model['evaluation_year'] ?? null) !== null)
                    · ความคลาดเคลื่อนเฉลี่ย (MAPE) เมื่อทดสอบกับปี พ.ศ. {{ $model['evaluation_year'] + 543 }}: {{ number_format((float) $model['mape'], 2) }}%
                @endif
                @if ($isDemo)
                    · <strong>ใช้ข้อมูลตัวอย่าง (DEMO)</strong>
                @endif
            </p>
        @endif
    </div>

</div>

</body>
</html>
