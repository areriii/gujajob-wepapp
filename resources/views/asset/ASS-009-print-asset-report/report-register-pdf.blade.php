<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <style>
        {{-- Bold needs its own font file, and Dompdf only registers @font-face from a plain
             absolute path (a file:/// URL silently fails), otherwise bold Thai text falls
             back to Helvetica-Bold and disappears. --}}
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/tahoma.ttf')) }}') format('truetype'); font-weight: normal; font-style: normal; }
        @font-face { font-family: 'ReportThai'; src: url('{{ str_replace('\\', '/', public_path('fonts/tahomabd.ttf')) }}') format('truetype'); font-weight: bold; font-style: normal; }

        @page { margin: 10mm; }

        body { color: #111827; font-family: 'ReportThai', sans-serif; font-size: 9px; }
        .asset-control-register { page-break-after: always; padding: 0; }
        .asset-control-register:last-child { page-break-after: auto; }
        .asset-control-title { text-align: center; font-size: 16px; font-weight: bold; margin: 0 0 6px; }
        .asset-control-heading { min-height: 185px; }
        .asset-control-administration { margin-top: 0; text-align: right; }
        .asset-control-administration p { margin: 0 0 3px; }
        .asset-control-administration strong { display: inline-block; min-width: 190px; }
        /* Reference layout has no header timestamp; only the per-row "วัน เดือน ปี" table column shows a date. */
        .report-generated-at { display: none; }
        .asset-control-fields { margin-top: 28px; }
        .asset-control-fields p { margin: 0 0 5px; }
        .asset-control-fields span { display: inline-block; width: 155px; }
        .asset-control-table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 14px; }
        .asset-control-table th, .asset-control-table td { border: 1px solid #64748b; overflow-wrap: break-word; padding: 4px 3px; vertical-align: top; }
        .asset-control-table th { font-weight: bold; text-align: center; vertical-align: middle; }
        .asset-control-table .number-cell { text-align: right; white-space: nowrap; }
        .asset-description { line-height: 1.5; white-space: pre-line; }
        .asset-control-table th:nth-child(1) { width: 5%; } .asset-control-table th:nth-child(2) { width: 5%; }
        .asset-control-table th:nth-child(3) { width: 27%; } .asset-control-table th:nth-child(4) { width: 5%; }
        .asset-control-table th:nth-child(5) { width: 8%; } .asset-control-table th:nth-child(6) { width: 7%; }
        .asset-control-table th:nth-child(7) { width: 5%; } .asset-control-table th:nth-child(8) { width: 6%; }
        .asset-control-table th:nth-child(9) { width: 7%; } .asset-control-table th:nth-child(10) { width: 7%; }
        .asset-control-table th:nth-child(11) { width: 6%; } .asset-control-table th:nth-child(12) { width: 7%; }
    </style>
</head>
<body>
    @foreach ($assets as $asset)
        @include('asset.ASS-009-print-asset-report._register-content', [
            'asset' => $asset,
            'generatedAt' => $generatedAt,
            'showReportTitle' => true,
        ])
    @endforeach
</body>
</html>