@extends('layouts.app')

@section('title', 'จัดการรายการวัสดุ')

@section('page-style')
    @vite(['resources/css/material/MAT-001-manage-material-items/style.css'])
@endsection

@section('content')
    <div class="page-container">
        <x-page-header :title="$pageTitle" />

        <section class="material-form-card">
            <div class="form-card-title">
                <svg class="form-title-icon"><use href="#icon-edit-form"></use></svg>
                <span>บันทึกข้อมูลวัสดุ</span>
            </div>

            <form id="materialCreateForm" class="material-form" autocomplete="off" action="{{ route('material.items.store') }}" method="POST">
                @csrf
                <div class="form-grid two-column">
                    <div class="form-field">
                        <label for="materialCode">รหัสวัสดุ <span class="required">*</span></label>
                        <input id="materialCode" name="MAT_CODE" type="text"
                               value="{{ old('MAT_CODE') }}"
                               placeholder="กรอกรหัสวัสดุ"
                               maxlength="10"
                               class="{{ $errors->has('MAT_CODE') ? 'gujajob-is-invalid' : '' }}"
                               required>
                        @error('MAT_CODE')
                            <div class="gujajob-validation-message">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label for="materialName">ชื่อวัสดุ<span class="required">*</span></label>
                        <input id="materialName" name="MAT_NAME" type="text" placeholder="กรอกชื่อวัสดุ" required>
                    </div>
                </div>

                <div class="form-field full-width">
                    <label for="materialDescription">คุณลักษณะเฉพาะ:</label>
                    <textarea id="materialDescription" name="MAT_DESC" rows="3" placeholder="กรอกคุณลักษณะเฉพาะของวัสดุ"></textarea>
                </div>

                <div class="form-grid three-column">
                    <div class="form-field">
                        <label for="materialUnit">หน่วยนับ <span class="required">*</span></label>
                        <select id="materialUnit" name="UNIT" required>
                            <option value="">เลือกหน่วยนับ</option>
                            <option value="รีม">รีม</option>
                            <option value="กล่อง">กล่อง</option>
                            <option value="ชิ้น">ชิ้น</option>
                            <option value="แพ็ค">แพ็ค</option>
                            <option value="ขวด">ขวด</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="materialMin">จำนวนคงเหลือต่ำสุด</label>
                        <input id="materialMin" name="MIN_AMT" type="number" min="0" placeholder="0">
                    </div>

                    <div class="form-field">
                        <label for="materialMax">จำนวนคงเหลือสูงสุด</label>
                        <input id="materialMax" name="MAX_AMT" type="number" min="0" placeholder="0">
                    </div>
                </div>

                <div class="form-actions">
                    <a class="cancel-btn" href="{{ route('material.items.index') }}">ย้อนกลับ</a>
                    <button class="submit-btn" type="submit">บันทึก</button>
                </div>
            </form>
        </section>
    </div>

    <div class="confirm-overlay" id="confirmOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon success-icon save-confirm-icon">
                <svg><use href="#icon-success"></use></svg>
            </div>

            <h3>ยืนยันการบันทึกข้อมูล</h3>
            <p>คุณแน่ใจหรือไม่ว่าต้องการบันทึกข้อมูลรายการวัสดุนี้</p>

            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelConfirmButton">ยกเลิก</button>
                <button class="modal-confirm-btn success-btn" type="button" id="confirmSaveButton">ยืนยันการบันทึก</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/material/MAT-001-manage-material-items/script.js'])
@endsection
