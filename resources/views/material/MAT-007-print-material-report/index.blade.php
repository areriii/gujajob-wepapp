@extends('layouts.app')

@section('page-style')
    @vite(['resources/css/material/MAT-007-print-material-report/style.css'])
@endsection

@section('content')
    <div class="page-container report-page">
        <x-page-header :title="$pageTitle" />

        <section class="report-card report-type-section">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-panel"></use></svg>
                <span>ส่วนที่ 1: เลือกประเภทรายงาน</span>
            </div>

            <div class="report-type-grid">
                <button class="report-type-item active" type="button" data-report-type="stock">
                    <span class="report-icon-box">
                        <svg><use href="#icon-report-file"></use></svg>
                    </span>

                    <span class="report-name">รายงานวัสดุคงเหลือ</span>
                    <span class="report-description">
                        แสดงข้อมูลวัสดุคงเหลือปัจจุบัน พร้อมจำนวนคงเหลือ
                    </span>
                </button>

                <button class="report-type-item" type="button" data-report-type="receiving">
                    <span class="report-icon-box">
                        <svg><use href="#icon-report-chart"></use></svg>
                    </span>

                    <span class="report-name">รายงานรับวัสดุ</span>
                    <span class="report-description">
                        แสดงรายการรับวัสดุเข้าคลัง พร้อมจำนวน ราคา และมูลค่ารวม ในช่วงเวลาที่กำหนด
                    </span>
                </button>

                <button class="report-type-item" type="button" data-report-type="withdraw">
                    <span class="report-icon-box">
                        <svg><use href="#icon-report-receipt"></use></svg>
                    </span>

                    <span class="report-name">รายงานเบิกวัสดุ</span>
                    <span class="report-description">
                        แสดงรายการเบิกวัสดุ พร้อมจำนวนที่เบิก หน่วยงานผู้เบิก และสถานะการอนุมัติ
                    </span>
                </button>
            </div>
        </section>

        <section class="report-card condition-section">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-filter-report"></use></svg>
                <span>ส่วนที่ 2: กำหนดเงื่อนไขการออกรายงาน</span>
            </div>

            <div class="condition-grid material-report-grid">
                <div class="field-group">
                    <label for="mainDepartment">หน่วยงาน</label>
                    <div class="searchable-field">
                        <input id="mainDepartment" type="text" class="searchable-input" placeholder="ค้นหาหน่วยงาน..." autocomplete="off" data-search-url="{{ route('material.report.organizations.search') }}">
                        <div class="dropdown-suggestions" id="mainDepartmentSuggestions" style="display:none"></div>
                        <input id="mainDepartmentId" type="hidden">
                    </div>
                    <span class="report-field-error" id="orgError">@error('org_id'){{ $message }}@enderror</span>
                </div>

                <div class="field-group">
                    <label for="subDepartment">หน่วยงานย่อย</label>
                    <div class="searchable-field">
                        <input id="subDepartment" type="text" class="searchable-input" placeholder="ค้นหาหน่วยงานย่อย..." autocomplete="off" disabled data-search-url="{{ route('material.report.sub-organizations.search') }}">
                        <div class="dropdown-suggestions" id="subDepartmentSuggestions" style="display:none"></div>
                        <input id="subDepartmentId" type="hidden">
                    </div>
                    <span class="report-field-error" id="subOrgError">@error('sub_org_id'){{ $message }}@enderror</span>
                </div>
            </div>

            <div class="export-panel">
                <div class="export-format">
                    <span class="export-label">รูปแบบไฟล์ที่ต้องการออกรายงาน:</span>

                    <label class="radio-label pdf-radio">
                        <input type="radio" name="exportFormat" value="pdf" checked>
                        <span>PDF Document (.pdf)</span>
                    </label>

                    <label class="radio-label excel-radio">
                        <input type="radio" name="exportFormat" value="xlsx">
                        <span>Excel Spreadsheet (.xlsx)</span>
                    </label>
                </div>

                <div class="export-actions">
                    <button class="preview-btn" type="button" id="previewReportButton" data-preview-url="{{ route('material.report.stock') }}" data-export-url="{{ route('material.report.stock.export') }}">
                        <svg><use href="#icon-printer"></use></svg>
                        <span>พรีวิว</span>
                    </button>
                </div>
            </div>
        </section>
    </div>

@endsection

@section('page-script')
    @vite(['resources/js/material/MAT-007-print-material-report/script.js'])
@endsection
