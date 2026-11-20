@extends('layouts.app')

@section('title', 'จัดการรายการวัสดุ')

@section('page-style')
    @vite(['resources/css/material/MAT-001-manage-material-items/style.css'])
@endsection

@section('content')
    <div class="page-container">
        <x-page-header :title="$pageTitle" />

        <section class="material-form-card edit-mode-card">
            <div class="form-card-title">
                <svg class="form-title-icon"><use href="#icon-edit-form"></use></svg>
                <span>แก้ไขข้อมูลวัสดุ</span>
            </div>

            <form id="materialEditForm" class="material-form" autocomplete="off" action="{{ route('material.items.update', $material->mat_code) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="form-grid two-column">
                    <div class="form-field">
                        <label for="materialCode">รหัสวัสดุ <span class="required">*</span></label>
                        <input id="materialCode" type="text" value="{{ $material->mat_code }}" readonly>
                    </div>

                    <div class="form-field">
                        <label for="materialName">ชื่อวัสดุ<span class="required">*</span></label>
                        <input id="materialName" name="MAT_NAME" type="text" value="{{ old('MAT_NAME', $material->mat_name) }}" required>
                    </div>
                </div>

                <div class="form-field full-width">
                    <label for="materialDescription">คุณลักษณะเฉพาะ:</label>
                    <textarea id="materialDescription" name="MAT_DESC" rows="3" data-autogrow>{{ old('MAT_DESC', $material->mat_desc) }}</textarea>
                </div>

                <div class="form-grid three-column">
                    <div class="form-field">
                        <label for="materialUnit">หน่วยนับ <span class="required">*</span></label>
                        <select id="materialUnit" name="UNIT" required>
                            <option value="รีม" {{ old('UNIT', $material->unit) === 'รีม' ? 'selected' : '' }}>รีม</option>
                            <option value="กล่อง" {{ old('UNIT', $material->unit) === 'กล่อง' ? 'selected' : '' }}>กล่อง</option>
                            <option value="ชิ้น" {{ old('UNIT', $material->unit) === 'ชิ้น' ? 'selected' : '' }}>ชิ้น</option>
                            <option value="แพ็ค" {{ old('UNIT', $material->unit) === 'แพ็ค' ? 'selected' : '' }}>แพ็ค</option>
                            <option value="ขวด" {{ old('UNIT', $material->unit) === 'ขวด' ? 'selected' : '' }}>ขวด</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="materialMin">จำนวนคงเหลือต่ำสุด</label>
                        <input id="materialMin" name="MIN_AMT" type="number" min="0" value="{{ old('MIN_AMT', $material->min_amt) }}">
                    </div>

                    <div class="form-field">
                        <label for="materialMax">จำนวนคงเหลือสูงสุด</label>
                        <input id="materialMax" name="MAX_AMT" type="number" min="0" value="{{ old('MAX_AMT', $material->max_amt) }}">
                    </div>
                </div>

                <div class="form-actions">
                    <a class="cancel-btn" href="{{ route('material.items.index') }}">ย้อนกลับ</a>
                    <button class="submit-btn edit-submit-btn" type="submit">บันทึก</button>
                </div>
            </form>
        </section>
    </div>

    <div class="confirm-overlay" id="editOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon edit-icon">
                <svg class="confirm-edit-svg"><use href="#icon-square-pen"></use></svg>
            </div>

            <h3>ยืนยันการแก้ไขข้อมูล</h3>
            <p>
                คุณแน่ใจหรือไม่ว่าต้องการแก้ไขข้อมูลวัสดุ '{{ $material->mat_code }}'<br>
                การดำเนินการนี้ไม่สามารถเรียกคืนได้
            </p>

            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelEditButton">ยกเลิก</button>
                <button class="modal-confirm-btn edit-confirm-btn" type="button" id="confirmEditButton">ยืนยันการแก้ไข</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/material/MAT-001-manage-material-items/script.js'])
@endsection
