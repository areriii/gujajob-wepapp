@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/app-shell.css',
        'resources/css/asset/ASS-009-print-asset-report/style.css',
        'resources/css/asset/ASS-009-print-asset-report/report-style.css',
    ])
@endsection

@section('content')
    <div class="page-container report-output-page">
        <x-page-header :title="$pageTitle" />

        <section class="report-card asset-ledger-register">
            @include('asset.ASS-009-print-asset-report._ledger-content')

            {{-- เลือกไฟล์ที่นี่ หลังจากดูรายงานบนจอแล้ว ไม่ต้องเลือกตั้งแต่หน้าเงื่อนไข --}}
            <div class="report-actions report-register-actions">
                <a href="{{ route('asset.reports.index') }}" class="back-btn"><span>ย้อนกลับ</span></a>
                <a
                    class="excel-btn"
                    href="{{ route('asset.reports.ledger.export', request()->only(['fiscal_year', 'org_id', 'sub_org_id'])) }}"
                >
                    <svg><use href="#icon-download-report"></use></svg><span>ดาวน์โหลด Excel</span>
                </a>
                <button
                    type="button"
                    class="print-btn"
                    data-pdf-url="{{ $pdfUrl }}"
                    data-pdf-filename="asset-ledger-report.pdf"
                    data-pdf-counter="LEDGER_PRINT_HANDLER"
                >
                    <svg><use href="#icon-printer"></use></svg><span>พิมพ์ PDF</span>
                </button>
            </div>
        </section>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-009-print-asset-report/report-register.js'])
@endsection
