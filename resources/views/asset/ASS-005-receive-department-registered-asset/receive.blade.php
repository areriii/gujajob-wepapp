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
            <h4 class="receive-card-title">
                <svg class="section-title-icon" aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                ข้อมูลครุภัณฑ์ที่จะรับ
            </h4>

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

            <h4 class="form-section-title">บันทึกข้อมูลการรับ</h4>

            <form class="receive-form" id="receiveForm"
                  action="{{ route('asset.department-receiving.store', $asset->id) }}"
                  method="POST"
                  autocomplete="off">
                @csrf
                <div class="receive-grid">
                    <div class="field-group">
                        <label>หน่วยงานที่รับการจัดสรร</label>
                        <input type="text" value="{{ $asset->target_org_name ?? '-' }}" readonly>
                    </div>

                    <div class="field-group">
                        <label>หน่วยงานที่ครุภัณฑ์ประจำห้อง <span class="required-mark">*</span></label>
                        <select name="sub_org_id" required>
                            <option value="">-- เลือกหน่วยงานย่อย --</option>
                            @foreach ($subOrgs as $org)
                                <option value="{{ $org->org_id }}" @selected(old('sub_org_id') == $org->org_id)>{{ $org->org_name }}</option>
                            @endforeach
                        </select>
                        @error('sub_org_id')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field-group">
                        <label for="assCode">รหัสครุภัณฑ์ประจำหน่วยงาน <span class="required-mark">*</span></label>
                        <input id="assCode" name="ass_code" type="text" maxlength="15"
                               value="{{ old('ass_code', $asset->ass_code ?? '') }}" required>
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
                            value="{{ old('receive_date', now()->format('Y-m-d')) }}"
                            placeholder="DD-MM-BBBB"
                            data-required="true"
                            required
                        >
                        @error('receive_date')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field-group">
                        <label for="receiverName">ชื่อผู้รับ</label>
                        <input id="receiverName" type="text" value="{{ $currentUser->user_name ?? '' }}" readonly>
                    </div>

                    <div class="field-group">
                        <label for="receiveRemark">หมายเหตุ</label>
                        <textarea id="receiveRemark" name="remark" rows="1" placeholder="หมายเหตุการรับครุภัณฑ์ (ถ้ามี)">{{ old('remark') }}</textarea>
                    </div>
                </div>
            </form>

            <div class="form-actions">
                <a class="cancel-btn" href="{{ route('asset.department-receiving.index') }}">ย้อนกลับ</a>
                <button class="save-btn receive-save-btn" id="openReceiveConfirmButton" type="button">
                    ยืนยัน
                </button>
            </div>
        </section>
    </div>

    <div class="confirm-overlay" id="receiveConfirmOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="receiveConfirmTitle">
            <div class="confirm-icon success-confirm-icon save-confirm-icon">
                <svg><use href="#icon-success"></use></svg>
            </div>

            <h3 id="receiveConfirmTitle">ยืนยันการรับครุภัณฑ์</h3>
            <p>คุณแน่ใจหรือไม่ว่าต้องการยืนยันการรับครุภัณฑ์</p>

            <div class="confirm-actions">
                <button class="modal-cancel-btn" id="cancelReceiveConfirmButton" type="button">ยกเลิก</button>
                <button class="modal-confirm-btn success-confirm-btn" id="confirmReceiveButton" type="button">ยืนยัน</button>
            </div>
        </div>
    </div>

@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-005-receive-department-registered-asset/script.js'])
@endsection
