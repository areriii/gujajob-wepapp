<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <style>
        {{-- Bold needs its own font file, and Dompdf only registers @font-face from a plain
             absolute path (a file:/// URL silently fails), otherwise bold Thai text falls
             back to Helvetica-Bold and disappears. --}}
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew.ttf')) }}') format('truetype'); font-weight: normal; font-style: normal; }
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/THSarabunNew-Bold.ttf')) }}') format('truetype'); font-weight: bold; font-style: normal; }

        @page { margin: 10mm; }

        {{-- Report title 18pt, all other text 14pt. --}}
        body { color: #111827; font-family: 'ReportThai', sans-serif; font-size: 14pt; line-height: 1; }
        .asset-control-register { page-break-after: always; padding: 0; }
        .asset-control-register:last-child { page-break-after: auto; }
        .asset-control-title { text-align: center; font-size: 18pt; font-weight: bold; margin: 0 0 4px; }
        .asset-control-administration { margin-top: 0; text-align: right; }
        .asset-control-administration p { margin: 0 0 2px; }
        .asset-control-administration strong { display: inline-block; min-width: 190px; margin-left: 10px; }
        /* Reference layout has no header timestamp; only the per-row "วัน เดือน ปี" table column shows a date. */
        .report-generated-at { display: none; }
        .asset-control-fields { margin-top: 12px; }
        .asset-control-fields p { margin: 0 0 2px; }
        .asset-control-fields span { display: inline-block; width: 210px; }
        .asset-control-table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 10px; }
        .asset-control-table th, .asset-control-table td { border: 1px solid #64748b; overflow-wrap: break-word; padding: 2px; vertical-align: top; }
        .asset-control-table th { font-weight: bold; text-align: center; vertical-align: middle; }
        .asset-control-table .number-cell { text-align: right; white-space: nowrap; }
        .asset-description { white-space: pre-line; }
        {{-- Column widths come from the invisible first row (pdfColumnSizer), so long Thai text without spaces
             cannot stretch a column. Narrow quantity/price/life/rate columns leave room for the document no.
             and description. --}}
        .asset-control-table .pdf-column-sizer td { height: 0; padding: 0; border: 0; }
        .asset-control-table .pdf-column-sizer td:nth-child(1) { width: 8%; }
        .asset-control-table .pdf-column-sizer td:nth-child(2) { width: 10%; }
        .asset-control-table .pdf-column-sizer td:nth-child(3) { width: 18.5%; }
        .asset-control-table .pdf-column-sizer td:nth-child(4) { width: 5.5%; }
        .asset-control-table .pdf-column-sizer td:nth-child(5) { width: 8%; }
        .asset-control-table .pdf-column-sizer td:nth-child(6) { width: 8%; }
        .asset-control-table .pdf-column-sizer td:nth-child(7) { width: 5.5%; }
        .asset-control-table .pdf-column-sizer td:nth-child(8) { width: 6%; }
        .asset-control-table .pdf-column-sizer td:nth-child(9) { width: 8%; }
        .asset-control-table .pdf-column-sizer td:nth-child(10) { width: 8%; }
        .asset-control-table .pdf-column-sizer td:nth-child(11) { width: 8%; }
        .asset-control-table .pdf-column-sizer td:nth-child(12) { width: 6.5%; }
    </style>
</head>
<body>
    @foreach ($assets as $asset)
        @include('asset.ASS-009-print-asset-report._register-content', [
            'asset' => $asset,
            'generatedAt' => $generatedAt,
            'showReportTitle' => true,
            'pdfColumnSizer' => true,
        ])
    @endforeach
</body>
</html>