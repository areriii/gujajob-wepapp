@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/app-shell.css',
        'resources/css/material/MAT-007-print-material-report/style.css',
        'resources/css/asset/ASS-009-print-asset-report/report-style.css',
        'resources/css/material/MAT-007-print-material-report/report.css',
    ])
@endsection

@section('content')
    <div class="page-container report-output-page">
        <x-page-header :title="$pageTitle" />
        <section class="report-card material-balance-report-card">
            <header class="material-balance-header">
                <h1>รายงานวัสดุคงเหลือ</h1>
                @if ($reportOrgLine !== '')<p>{{ $reportOrgLine }}</p>@endif
                <p>ณ วันที่ {{ $asOfDate }}</p>
            </header>
            <div class="report-table material-balance-table-wrap">
                <table class="material-balance-table">
                    <thead><tr><th>ลำดับที่</th><th>รหัสวัสดุ</th><th>ชื่อวัสดุ</th><th>หน่วยนับ</th><th>ราคาต่อหน่วย</th><th>ปริมาณคงเหลือ</th></tr></thead>
                    <tbody>
                        @forelse ($materials as $material)
                            <tr>
                                <td class="center-cell">{{ $loop->iteration }}</td>
                                <td>{{ $material->mat_code }}</td>
                                <td>{{ $material->mat_name }}</td>
                                <td class="center-cell">{{ $material->unit }}</td>
                                <td class="number-cell">{{ number_format((float) $material->unit_price, 2) }}</td>
                                <td class="number-cell">{{ number_format((float) $material->balance, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="report-no-data">ไม่มีข้อมูลที่ตรงกับเงื่อนไข</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="report-actions report-register-actions">
                <a href="{{ route('material.report.index') }}" class="back-btn"><span>ย้อนกลับ</span></a>
                <button class="print-btn" type="button" onclick="window.print()">
                    <svg><use href="#icon-printer"></use></svg>
                    <span>พิมพ์</span>
                </button>
            </div>
        </section>
    </div>
@endsection
