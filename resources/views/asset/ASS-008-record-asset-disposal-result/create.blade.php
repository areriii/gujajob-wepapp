@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/asset/ASS-006-request-asset-disposal/style.css',
        'resources/css/asset/ASS-008-record-asset-disposal-result/style.css',
    ])
@endsection

@section('content')
    @php
        $resultButtonText = $isLostReason ? 'ยืนยันการจำหน่าย' : 'บันทึก';
    @endphp

    <div class="page-container disposal-page disposal-create-page result-page">
        <x-page-header :title="$pageTitle" />

        @error('_error')
            <div class="disposal-inline-validation-error">{{ $message }}</div>
        @enderror
        @if ($errors->any() && ! $errors->has('_error'))
            <div class="disposal-inline-validation-error">กรุณาตรวจสอบข้อมูลที่กรอกอีกครั้ง</div>
        @endif

        <section class="detail-card disposal-create-card result-create-card">
            <h3 class="create-title">บันทึกผลการจำหน่ายครุภัณฑ์</h3>

            <form id="resultRecordForm" method="POST" action="{{ route('asset.disposals.results.store', $record->id) }}" data-result-mode="{{ $isLostReason ? 'lost' : 'normal' }}">
                @csrf

                <div class="create-shell">
                    <section class="create-section">
                        <h4 class="create-section-title">
                            <svg class="section-title-icon" aria-hidden="true"><use href="#icon-check-circle"></use></svg>
                            บันทึกผลการจำหน่าย
                        </h4>

                        <div class="detail-grid three-col">
                            <div class="field-group">
                                <label>เลขที่ใบขอจำหน่าย</label>
                                <input type="text" value="{{ $record->selling_code ?? '-' }}" readonly>
                            </div>
                            <div class="field-group">
                                <label>วันที่แจ้งจำหน่าย</label>
                                <input type="text" value="{{ $record->req_date_th ?? '-' }}" readonly>
                            </div>
                            <div class="field-group">
                                <label>สถานะ</label>
                                <div class="readonly-badge-cell">
                                    <span class="status-pill {{ $statusInfo['type'] }}">{{ $statusInfo['label'] }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="detail-grid three-col">
                            <div class="field-group">
                                <label>หน่วยงานผู้แจ้ง</label>
                                <input type="text" value="{{ $record->req_org_name ?? '-' }}" readonly>
                            </div>
                            <div class="field-group">
                                <label>วันที่อนุมัติ</label>
                                <input type="text" value="{{ $record->approval_date_th ?? '-' }}" readonly>
                            </div>
                            <div class="field-group">
                                <label>ผู้อนุมัติ</label>
                                <input type="text" value="{{ $record->approval_user_name ?? '-' }}" readonly>
                            </div>
                        </div>

                        <div class="detail-grid three-col">
                            <div class="field-group full-width">
                                <label>เหตุผล</label>
                                <input type="text" value="{{ $reasonLabel }}" readonly>
                            </div>
                        </div>
                    </section>

                    <section class="create-section asset-items-section">
                        <h4 class="create-section-title">
                            <svg class="section-title-icon" aria-hidden="true"><use href="#icon-list-check"></use></svg>
                            รายการครุภัณฑ์ที่แจ้งจำหน่าย
                        </h4>

                        <div class="asset-table-shell">
                            <table class="asset-item-table result-item-table">
                                <thead>
                                    <tr>
                                        <th>ลำดับ</th>
                                        <th>รหัสครุภัณฑ์</th>
                                        <th>ชื่อครุภัณฑ์</th>
                                        <th>ราคาต้นทุน</th>
                                        <th>ราคาขายขั้นต่ำ</th>
                                        <th>ราคาที่ขายได้จริง @unless ($isLostReason)<span class="required">*</span>@endunless</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($items as $i => $item)
                                        <tr>
                                            <td class="center">{{ $i + 1 }}</td>
                                            <td class="code-cell">
                                                {{ app(\App\Services\AssetDisplayService::class)->displayCode($item) }}
                                            </td>
                                            <td>{{ $item->asscat_name ?? '-' }}</td>
                                            <td class="center">{{ $item->ass_price !== null ? number_format((float) $item->ass_price, 2) : '-' }}</td>
                                            <td class="center">{{ $item->selling_min_price !== null ? number_format((float) $item->selling_min_price, 2) : '0.00' }}</td>
                                            <td class="center">
                                                <input
                                                    class="price-input js-real-price-input"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    name="items[{{ $item->id }}][selling_real_price]"
                                                    value="{{ $isLostReason ? '0.00' : old('items.' . $item->id . '.selling_real_price', $item->selling_real_price !== null ? number_format((float) $item->selling_real_price, 2, '.', '') : '0.00') }}"
                                                    aria-label="ราคาที่ขายได้จริงของ {{ $item->ass_code }}"
                                                    @readonly($isLostReason)
                                                >
                                                @error('items.' . $item->id . '.selling_real_price')
                                                    <span class="price-error-msg">{{ $message }}</span>
                                                @enderror
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="no-data" colspan="6">ไม่พบรายการครุภัณฑ์ที่ตรงกับคำค้นหา</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="create-section">
                        <div class="detail-grid three-col">
                            <div class="field-group full-width result-buyer-field">
                                <label for="buyer">ผู้รับซื้อ @unless ($isLostReason)<span class="required">*</span>@endunless</label>
                                <input id="buyer" name="buyer" type="text" maxlength="150" value="{{ $isLostReason ? '-' : old('buyer') }}" placeholder="{{ $isLostReason ? 'ไม่ต้องระบุผู้รับซื้อ' : 'ชื่อบุคคล / ชื่อบริษัทผู้รับซื้อ' }}" @readonly($isLostReason) @required(! $isLostReason)>
                                @error('buyer')<span class="field-error">{{ $message }}</span>@enderror
                            </div>
                        </div>
                    </section>

                    <div id="resultValidationAlert" class="disposal-inline-validation-error" hidden></div>

                    <div class="form-actions create-actions detail-actions">
                        <a class="cancel-btn disposal-back-btn" href="{{ route('asset.disposals.results.index') }}">ย้อนกลับ</a>
                        <button class="save-btn result-create-save-btn" type="button" id="saveResultButton">{{ $resultButtonText }}</button>
                    </div>
                </div>
            </form>
        </section>

        <div class="confirm-overlay" id="saveResultOverlay" aria-hidden="true">
            <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="saveResultConfirmTitle">
                <div class="confirm-icon success-confirm-icon save-confirm-icon">
                    <svg><use href="#icon-success"></use></svg>
                </div>
                <h3 id="saveResultConfirmTitle">ยืนยันการบันทึกผลการจำหน่าย</h3>
                <p>คุณแน่ใจหรือไม่ว่าต้องการบันทึกผลการจำหน่ายนี้</p>
                <div class="confirm-actions">
                    <button class="modal-cancel-btn" type="button" id="cancelSaveResultButton">ยกเลิก</button>
                    <button class="modal-confirm-btn success-confirm-btn" type="button" id="confirmSaveResultButton">ยืนยันการบันทึก</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-008-record-asset-disposal-result/script.js'])
@endsection
