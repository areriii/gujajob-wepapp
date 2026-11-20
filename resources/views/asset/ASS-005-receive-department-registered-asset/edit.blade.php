@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/searchable-select.css',
        'resources/css/asset/ASS-005-receive-department-registered-asset/style.css',
        'resources/css/asset/ASS-005-receive-department-registered-asset/show.css',
    ])
@endsection

@section('content')
    <div class="page-container receiving-confirm-page">
        <x-page-header :title="$pageTitle" />

        @if (session('error'))
            <div class="form-error-box" style="margin-bottom:12px;padding:10px 14px;background:#fee2e2;border:1px solid #f87171;border-radius:6px;font-size:13px;color:#991b1b;">
                {{ session('error') }}
            </div>
        @endif

        <section class="receive-card">
            <h3 class="receive-card-title">ข้อมูลครุภัณฑ์</h3>

            <div class="asset-summary">
                <div class="summary-item">
                    <span>รหัสครุภัณฑ์ประจำหน่วยงาน</span>
                    <strong>{{ $asset->ass_code ?? '-' }}</strong>
                </div>
                <div class="summary-item">
                    <span>ชื่อครุภัณฑ์</span>
                    <strong>{{ $asset->asscat_name ?? '-' }}</strong>
                </div>
                <div class="summary-item">
                    <span>หมวดครุภัณฑ์</span>
                    <strong>{{ $asset->asscat_group ?? '-' }}</strong>
                </div>
                <div class="summary-item">
                    <span>มูลค่า</span>
                    <strong>{{ $asset->ass_price !== null ? number_format((float) $asset->ass_price, 2) : '-' }} บาท</strong>
                </div>
                <div class="summary-item">
                    <span>รุ่น/แบบ</span>
                    <strong>{{ $asset->ass_model ?? '-' }}</strong>
                </div>
                <div class="summary-item">
                    <span>Serial No.</span>
                    <strong>{{ $asset->ass_serail ?? '-' }}</strong>
                </div>
                <div class="summary-item">
                    <span>จัดสรรให้</span>
                    <strong>{{ $asset->target_org_name ?? '-' }}</strong>
                </div>
            </div>

            <h4 class="form-section-title">แก้ไขข้อมูลการรับ</h4>

            <form class="receive-form" id="editForm"
                  action="{{ route('asset.department-receiving.update', $asset->id) }}"
                  method="POST"
                  autocomplete="off">
                @csrf
                @method('PUT')
                <div class="receive-grid">
                    <div class="field-group">
                        <label>หน่วยงานที่รับการจัดสรร</label>
                        <input type="text" value="{{ $asset->target_org_name ?? '-' }}" readonly>
                    </div>

                    <div class="field-group">
                        <label>หน่วยงานที่ครุภัณฑ์ประจำห้อง <span class="required-mark">*</span></label>
                        <div class="guja-autocomplete" data-server-select
                             data-endpoint="{{ route('search.suggestions') }}?entity=assign_org&limit=20&q="
                             data-initial-label="{{ old('_sub_org_label', $asset->sub_org_name ?? '') }}">
                            <div class="guja-autocomplete__wrap">
                                <input type="text" class="guja-autocomplete__input" placeholder="ค้นหาหน่วยงานย่อย..." autocomplete="off" data-required="true">
                                <input type="hidden" class="guja-autocomplete__value" name="sub_org_id" value="{{ old('sub_org_id', $asset->sub_org_id ?? '') }}">
                                <button type="button" class="guja-autocomplete__arrow" tabindex="-1" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 5l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                            </div>
                            <ul class="guja-autocomplete__items" role="listbox"></ul>
                        </div>
                        @error('sub_org_id')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field-group">
                        <label for="assCode">รหัสครุภัณฑ์ประจำหน่วยงาน <span class="required-mark">*</span></label>
                        <input id="assCode" name="ass_code" type="text" maxlength="15" value="{{ old('ass_code', $asset->ass_code ?? '') }}" required>
                        @error('ass_code')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field-group">
                        <label for="receiveDate">วันที่รับ <span class="required-mark">*</span></label>
                        <input
                            id="receiveDate"
                            name="receive_date"
                            type="text"
                            class="js-date-picker"
                            value="{{ old('receive_date', $asset->ass_trans_date_iso ?? now()->format('Y-m-d')) }}"
                            placeholder="DD-MM-BBBB"
                            data-required="true"
                            required
                        >
                        @error('receive_date')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field-group">
                        <label>ชื่อผู้รับ</label>
                        <input type="text" value="{{ $asset->ass_trans_person ?? '-' }}" readonly>
                    </div>

                    <div class="field-group">
                        <label>ชื่อผู้รับ</label>
                        <input type="text" value="{{ $asset->ass_trans_person ?? '-' }}" readonly>
                    </div>

                    <div class="field-group">
                        <label for="editRemark">หมายเหตุ</label>
                        <textarea id="editRemark" name="remark" rows="3" data-autogrow data-autogrow-max="200" placeholder="หมายเหตุการรับครุภัณฑ์ (ถ้ามี)">{{ old('remark', $asset->ass_trans_remark ?? '') }}</textarea>
                    </div>
                </div>
            </form>

            <div class="form-actions">
                <a class="cancel-btn" href="{{ route('asset.department-receiving.index') }}">ย้อนกลับ</a>
                <button class="save-btn" id="openEditConfirmButton" type="button">บันทึก</button>
            </div>
        </section>
    </div>

    <div class="confirm-overlay" id="editConfirmOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="editConfirmTitle">
            <div class="confirm-icon edit-confirm-icon">
                <svg><use href="#icon-square-pen"></use></svg>
            </div>

            <h3 id="editConfirmTitle">ยืนยันการแก้ไขข้อมูล</h3>
            <p>คุณแน่ใจหรือไม่ว่าต้องการแก้ไขข้อมูลการรับครุภัณฑ์<br>การดำเนินการนี้จะบันทึกทับข้อมูลเดิม</p>

            <div class="confirm-actions">
                <button class="modal-cancel-btn" id="cancelEditConfirmButton" type="button">ยกเลิก</button>
                <button class="modal-confirm-btn edit-confirm-btn" id="confirmEditButton" type="button">ยืนยันการแก้ไข</button>
            </div>
        </div>
    </div>

@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-005-receive-department-registered-asset/script.js'])
@endsection
