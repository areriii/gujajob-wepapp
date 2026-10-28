@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/search-autocomplete.css',
        'resources/css/components/table-actions.css',
        'resources/css/material/MAT-003-withdraw-material/style.css',
    ])
@endsection

@section('content')
    <div class="page-container withdraw-create-page">
        <x-page-header :title="$pageTitle" />

        @if ($errors->any())
            <div class="form-error-box">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('error'))
            <div class="form-error-box">{{ session('error') }}</div>
        @endif

        <form id="withdrawForm" method="POST" action="{{ route('material.withdraw.store') }}" novalidate>
            @csrf
            <input type="hidden" id="itemsJsonHidden" name="items_json" value="{{ old('items_json', '') }}">
            <input type="hidden" id="withdrawTypeHidden" name="withdraw_action" value="{{ $withdrawMode }}">
            <input type="hidden" name="org_id" value="{{ $defaultOrg?->org_id ?? '' }}">
            {{-- Restore items table after validation error --}}
            @if (old('items_json'))
            <script>window.mat003OldItems = {!! json_encode(json_decode(old('items_json', '[]'), true) ?? []) !!};</script>
            @endif

        <section class="withdraw-form-card">
            <div class="form-section-title">
                <span>{{ $pageTitle }}</span>
            </div>

            <div class="withdraw-document-grid">
                <div class="form-field">
                    <label for="withdrawNo">เลขที่ใบเบิก</label>
                    <input id="withdrawNo" type="text" value="สร้างอัตโนมัติเมื่อบันทึก" readonly class="input-placeholder-hint">
                </div>

                <div class="form-field">
                    <label for="withdrawDate">วันที่เบิก <span class="required">*</span></label>
                    <input id="withdrawDate" name="mat_wd_date" type="text" class="js-date-picker" placeholder="วว-ดด-ปปปป" data-picker-position="below" value="{{ old('mat_wd_date', now()->format('Y-m-d')) }}" required>
                </div>

                <div class="form-field">
                    <label for="withdrawDepartment">หน่วยงานที่ขอเบิก <span class="required">*</span></label>
                    <input id="withdrawDepartment" type="text" value="{{ $defaultOrg?->org_name ?? 'ไม่พบหน่วยงาน' }}" readonly>
                </div>

                <div class="form-field">
                    <label for="withdrawerName">ชื่อผู้เบิกวัสดุ <span class="required">*</span></label>
                    <input id="withdrawerName" name="mat_wd_person" type="text" value="{{ old('mat_wd_person', $defaultPersonName) }}" placeholder="ระบุชื่อ-นามสกุล" required>
                </div>

                <div class="form-field">
                    <label for="approveDepartment">หน่วยงาน (ผู้อนุมัติ)</label>
                    <input id="approveDepartment" type="text" value="{{ $defaultOrg?->org_name ?? '-' }}" readonly>
                </div>
            </div>

            {{-- ─── เฟรมรายการวัสดุที่ต้องการเบิก ─────────────────────────── --}}
            <div class="withdraw-items-title">
                <svg class="section-icon"><use href="#icon-panel"></use></svg>
                <span>ชื่อวัสดุที่ต้องการเบิก</span>
            </div>

            <div class="material-search-box">
                <div class="field-group">
                    <label for="materialSearchType">ค้นหาจาก</label>
                    <select id="materialSearchType">
                        <option value="code">รหัสวัสดุ</option>
                        <option value="name">ชื่อวัสดุ</option>
                    </select>
                </div>

                <div class="field-group search-white">
                    <label for="materialSearchInput">คำค้นหา</label>
                    <input
                        id="materialSearchInput"
                        type="search"
                        value=""
                        placeholder="กรอกรหัสวัสดุ"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="off"
                        spellcheck="false"
                    >
                </div>

                <button class="search-btn" type="button" id="findWithdrawMaterialButton">ค้นหา</button>
            </div>

            <div class="withdraw-item-form">
                <div class="form-field">
                    <label for="materialCode">รหัสวัสดุ</label>
                    <input id="materialCode" type="text" value="" readonly>
                </div>

                <div class="form-field material-name-field">
                    <label for="materialName">ชื่อวัสดุ</label>
                    <input id="materialName" type="text" value="" readonly>
                </div>

                <div class="form-field">
                    <label for="withdrawQty">จำนวนที่เบิก <span class="required">*</span></label>
                    <input id="withdrawQty" type="number" min="1" step="1" value="" data-validate-on-add>
                </div>

                <div class="form-field">
                    <label for="materialUnit">หน่วยนับ</label>
                    <input id="materialUnit" type="text" value="" readonly>
                </div>

                <button class="add-btn" type="button" id="addWithdrawMaterialButton">เพิ่ม</button>
            </div>

            <p id="stockInfo" class="stock-info-text" hidden></p>

            {{-- Filled by script.js once items are on the list: materials this organization usually withdraws with them --}}
            <div class="withdraw-recommend" id="withdrawRecommendations" data-url="{{ route('material.withdraw.recommendations') }}" hidden>
                <div class="withdraw-recommend-title">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M9 18h6"/>
                        <path d="M10 22h4"/>
                        <path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>
                    </svg>
                    <span>แนะนำจากประวัติการเบิกของหน่วยงาน</span>
                </div>
                <div class="withdraw-recommend-list" id="withdrawRecommendationList"></div>
            </div>

            <h3 class="sub-table-title">รายการวัสดุที่เบิก</h3>

            <div class="table-wrapper compact-table-wrapper">
                <table class="withdraw-items-table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>รหัสวัสดุ</th>
                            <th>ชื่อวัสดุ</th>
                            <th>จำนวนที่เบิก</th>
                            <th>หน่วยนับ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="withdrawItemsBody"></tbody>
                </table>
            </div>

            <div class="form-actions">
                <a class="cancel-btn" href="{{ route('material.withdraw.index') }}">ย้อนกลับ</a>
                <button class="submit-btn" type="button" id="saveWithdrawButton">บันทึก</button>
            </div>
        </section>
        </form>
    </div>

    {{-- Save confirmation overlay --}}
    <div class="confirm-overlay" id="saveWithdrawOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="saveWithdrawTitle">
            <div class="confirm-icon success-confirm-icon save-confirm-icon">
                <svg><use href="#icon-success"></use></svg>
            </div>
            <h3 id="saveWithdrawTitle">ยืนยันการบันทึกข้อมูลการเบิกวัสดุ</h3>
            <p id="saveWithdrawConfirmText"></p>
            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelSaveWithdrawButton">ยกเลิก</button>
                <button class="modal-confirm-btn success-confirm-btn" type="button" id="confirmSaveWithdrawButton">ยืนยัน</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/material/MAT-003-withdraw-material/script.js'])
@endsection
