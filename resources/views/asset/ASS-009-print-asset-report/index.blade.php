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

        {{-- ===== รายงานทะเบียนคุมทรัพย์สิน ===== --}}
        <form class="report-card condition-section" id="registerConditions" method="GET" action="{{ route('asset.reports.register') }}">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-filter-report"></use></svg>
                <span>ส่วนที่ 2: กำหนดเงื่อนไขการออกรายงาน</span>
            </div>

            @if (empty($categoryOptions))
                <p class="condition-empty-note">ยังไม่มีครุภัณฑ์ในหน่วยงานที่ท่านมีสิทธิ์เข้าถึง จึงยังออกรายงานนี้ไม่ได้</p>
            @endif

            <div class="condition-grid">
                <div class="field-group">
                    <label for="categoryId">ประเภทครุภัณฑ์ <span class="required">*</span></label>
                    {{-- มีเฉพาะประเภทที่มีครุภัณฑ์อยู่จริง เลือกแล้วจะไม่เจอรายงานว่าง --}}
                    <x-searchable-select
                        name="category_id"
                        id="categoryId"
                        placeholder="เลือกประเภทครุภัณฑ์"
                        :options="$categoryOptions"
                        :selected="old('category_id', '')"
                    />
                    <span class="report-field-error" id="categoryError">@error('category_id'){{ $message }}@enderror</span>
                </div>

                <div class="field-group">
                    <label for="assetId">รหัสครุภัณฑ์ <span class="required">*</span></label>
                    {{-- รายการจะถูกเติมโดย script.js ตามประเภทที่เลือก --}}
                    <x-searchable-select
                        name="asset_id"
                        id="assetId"
                        placeholder="เลือกประเภทครุภัณฑ์ก่อน"
                        :options="[]"
                        :selected="old('asset_id', '')"
                    />
                    <span class="report-field-error" id="assetError">@error('asset_id'){{ $message }}@enderror</span>
                </div>
            </div>

            <div class="export-panel">
                <p class="export-hint">
                    กดพรีวิวเพื่อดูรายงานบนหน้าจอก่อน จากหน้าพรีวิวจึงเลือกบันทึกเป็น PDF หรือ Excel ได้
                </p>

                <div class="export-actions">
                    <button class="preview-btn" type="submit">
                        <svg><use href="#icon-printer"></use></svg>
                        <span>พรีวิว</span>
                    </button>
                </div>
            </div>
        </form>

        {{-- ===== รายงานทะเบียนครุภัณฑ์ ===== --}}
        <form class="report-card condition-section" id="ledgerConditions" method="GET" action="{{ route('asset.reports.ledger') }}" style="display:none">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-filter-report"></use></svg>
                <span>ส่วนที่ 2: กำหนดเงื่อนไขการออกรายงาน</span>
            </div>

            @if (empty($parentOrgOptions))
                <p class="condition-empty-note">ยังไม่มีครุภัณฑ์สถานะ “ปกติ” ในหน่วยงานที่ท่านมีสิทธิ์เข้าถึง จึงยังออกรายงานนี้ไม่ได้</p>
            @endif

            <div class="condition-grid">
                <div class="field-group">
                    <label for="orgId">หน่วยงาน <span class="required">*</span></label>
                    {{-- ค่าเริ่มต้นเป็นหน่วยงานของผู้ใช้ที่ล็อกอิน และมีเฉพาะหน่วยงานที่มีครุภัณฑ์อยู่จริง --}}
                    <x-searchable-select
                        name="org_id"
                        id="orgId"
                        placeholder="เลือกหน่วยงาน"
                        :options="$parentOrgOptions"
                        :selected="old('org_id', $defaultParentOrgId)"
                    />
                    <span class="report-field-error" id="orgError">@error('org_id'){{ $message }}@enderror</span>
                </div>

                <div class="field-group">
                    <label for="subOrgId">หน่วยงานย่อย <span class="required">*</span></label>
                    <x-searchable-select
                        name="sub_org_id"
                        id="subOrgId"
                        placeholder="เลือกหน่วยงานย่อย"
                        :options="$subOrgsByParent[$defaultParentOrgId] ?? []"
                        :selected="old('sub_org_id', $defaultSubOrgId)"
                    />
                    <span class="report-field-error" id="subOrgError">@error('sub_org_id'){{ $message }}@enderror</span>
                </div>

                <x-fiscal-year-input
                    id="fiscalYear"
                    label="ปีงบประมาณ (พ.ศ.)"
                    :value="old('fiscal_year', '')"
                    :years="$availableFiscalYears"
                    :currentYear="$currentFiscalYear"
                />
            </div>

            <div class="export-panel">
                <p class="export-hint">
                    กดพรีวิวเพื่อดูรายงานบนหน้าจอก่อน จากหน้าพรีวิวจึงเลือกบันทึกเป็น PDF หรือ Excel ได้
                    <span class="export-hint-years" id="fiscalYearAvailability"></span>
                </p>

                <div class="export-actions">
                    <button class="preview-btn" type="submit">
                        <svg><use href="#icon-printer"></use></svg>
                        <span>พรีวิว</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        window.ass009Options = {
            assetsByCategory: @json($assetsByCategory),
            subOrgsByParent: @json($subOrgsByParent),
            fiscalYearsByOrg: @json($fiscalYearsByOrg),
        };
    </script>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-009-print-asset-report/script.js'])
@endsection
