<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>Excel Ledger Test</title>
    <style>
        body { margin: 20px; color: #18212f; font: 14px Arial, sans-serif; }
        h1 { margin: 0 0 8px; }
        p { margin: 4px 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #667085; padding: 7px 5px; vertical-align: top; }
        th { background: #f1f4f8; text-align: center; }
        .center { text-align: center; }
        .number { text-align: right; }
    </style>
</head>
<body>
    <h1>Excel Ledger Test</h1>
    <p>{{ $reportOrgLine ?: '-' }} | ปีงบประมาณ พ.ศ. {{ $fiscalYear }}</p>
    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $index => $value)
                        <td class="{{ $index === 0 || $index === 1 || $index === 9 || $index === 10 || $index === 14 ? 'center' : ($index === 11 ? 'number' : '') }}">{{ $value ?? '-' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="15">ไม่มีข้อมูลที่ตรงกับเงื่อนไข</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
