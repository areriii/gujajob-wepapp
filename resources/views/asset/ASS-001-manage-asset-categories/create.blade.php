@extends('layouts.app')

@section('page-style')
    @vite(['resources/css/asset/ASS-001-manage-asset-categories/style.css'])
@endsection

@section('content')
    <div class="page-container asset-create-page">
        <x-page-header :title="$pageTitle" />

        <section class="asset-form-card">
            <div class="form-title">
                <svg class="form-title-icon"><use href="#icon-square-pen"></use></svg>
                <span>บันทึกประเภทครุภัณฑ์</span>
            </div>

            @if ($errors->has('general'))
                <div class="form-error-box" style="margin-bottom:16px;">{{ $errors->first('general') }}</div>
            @endif

            <form class="asset-category-form" id="assetCategoryCreateForm" method="POST" action="{{ route('asset.categories.store') }}" autocomplete="off">
                @csrf
                <div class="form-grid">
                    <div class="form-field">
                        <label for="asscat_code">รหัสประเภทครุภัณฑ์ <span class="required">*</span></label>
                        <input
                            id="asscat_code"
                            name="asscat_code"
                            type="text"
                            value="{{ old('asscat_code') }}"
                            placeholder="เช่น 7440-001-001"
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
                            value="{{ old('asscat_name') }}"
                            placeholder="ระบุชื่อครุภัณฑ์"
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
                            value="{{ old('asscat_type') }}"
                            placeholder="ระบุชนิดครุภัณฑ์"
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
                            value="{{ old('asscat_group') }}"
                            placeholder="ระบุหมวดครุภัณฑ์"
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
                                <option value="{{ $unit }}" @selected(old('asscat_unit') === $unit)>{{ $unit }}</option>
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
                            value="{{ old('depreciation_rate') }}"
                            placeholder="เช่น 20"
                            min="0"
                            max="100"
                            step="0.01"
                        >
                        @error('depreciation_rate')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="form-actions">
                    <a class="cancel-btn" href="{{ route('asset.categories.index') }}">ย้อนกลับ</a>
                    <button class="submit-btn" type="button" id="saveAssetCategoryButton">
                        บันทึก
                    </button>
                </div>
            </form>
        </section>
    </div>

    {{-- Save confirmation modal (green) --}}
    <div class="confirm-overlay" id="saveAssetCategoryOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon success-confirm-icon save-confirm-icon">
                <svg><use href="#icon-success"></use></svg>
            </div>
            <h3>ยืนยันการบันทึกข้อมูล</h3>
            <p>คุณแน่ใจหรือไม่ว่าต้องการบันทึกข้อมูลประเภทครุภัณฑ์นี้</p>
            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelSaveAssetCategoryButton">ยกเลิก</button>
                <button class="modal-confirm-btn success-confirm-btn" type="button" id="confirmSaveAssetCategoryButton">ยืนยันการบันทึก</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-001-manage-asset-categories/script.js'])
@endsection
