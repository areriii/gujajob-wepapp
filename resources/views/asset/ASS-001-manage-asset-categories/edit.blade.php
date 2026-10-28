@extends('layouts.app')

@section('page-style')
    @vite(['resources/css/asset/ASS-001-manage-asset-categories/style.css'])
@endsection

@section('content')
    <div class="page-container asset-detail-page">
        <x-page-header :title="$pageTitle" />

        <section class="asset-form-card asset-edit-card">
            <div class="form-title">
                <svg class="form-title-icon"><use href="#icon-square-pen"></use></svg>
                <span>แก้ไขประเภทครุภัณฑ์</span>
            </div>

            @if ($errors->has('general'))
                <div class="form-error-box" style="margin-bottom:16px;">{{ $errors->first('general') }}</div>
            @endif

            <form class="asset-category-form" id="assetCategoryEditForm" method="POST" action="{{ route('asset.categories.update', $category->id) }}" autocomplete="off">
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <div class="form-field">
                        <label for="asscat_code">รหัสประเภทครุภัณฑ์ <span class="required">*</span></label>
                        <input
                            id="asscat_code"
                            name="asscat_code"
                            type="text"
                            value="{{ old('asscat_code', $category->asscat_code) }}"
                            required
                        >
                        @error('asscat_code')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label for="asscat_name">ชื่อครุภัณฑ์ <span class="required">*</span></label>
                        <input
                            id="asscat_name"
                            name="asscat_name"
                            type="text"
                            value="{{ old('asscat_name', $category->asscat_name) }}"
                            required
                        >
                        @error('asscat_name')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label for="asscat_type">ชนิดครุภัณฑ์ <span class="required">*</span></label>
                        <input
                            id="asscat_type"
                            name="asscat_type"
                            type="text"
                            value="{{ old('asscat_type', $category->asscat_type) }}"
                            required
                        >
                        @error('asscat_type')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label for="asscat_group">หมวดครุภัณฑ์ <span class="required">*</span></label>
                        <input
                            id="asscat_group"
                            name="asscat_group"
                            type="text"
                            value="{{ old('asscat_group', $category->asscat_group) }}"
                            required
                        >
                        @error('asscat_group')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label for="asscat_unit">หน่วยนับ</label>
                        <select id="asscat_unit" name="asscat_unit">
                            <option value="">เลือกหน่วยนับ</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit }}" @selected(old('asscat_unit', $category->asscat_unit) === $unit)>{{ $unit }}</option>
                            @endforeach
                        </select>
                        @error('asscat_unit')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label for="depreciation_rate">อัตราค่าเสื่อม (%)</label>
                        <input
                            id="depreciation_rate"
                            name="depreciation_rate"
                            type="number"
                            value="{{ old('depreciation_rate', $category->depreciation_rate) }}"
                            min="0"
                            max="100"
                            step="0.01"
                        >
                        @error('depreciation_rate')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="form-actions detail-actions">
                    <a class="cancel-btn" href="{{ route('asset.categories.index') }}">ย้อนกลับ</a>
                    <button class="submit-btn" type="button" id="saveAssetCategoryButton">
                        บันทึก
                    </button>
                </div>
            </form>
        </section>
    </div>

    {{-- Edit confirmation modal (yellow) --}}
    <div class="confirm-overlay" id="editAssetCategoryOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon edit-confirm-icon">
                <svg><use href="#icon-square-pen"></use></svg>
            </div>
            <h3>ยืนยันการแก้ไขข้อมูล</h3>
            <p>
                คุณแน่ใจหรือไม่ว่าต้องการแก้ไขข้อมูลประเภทครุภัณฑ์<br>
                การดำเนินการนี้ไม่สามารถเรียกคืนได้
            </p>
            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelEditAssetCategoryButton">ยกเลิก</button>
                <button class="modal-confirm-btn edit-confirm-btn" type="button" id="confirmEditAssetCategoryButton">ยืนยันการแก้ไข</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-001-manage-asset-categories/script.js'])
@endsection
