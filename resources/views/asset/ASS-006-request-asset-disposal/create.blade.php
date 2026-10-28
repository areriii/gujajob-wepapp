@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/table-actions.css',
        'resources/css/asset/ASS-006-request-asset-disposal/style.css',
    ])
@endsection

@section('content')
    <div class="page-container disposal-page disposal-create-page">
        <x-page-header :title="$pageTitle" />

        @if ($errors->has('_error'))
            <div class="disposal-inline-validation-error" role="alert">
                {{ $errors->first('_error') }}
            </div>
        @endif

        <section class="detail-card disposal-create-card">
            <h3 class="create-title">บันทึกการแจ้งขอจำหน่าย</h3>

            <form method="POST"
                  id="disposalCreateForm"
                  action="{{ route('asset.disposals.store') }}"
                  data-asset-search-url="{{ route('asset.disposals.assets.search') }}">
                @csrf

                <div class="create-shell">

                    {{-- Section 1: ใบขอจำหน่าย --}}
                    <section class="create-section">
                        <h4 class="create-section-title">
                            <svg class="section-title-icon" aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                            ใบขอจำหน่ายครุภัณฑ์
                        </h4>

                        <div class="detail-grid three-col">
                            <div class="field-group">
                                <label for="requestNo">เลขที่ใบขอจำหน่ายครุภัณฑ์</label>
                                <input id="requestNo" type="text" value="{{ $nextRequestNo }}" readonly>
                            </div>

                            <div class="field-group">
                                <label for="selling_req_date">วันที่แจ้งขอจำหน่าย <span class="required">*</span></label>
                                <input
                                    class="js-date-picker @error('selling_req_date') is-invalid @enderror"
                                    id="selling_req_date"
                                    name="selling_req_date"
                                    type="text"
                                    placeholder="วว-ดด-ปปปป"
                                    value="{{ old('selling_req_date', now()->format('Y-m-d')) }}"
                                    autocomplete="off"
                                    required
                                >
                                @error('selling_req_date')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="detail-grid three-col">
                            <div class="field-group">
                                <label for="requestDepartment">หน่วยงานผู้แจ้งขออนุมัติ</label>
                                <input id="requestDepartment" type="text"
                                       value="{{ $userOrg->org_name ?? '-' }}" readonly>
                            </div>

                            <div class="field-group">
                                <label for="reason">เหตุผลในการจำหน่าย <span class="required">*</span></label>
                                <select
                                    id="reason"
                                    name="reason"
                                    class="@error('reason') is-invalid @enderror"
                                    required
                                >
                                    <option value="">-- เลือกเหตุผล --</option>
                                    @foreach ($reasonOptions as $code => $label)
                                        <option value="{{ $code }}" @selected(old('reason') === $code)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('reason')<p class="field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="field-group">
                                <label for="remarks">หมายเหตุ</label>
                                <input id="remarks" name="remarks" type="text"
                                       placeholder="หมายเหตุ (ถ้ามี)"
                                       value="{{ old('remarks', '') }}">
                            </div>
                        </div>
                    </section>

                    {{-- Section 2: รายการครุภัณฑ์ --}}
                    <section class="create-section asset-items-section">
                        <h4 class="create-section-title">
                            <svg class="section-title-icon" aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                            รายการครุภัณฑ์
                        </h4>

                        <div class="asset-search-toolbar">
                            <div class="field-group">
                                <label for="assetSearchType">ค้นหาจาก</label>
                                <select id="assetSearchType">
                                    <option value="">ทั้งหมด</option>
                                    <option value="code">รหัสครุภัณฑ์</option>
                                    <option value="name">ชื่อครุภัณฑ์</option>
                                </select>
                            </div>

                            <div class="field-group">
                                <label for="assetSearchKeyword">คำค้นหา</label>
                                <input id="assetSearchKeyword" type="text"
                                       placeholder="พิมพ์เพื่อค้นหา" autocomplete="off">
                            </div>

                            <div class="field-group">
                                <label style="visibility:hidden">&nbsp;</label>
                                <button type="button" id="assetSearchBtn" class="search-btn small">ค้นหา</button>
                            </div>
                        </div>

                        <div id="assetSearchResultBox" class="asset-result-box" style="display:none;">
                            <table class="asset-result-table">
                                <thead>
                                    <tr>
                                        <th>รหัสครุภัณฑ์</th>
                                        <th>ชื่อครุภัณฑ์</th>
                                        <th class="col-act">เพิ่ม</th>
                                    </tr>
                                </thead>
                                <tbody id="assetResultBody"></tbody>
                            </table>
                        </div>

                        <div class="asset-table-shell">
                            <table class="asset-item-table">
                                <thead>
                                    <tr>
                                        <th>ลำดับ</th>
                                        <th>รหัสครุภัณฑ์</th>
                                        <th>ชื่อครุภัณฑ์</th>
                                        <th>มูลค่าครุภัณฑ์</th>
                                        <th>ราคาจำหน่ายขั้นต่ำ</th>
                                        <th>ราคาที่ขายได้จริง</th>
                                        <th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody id="assetItemTableBody"></tbody>
                            </table>
                        </div>
                    </section>

                    <div class="form-actions create-actions">
                        <a class="cancel-btn disposal-back-btn" href="{{ route('asset.disposals.index') }}">ย้อนกลับ</a>
                        <button class="save-btn" id="saveDisposalButton" type="button">
                            บันทึก
                        </button>
                    </div>
                </div>

            </form>

            {{-- Green-check confirmation modal (inside .disposal-page for CSS scoping) --}}
            <div class="confirm-overlay" id="saveDisposalOverlay" aria-hidden="true">
                <div class="confirm-modal" role="dialog" aria-modal="true"
                     aria-labelledby="saveDisposalConfirmTitle">
                    <div class="confirm-icon success-confirm-icon save-confirm-icon">
                        <svg><use href="#icon-success"></use></svg>
                    </div>
                    <h3 id="saveDisposalConfirmTitle">ยืนยันการแจ้งขอจำหน่าย</h3>
                    <p>คุณแน่ใจหรือไม่ว่าต้องการบันทึกการแจ้งขอจำหน่ายนี้?</p>
                    <div class="confirm-actions">
                        <button class="modal-cancel-btn" id="cancelSaveDisposalButton"
                                type="button">ยกเลิก</button>
                        <button class="modal-confirm-btn success-confirm-btn"
                                id="confirmSaveDisposalButton" type="button">
                            ยืนยัน
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-006-request-asset-disposal/script.js'])
@endsection