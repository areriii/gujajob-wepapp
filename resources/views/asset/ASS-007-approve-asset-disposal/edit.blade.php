@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/asset/ASS-006-request-asset-disposal/style.css',
        'resources/css/asset/ASS-007-approve-asset-disposal/approval.css',
    ])
@endsection

@section('content')
    <div class="page-container disposal-page disposal-detail-page approval-page approval-edit-page">
        <x-page-header :title="$pageTitle" />

        @error('_error')
            <div class="disposal-inline-validation-error">{{ $message }}</div>
        @enderror

        <section class="detail-card disposal-detail-card">
            <h3 class="create-title">แก้ไขผลการอนุมัติ</h3>

            <form id="approveForm" method="POST" action="{{ route('asset.disposals.approval.update', $record->id) }}" data-form-mode="edit" data-is-lost="{{ $isLostReason ? '1' : '0' }}">
                @csrf
                @method('PUT')

                <div class="create-shell">
                    <section class="create-section">
                        <h4 class="create-section-title"><svg class="section-title-icon" aria-hidden="true"><use href="#icon-check-circle"></use></svg>ข้อมูลใบแจ้งขอจำหน่าย</h4>
                        <div class="detail-grid three-col">
                            <div class="field-group"><label>เลขที่ใบแจ้งขอจำหน่าย</label><input type="text" value="{{ $record->selling_code ?? '-' }}" readonly></div>
                            <div class="field-group"><label>วันที่แจ้งขอจำหน่าย</label><input type="text" value="{{ $record->req_date_th ?? '-' }}" readonly></div>
                            <div class="field-group"><label>สถานะปัจจุบัน</label><div class="readonly-badge-cell"><span class="status-pill {{ $statusInfo['type'] }}">{{ $statusInfo['label'] }}</span></div></div>
                        </div>
                        <div class="detail-grid three-col">
                            <div class="field-group"><label>หน่วยงานผู้แจ้ง</label><input type="text" value="{{ $record->req_org_name ?? '-' }}" readonly></div>
                            <div class="field-group"><label>หน่วยงานผู้อนุมัติ</label><input type="text" value="{{ $record->app_org_name ?? '-' }}" readonly></div>
                            <div class="field-group"><label>เหตุผล</label><input type="text" value="{{ $reasonLabel }}" readonly></div>
                        </div>
                        <div class="detail-grid three-col">
                            <div class="field-group full-width"><label>หมายเหตุ</label><input type="text" value="{{ $record->remarks ?? '-' }}" readonly></div>
                        </div>
                    </section>

                    <section class="create-section asset-items-section">
                        <h4 class="create-section-title"><svg class="section-title-icon" aria-hidden="true"><use href="#icon-list-check"></use></svg>รายการครุภัณฑ์ที่ขอจำหน่าย</h4>
                        <div class="asset-table-shell">
                            <table class="asset-item-table">
                                <thead>
                                    <tr>
                                        <th>ลำดับ</th>
                                        <th>รหัสครุภัณฑ์</th>
                                        <th>ชื่อครุภัณฑ์</th>
                                        <th>มูลค่าครุภัณฑ์</th>
                                        <th>มูลค่าคงเหลือ</th>
                                        <th>ราคาขาย @unless ($isLostReason)<span class="required">*</span>@endunless</th>
                                        <th>ราคาขายจริง</th>
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
                                            <td class="center">{{ $item->remain_price !== null ? number_format((float) $item->remain_price, 2) : '-' }}</td>
                                            <td class="center">
                                                <input type="number" step="0.01" min="0" class="price-input"
                                                       name="items[{{ $item->id }}][selling_min_price]"
                                                          value="{{ $isLostReason ? '0.00' : old('items.' . $item->id . '.selling_min_price', $item->selling_min_price !== null ? number_format((float) $item->selling_min_price, 2, '.', '') : '0.00') }}"
                                                          aria-label="ราคาขายของ {{ $item->ass_code }}" @readonly($isLostReason)>
                                                @error('items.' . $item->id . '.selling_min_price')
                                                    <span class="price-error-msg">{{ $message }}</span>
                                                @enderror
                                            </td>
                                            <td class="center">{{ $item->selling_real_price !== null ? number_format((float) $item->selling_real_price, 2) : '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td class="no-data" colspan="7">ไม่มีรายการครุภัณฑ์</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="create-section">
                        <h4 class="create-section-title"><svg class="section-title-icon" aria-hidden="true"><use href="#icon-square-pen"></use></svg>แก้ไขผลการอนุมัติ</h4>
                        <div class="detail-grid three-col">
                            <div class="field-group">
                                <label for="approvalDecision">ผลการอนุมัติ <span class="required">*</span></label>
                                @php($currentDecision = old('decision', (string) ((int) ($record->selling_approval_status ?? 0) ?: 1)))
                                <select id="approvalDecision" name="decision">
                                    <option value="1" @selected($currentDecision === '1')>อนุมัติ</option>
                                    <option value="2" @selected($currentDecision === '2')>ไม่อนุมัติ</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label for="approvalDate">วันที่พิจารณา <span class="required">*</span></label>
                                <input id="approvalDate" type="text" class="js-date-picker" name="approval_date"
                                        value="{{ $isLostReason ? ($record->created_at_input ?? '') : old('approval_date', $record->approval_date_input ?? now()->format('Y-m-d')) }}"
                                        placeholder="วว-ดด-ปปปป" data-picker-position="below" @readonly($isLostReason)>
                                @error('approval_date')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                            </div>
                            <div class="field-group full-width approval-note-field">
                                <label for="rejectReasonInput">หมายเหตุ</label>
                                <textarea id="rejectReasonInput" name="reject_reason" maxlength="500" placeholder="กรอกหมายเหตุเมื่อไม่อนุมัติ">{{ old('reject_reason', $record->reject_reason) }}</textarea>
                                <span class="price-error-msg" id="rejectReasonError" @if (! $errors->has('reject_reason')) hidden @endif>{{ $errors->first('reject_reason', 'กรุณากรอกหมายเหตุหากไม่อนุมัติ') }}</span>
                            </div>
                        </div>
                        <div id="approvalValidationAlert" class="disposal-inline-validation-error" hidden></div>
                    </section>

                    <div class="form-actions create-actions detail-actions">
                        <a class="cancel-btn" href="{{ route('asset.disposals.approval.index') }}">ย้อนกลับ</a>
                        <button class="save-btn approval-edit-save-btn" type="button" id="saveApprovalEditBtn">บันทึก</button>
                    </div>
                </div>
            </form>
        </section>

        <div class="confirm-overlay" id="approvalConfirmOverlay" aria-hidden="true">
            <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="approvalConfirmTitle">
                <div class="confirm-icon success-confirm-icon save-confirm-icon" id="approvalConfirmIcon">
                    <svg><use id="approvalConfirmIconUse" href="#icon-success"></use></svg>
                </div>
                <h3 id="approvalConfirmTitle">ยืนยันการบันทึก</h3>
                <p id="approvalConfirmMessage">คุณแน่ใจหรือไม่ว่าต้องการบันทึกผลการอนุมัตินี้</p>
                <div class="confirm-actions">
                    <button class="modal-cancel-btn" type="button" id="cancelApprovalButton">ยกเลิก</button>
                    <button class="modal-confirm-btn success-confirm-btn" type="button" id="confirmApprovalButton">ยืนยัน</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-007-approve-asset-disposal/approval.js'])
@endsection
