@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/search-autocomplete.css',
        'resources/css/components/searchable-select.css',
        'resources/css/asset/ASS-003-manage-asset-registration/create.css',
    ])
@endsection

@section('content')
    <div class="page-container asset-registration-create-page">
        <x-page-header :title="$pageTitle" />

        @error('general')
            <div class="gujajob-validation-alert">{{ $message }}</div>
        @enderror

        <section class="form-card">
            <div class="form-title">
                <svg class="form-title-icon"><use href="#icon-square-pen"></use></svg>
                <span>บันทึกทะเบียนครุภัณฑ์ใหม่</span>
            </div>

            <form class="registration-form" method="POST" action="{{ route('asset.registrations.store') }}" autocomplete="off" enctype="multipart/form-data">
                @csrf

                {{-- Search panel for category --}}
                <div class="search-panel">
                    <span class="search-panel-label">ค้นหาประเภทครุภัณฑ์</span>
                    <div class="search-grid">
                        <div class="form-field">
                            <label for="catSearchType">ค้นหาจาก</label>
                            <select id="catSearchType">
                                <option value="name">ชื่อประเภท</option>
                                <option value="code">รหัสประเภท</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="catSearchInput">คำค้นหา</label>
                            <input id="catSearchInput" type="search" autocomplete="off" placeholder="พิมพ์เพื่อค้นหา" spellcheck="false">
                        </div>
                        <button class="small-search-btn" type="button" id="catSearchBtn">ค้นหา</button>
                    </div>
                </div>

                {{-- ─────────────────────────────────────────────────────── --}}
                <h3 class="section-title">1. ข้อมูลทั่วไปของครุภัณฑ์</h3>

                <div class="form-row four-col">
                    <div class="form-field">
                           <label for="assCode">รหัสครุภัณฑ์ประจำหน่วยงาน</label>
                           <input id="assCode" type="text" maxlength="15"
                               placeholder="-" value="{{ old('ass_code') }}" readonly>
                           <input type="hidden" name="ass_code" value="{{ old('ass_code') }}">
                        @error('ass_code')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                </div>

                {{-- Hidden asscat_id submitted to backend --}}
                <input id="asscatId" name="asscat_id" type="hidden" value="{{ old('asscat_id') }}">
                @error('asscat_id')<span class="field-error" style="color:#dc2626;font-size:11px;font-weight:700;">{{ $message }}</span>@enderror

                {{-- 4-column category details (populated by search) --}}
                <div class="form-row four-col compact-top">
                    <div class="form-field">
                        <label for="asscatCode">รหัสประเภทครุภัณฑ์</label>
                        <input id="asscatCode" type="text" readonly placeholder="-">
                    </div>
                    <div class="form-field">
                        <label for="asscatGroup">หมวดครุภัณฑ์</label>
                        <input id="asscatGroup" type="text" readonly placeholder="-">
                    </div>
                    <div class="form-field">
                        <label for="asscatType">ชนิดครุภัณฑ์</label>
                        <input id="asscatType" type="text" readonly placeholder="-">
                    </div>
                    <div class="form-field">
                        <label for="asscatName">ชื่อครุภัณฑ์ <span class="required">*</span></label>
                        <input id="asscatName" type="text" readonly placeholder="-"
                               data-required-label="ชื่อครุภัณฑ์">
                    </div>
                </div>

                <div class="form-row two-col unit-rate-row">
                    <div class="form-field">
                        <label for="asscatUnit">หน่วยนับ</label>
                        <input id="asscatUnit" type="text" readonly placeholder="-">
                    </div>
                    <div class="form-field">
                        <label for="depreciationRate">อัตราค่าเสื่อม</label>
                        <input id="depreciationRate" type="text" readonly placeholder="-">
                    </div>
                    <div class="form-field">
                        <label for="replacementStatus">สถานะการซื้อทดแทน</label>
                        <select id="replacementStatus" name="replacement_status">
                            @foreach (\App\Http\Controllers\AssetController::REPLACEMENT_STATUS as $val => $label)
                                <option value="{{ $val }}" @selected(old('replacement_status', 'no') === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('replacement_status')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="form-row one-col">
                    <div class="form-field">
                        <label for="assDesc">ลักษณะ / รายละเอียดครุภัณฑ์</label>
                        <textarea id="assDesc" name="ass_desc" rows="3" maxlength="500" placeholder="กรอกรายละเอียด">{{ old('ass_desc') }}</textarea>
                        @error('ass_desc')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="form-row three-col">
                    <div class="form-field">
                        <label for="assModel">รุ่น / แบบ</label>
                        <input id="assModel" name="ass_model" type="text" maxlength="100" value="{{ old('ass_model') }}" placeholder="กรอกรุ่น/แบบ">
                        @error('ass_model')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-field">
                        <label for="assSerail">หมายเลขเครื่อง</label>
                        <input id="assSerail" name="ass_serail" type="text" maxlength="30" value="{{ old('ass_serail') }}" placeholder="กรอก Serial">
                        @error('ass_serail')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-field">
                        <label for="assPrice">มูลค่าครุภัณฑ์ (บาท)</label>
                        <input id="assPrice" name="ass_price" type="number" step="0.01" min="0" value="{{ old('ass_price') }}" placeholder="0.00">
                        @error('ass_price')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                </div>

                {{-- ─────────────────────────────────────────────────────── --}}
                <h3 class="section-title">2. ผู้ประกอบการและสัญญา</h3>

                <div class="form-row four-col">
                    <div class="form-field">
                        <label for="dealerId">ผู้ประกอบการ</label>
                        <div class="guja-autocomplete"
                             data-server-select
                             data-endpoint="{{ route('search.suggestions') }}?entity=dealer_search&limit=20&q="
                             data-initial-label="{{ old('_dealer_label', '') }}">
                            <input type="text"   class="guja-autocomplete__input" placeholder="พิมพ์ชื่อผู้ประกอบการ" autocomplete="off">
                            <span               class="guja-autocomplete__arrow">▼</span>
                            <div               class="guja-autocomplete__items"></div>
                            <input type="hidden" class="guja-autocomplete__value" id="dealerId" name="dealer_id" value="{{ old('dealer_id', '') }}">
                        </div>
                        @error('dealer_id')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-field">
                        <label for="assContactNo">เลขที่สัญญา</label>
                        <input id="assContactNo" name="ass_contact_no" type="text" maxlength="20" value="{{ old('ass_contact_no') }}" placeholder="กรอกเลขที่สัญญา">
                        @error('ass_contact_no')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-field">
                        <label for="assContactDate">วันที่ทำสัญญา</label>
                        <input id="assContactDate" name="ass_contact_date" type="text" class="js-date-picker" value="{{ old('ass_contact_date', now()->format('Y-m-d')) }}" placeholder="วว-ดด-ปปปป" data-picker-position="below">
                        @error('ass_contact_date')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-field">
                        <label for="inspectDate">วันที่ตรวจรับ</label>
                        <input id="inspectDate" name="inspect_date" type="text" class="js-date-picker" value="{{ old('inspect_date', now()->format('Y-m-d')) }}" placeholder="วว-ดด-ปปปป" data-picker-position="below">
                        @error('inspect_date')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                    </div>
                </div>

                {{-- ─────────────────────────────────────────────────────── --}}
                <h3 class="section-title">3. อายุการใช้งานและการรับประกัน</h3>

                <div class="section3-grid">
                    {{-- Left: fields --}}
                    <div class="section3-left">
                        <div class="form-row one-col">
                            <div class="form-field">
                                <label for="assStatus">สถานะ <span class="required-mark">*</span></label>
                                <select id="assStatus" name="ass_status" required>
                                    @foreach (\App\Http\Controllers\AssetController::ASSET_STATUS as $val => $info)
                                        <option value="{{ $val }}" @selected(old('ass_status', '1') === (string) $val)>{{ $info['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('ass_status')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <div class="form-row two-col">
                            <div class="form-field">
                                <label for="warranty">ระยะเวลารับประกัน (วัน)</label>
                                <input id="warranty" name="warranty" type="number" min="0" value="{{ old('warranty') }}" placeholder="0">
                                @error('warranty')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                            </div>
                            <div class="form-field">
                                <label for="assLifetime">อายุครุภัณฑ์ (ปี)</label>
                                <input id="assLifetime" name="ass_lifetime" type="number" min="0" value="{{ old('ass_lifetime') }}" placeholder="0">
                                @error('ass_lifetime')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <div class="form-row one-col">
                            <div class="form-field">
                                <label for="remainPrice">มูลค่าคงเหลือปัจจุบัน (บาท)</label>
                                <input id="remainPrice" type="text" readonly class="readonly-field" value="{{ old('ass_price') ? number_format((float) old('ass_price'), 2) : '-' }}" placeholder="-" tabindex="-1">
                                <p class="field-hint">คำนวณจากมูลค่าครุภัณฑ์ — ไม่สามารถแก้ไขได้</p>
                            </div>
                        </div>
                        <div class="form-row one-col">
                            <div class="form-field">
                                <label for="remarks">หมายเหตุ</label>
                                <textarea id="remarks" name="remarks" rows="3" maxlength="500" placeholder="หมายเหตุ">{{ old('remarks') }}</textarea>
                                @error('remarks')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                            </div>
                        </div>
                    </div>

                    {{-- Right: image upload --}}
                    <div class="section3-right">
                        <div class="form-field upload-field">
                            <label>แนบรูปภาพครุภัณฑ์</label>
                            <div class="upload-box" id="assetImageDropzone" role="button" tabindex="0" aria-label="เลือกรูปภาพ">
                                <svg aria-hidden="true"><use href="#icon-upload"></use></svg>
                                <span>คลิกเพื่ออัปโหลด<br>หรือลากไฟล์มาวางที่นี่</span>
                                <input id="assetImageInput" type="file" name="asset_images[]" accept="image/*" multiple>
                            </div>
                            <p class="upload-hint">รองรับ JPG, PNG, GIF ขนาดไม่เกิน 1 MB ต่อไฟล์</p>
                            <div class="file-chip-list" id="assetImageList"></div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a class="cancel-btn" href="{{ route('asset.registrations.index') }}">ย้อนกลับ</a>
                    <button class="submit-btn" type="button" id="saveAssetRegistrationButton">บันทึก</button>
                </div>
            </form>
        </section>
    </div>

    {{-- Green+Check save confirmation popup --}}
    <div class="confirm-overlay" id="saveAssetRegistrationOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon success-confirm-icon save-confirm-icon">
                <svg><use href="#icon-success"></use></svg>
            </div>
            <h3>ยืนยันการบันทึกข้อมูล</h3>
            <p>คุณแน่ใจหรือไม่ว่าต้องการบันทึกทะเบียนครุภัณฑ์นี้</p>
            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelSaveAssetRegistrationButton">ยกเลิก</button>
                <button class="modal-confirm-btn success-confirm-btn" type="button" id="confirmSaveAssetRegistrationButton">ยืนยันการบันทึก</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite([
        'resources/js/components/date-picker.js',
        'resources/js/asset/ASS-003-manage-asset-registration/create.js',
    ])
@endsection