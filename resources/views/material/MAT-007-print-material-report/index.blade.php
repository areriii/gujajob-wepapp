@extends('layouts.app')

@section('page-style')
    @vite(['resources/css/material/MAT-007-print-material-report/style.css'])
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
                <button class="report-type-item active" type="button" data-report-type="stock">
                    <span class="report-icon-box">
                        <svg><use href="#icon-report-file"></use></svg>
                    </span>

                    <span class="report-name">รายงานวัสดุคงเหลือ</span>
                    <span class="report-description">
                        แสดงข้อมูลวัสดุคงเหลือปัจจุบัน พร้อมจำนวนคงเหลือ
                    </span>
                </button>
            </div>
        </section>

        {{-- ส่งเป็น GET ธรรมดา ไม่ต้องให้ JS ประกอบ URL เอง กดปุ่มแล้วไปหน้าพรีวิวตรง ๆ --}}
        <form class="report-card condition-section" method="GET" action="{{ route('material.report.stock') }}">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-filter-report"></use></svg>
                <span>ส่วนที่ 2: กำหนดเงื่อนไขการออกรายงาน</span>
            </div>

            <div class="condition-grid material-report-grid">
                <div class="field-group">
                    <label for="mainDepartment">หน่วยงาน <span class="required">*</span></label>

                    {{-- ตั้งค่าเริ่มต้นเป็นหน่วยงานของผู้ใช้ที่ล็อกอิน และเลือกได้เฉพาะหน่วยงานตัวเองกับหน่วยงานลูก
                         เดิมเป็นช่องพิมพ์ค้นหา ถ้าไม่ได้กดเลือกจากรายการจะไม่มี id ติดไปด้วย --}}
                    <x-searchable-select
                        name="org_id"
                        id="mainDepartment"
                        placeholder="เลือกหน่วยงาน"
                        :options="$organizations->map(fn ($org) => ['value' => (int) $org->org_id, 'label' => $org->org_name])"
                        :selected="old('org_id', $defaultOrgId)"
                    />

                    <span class="report-field-error" id="orgError">@error('org_id'){{ $message }}@enderror</span>
                </div>
            </div>

            <div class="export-panel">
                <p class="export-hint">
                    กดพรีวิวเพื่อดูรายงานบนหน้าจอก่อน จากหน้าพรีวิวจึงเลือกบันทึกเป็น PDF หรือ Excel ได้
                    ถ้าต้องการดูเฉย ๆ ก็ไม่ต้องดาวน์โหลดไฟล์
                </p>

                <div class="export-actions">
                    <button class="preview-btn" type="submit" id="previewReportButton">
                        <svg><use href="#icon-printer"></use></svg>
                        <span>พรีวิว</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

@endsection

@section('page-script')
    @vite(['resources/js/material/MAT-007-print-material-report/script.js'])
@endsection
