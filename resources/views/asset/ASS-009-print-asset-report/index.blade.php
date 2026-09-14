@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/app-shell.css',
        'resources/css/asset/ASS-009-print-asset-report/style.css',
    ])
@endsection

@section('content')
    <div class="page-container report-page">
        <x-page-header :title="$pageTitle" />

        @error('report')
            <div class="report-inline-validation-error">{{ $message }}</div>
        @enderror

        <section class="report-card report-type-section">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-panel"></use></svg>
                <span>ส่วนที่ 1: เลือกประเภทรายงาน</span>
            </div>

            <div class="report-type-grid">
                <button class="report-type-item active" type="button" data-report-type="register">
                    <span class="report-icon-box">
                        <svg><use href="#icon-report-file"></use></svg>
                    </span>

                    <span class="report-name">รายงานทะเบียนคุมทรัพย์สิน</span>
                    <span class="report-description">
                        แสดงรายละเอียดทะเบียนครุภัณฑ์และข้อมูลทรัพย์สินตามประเภทหรือรหัสครุภัณฑ์ที่เลือก เพื่อใช้ตรวจสอบข้อมูลทะเบียน สถานะ และรายละเอียดของครุภัณฑ์ในระบบ
                    </span>
                </button>

                <button class="report-type-item" type="button" data-report-type="ledger">
                    <span class="report-icon-box">
                        <svg><use href="#icon-report-chart"></use></svg>
                    </span>

                    <span class="report-name">รายงานทะเบียนครุภัณฑ์</span>
                    <span class="report-description">
                        แสดงรายการทะเบียนครุภัณฑ์ตามปีงบประมาณและหน่วยงานที่กำหนด เพื่อใช้ตรวจสอบครุภัณฑ์ที่อยู่ในช่วงเวลาที่เลือกและข้อมูลหน่วยงานที่เกี่ยวข้อง
                    </span>
                </button>
            </div>
        </section>

        <section class="report-card condition-section" id="conditionSection">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-filter-report"></use></svg>
                <span>ส่วนที่ 2: กำหนดเงื่อนไขการออกรายงาน</span>
            </div>

            <!-- Report Type 1: Asset Register -->
            <div class="condition-grid" id="registerConditions" style="display: block;">
                <div class="field-group">
                    <label class="field-label">ประเภทครุภัณฑ์</label>
                    <div class="searchable-field">
                        <input id="categorySearch" type="text" placeholder="ค้นหาประเภท..."
                               class="searchable-input" autocomplete="off">
                        <div class="dropdown-suggestions" id="categorySuggestions" style="display: none;"></div>
                        <input id="categoryId" type="hidden">
                    </div>
                    <span class="report-field-error" id="categoryError">@error('category_id'){{ $message }}@enderror</span>
                </div>

                <div class="field-group">
                    <label class="field-label">รหัสครุภัณฑ์</label>
                    <div class="searchable-field">
                        <input id="assetSearch" type="text" placeholder="ค้นหารหัสครุภัณฑ์..."
                               class="searchable-input" autocomplete="off" disabled>
                        <div class="dropdown-suggestions" id="assetSuggestions" style="display: none;"></div>
                        <input id="assetId" type="hidden">
                    </div>
                    <span class="field-hint" id="assetSearchHint">กรุณาเลือกประเภทครุภัณฑ์ก่อน</span>
                    <span class="report-field-error" id="assetError">@error('asset_id'){{ $message }}@enderror</span>
                </div>
            </div>

            <!-- Report Type 2: Asset Ledger -->
            <div class="condition-grid" id="ledgerConditions" style="display: none;">
                <div class="field-group">
                    <label class="field-label">ปีงบประมาณ (พ.ศ.)</label>
                    <input id="fiscalYear" type="text" placeholder="เช่น 2567" class="text-input">
                    <span class="report-field-error" id="fiscalYearError">@error('fiscal_year'){{ $message }}@enderror</span>
                </div>

                <div class="field-group">
                    <label class="field-label">หน่วยงาน</label>
                    <div class="searchable-field">
                        <input id="orgSearch" type="text" placeholder="ค้นหาหน่วยงาน..."
                               class="searchable-input" autocomplete="off">
                        <div class="dropdown-suggestions" id="orgSuggestions" style="display: none;"></div>
                        <input id="orgId" type="hidden">
                    </div>
                    <span class="report-field-error" id="orgError">@error('org_id'){{ $message }}@enderror</span>
                </div>

                <div class="field-group">
                    <label class="field-label">หน่วยงานย่อย</label>
                    <div class="searchable-field">
                        <input id="subOrgSearch" type="text" placeholder="ค้นหาหน่วยงานย่อย..."
                               class="searchable-input" autocomplete="off">
                        <div class="dropdown-suggestions" id="subOrgSuggestions" style="display: none;"></div>
                        <input id="subOrgId" type="hidden">
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
                        <button class="preview-btn" type="button" id="previewReportButton"
                            data-register-url="{{ route('asset.reports.register') }}"
                                data-ledger-url="{{ route('asset.reports.ledger') }}"
                            data-register-export-url="{{ route('asset.reports.register.export') }}"
                            data-ledger-export-url="{{ route('asset.reports.ledger.export') }}">
                        <svg><use href="#icon-printer"></use></svg>
                        <span>พรีวิว</span>
                    </button>
                </div>
            </div>
        </section>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-009-print-asset-report/script.js'])
@endsection
