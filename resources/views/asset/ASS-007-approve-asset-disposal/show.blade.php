@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/asset/ASS-006-request-asset-disposal/style.css',
        'resources/css/asset/ASS-007-approve-asset-disposal/approval.css',
    ])
@endsection

@section('content')
    @php
        $isPending = $statusInfo['type'] === 'pending';
        $approvalDateValue = $isLostReason ? ($record->created_at_input ?? '') : old('approval_date', now()->format('Y-m-d'));
    @endphp

    <div class="page-container disposal-page disposal-detail-page approval-page">
        <x-page-header :title="$pageTitle" />

        @error('_error')
            <div class="disposal-inline-validation-error">{{ $message }}</div>
        @enderror

        <section class="detail-card disposal-detail-card">
            <h3 class="create-title">พิจารณาใบแจ้งขอจำหน่าย</h3>

            <form id="approveForm" method="POST" action="{{ route('asset.disposals.approval.approve', $record->id) }}"
                data-reject-url="{{ route('asset.disposals.approval.reject', $record->id) }}" data-is-lost="{{ $isLostReason ? '1' : '0' }}">
                @csrf
                <div class="create-shell">
                    <section class="create-section">
                        <h4 class="create-section-title"><svg class="section-title-icon" aria-hidden="true"><use href="#icon-check-circle"></use></svg>พิจารณาใบแจ้งขอจำหน่าย</h4>
                        <div class="detail-grid three-col">
                            <div class="field-group"><label>เลขที่ใบขอจำหน่าย</label><input type="text" value="{{ $record->selling_code ?? '-' }}" readonly></div>
                            <div class="field-group"><label>วันที่ขอจำหน่าย</label><input type="text" value="{{ $record->req_date_th ?? '-' }}" readonly></div>
                            <div class="field-group"><label>ผลการอนุมัติ</label><div class="readonly-badge-cell"><span class="status-pill {{ $statusInfo['type'] }}">{{ $statusInfo['label'] }}</span></div></div>
                        </div>
                        <div class="detail-grid three-col">
                            <div class="field-group"><label>หน่วยงานผู้แจ้ง</label><input type="text" value="{{ $record->req_org_name ?? '-' }}" readonly></div>
                            <div class="field-group"><label>หน่วยงานผู้อนุมัติ</label><input type="text" value="{{ $record->app_org_name ?? '-' }}" readonly></div>
                            <div class="field-group"><label>เหตุผล</label><input type="text" value="{{ $reasonLabel }}" readonly></div>
                        </div>
                        <div class="detail-grid three-col">
                            <div class="field-group full-width"><label>หมายเหตุ</label><input type="text" value="{{ $record->remarks ?? '-' }}" readonly></div>
                        </div>
                        @if (! $isPending && $record->selling_approval_date)
                            <div class="detail-grid three-col">
                                <div class="field-group"><label>วันที่พิจารณา</label><input type="text" value="{{ $record->approval_date_th ?? '-' }}" readonly></div>
                                @if ($statusInfo['type'] === 'rejected' && $record->reject_reason)
                                    <div class="field-group full-width"><label>เหตุผลการไม่อนุมัติ</label><input type="text" value="{{ $record->reject_reason }}" readonly></div>
                                @endif
                            </div>
                        @endif
                    </section>

                    <section class="create-section asset-items-section">
                        <h4 class="create-section-title"><svg class="section-title-icon" aria-hidden="true"><use href="#icon-list-check"></use></svg>รายการครุภัณฑ์ที่ขอจำหน่าย</h4>

                        @unless ($isLostReason)
                            <div class="asset-search-toolbar">
                                <div class="field-group">
                                    <label for="itemSearchType">ค้นหาจาก</label>
                                    <select id="itemSearchType" name="item_search_by" form="itemSearchForm">
                                        <option value="all"  @selected($itemSearchBy === 'all')>ทั้งหมด</option>
                                        <option value="code" @selected($itemSearchBy === 'code')>รหัสครุภัณฑ์</option>
                                        <option value="name" @selected($itemSearchBy === 'name')>ชื่อครุภัณฑ์</option>
                                    </select>
                                </div>
                                <div class="field-group">
                                    <label for="itemKeyword">คำค้นหา</label>
                                    <input id="itemKeyword" type="search" name="item_keyword" value="{{ $itemKeyword }}" placeholder="กรอกคำค้นหา" form="itemSearchForm">
                                </div>
                                <button class="search-btn small" type="submit" form="itemSearchForm">ค้นหา</button>
                            </div>
                        @endunless

                        <div class="asset-table-shell">
                            <table class="asset-item-table">
                                <thead>
                                    <tr>
                                        <th>ลำดับ</th>
                                        <th>รหัสครุภัณฑ์</th>
                                        <th>ชื่อครุภัณฑ์</th>
                                        <th>มูลค่าครุภัณฑ์</th>
                                        <th>มูลค่าคงเหลือ</th>
                                        <th>ราคาขายขั้นต่ำ @if ($isPending && ! $isLostReason)<span class="required">*</span>@endif</th>
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
                                                @if ($isPending)
                                                       <input type="number" step="0.01" min="0" class="price-input"
                                                           name="items[{{ $item->id }}][selling_min_price]"
                                                           value="{{ $isLostReason ? '0.00' : old('items.' . $item->id . '.selling_min_price', $item->selling_min_price) }}"
                                                           aria-label="ราคาขายขั้นต่ำของ {{ $item->ass_code }}" @readonly($isLostReason)>
                                                    @error('items.' . $item->id . '.selling_min_price')
                                                        <span class="price-error-msg">{{ $message }}</span>
                                                    @enderror
                                                @else
                                                    {{ $item->selling_min_price !== null ? number_format((float) $item->selling_min_price, 2) : '-' }}
                                                @endif
                                            </td>
                                            <td class="center">{{ $item->selling_real_price !== null ? number_format((float) $item->selling_real_price, 2) : '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td class="no-data" colspan="7">ไม่พบรายการครุภัณฑ์ที่ตรงกับคำค้นหา</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    @if ($isPending)
                        <section class="create-section">
                            <h4 class="create-section-title"><svg class="section-title-icon" aria-hidden="true"><use href="#icon-square-pen"></use></svg>บันทึกผลการอนุมัติ</h4>
                            <div class="detail-grid three-col">
                                <div class="field-group">
                                    <label for="approvalDate">วันที่พิจารณา <span class="required">*</span></label>
                                    <input id="approvalDate" type="text" class="js-date-picker" name="approval_date"
                                         value="{{ $approvalDateValue }}"
                                         placeholder="วว-ดด-ปปปป" data-picker-position="below" @readonly($isLostReason)>
                                    @error('approval_date')<span class="field-error" style="color:#dc2626;font-size:11px;">{{ $message }}</span>@enderror
                                </div>
                                <div class="field-group full-width approval-note-field">
                                    <label for="rejectReasonInput">หมายเหตุ</label>
                                    <textarea id="rejectReasonInput" name="reject_reason" maxlength="500" placeholder="กรอกหมายเหตุเมื่อไม่อนุมัติ">{{ old('reject_reason') }}</textarea>
                                    <span class="price-error-msg" id="rejectReasonError" @if (! $errors->has('reject_reason')) hidden @endif>{{ $errors->first('reject_reason', 'กรุณากรอกหมายเหตุหากไม่อนุมัติ') }}</span>
                                </div>
                            </div>
                            <div id="approvalValidationAlert" class="disposal-inline-validation-error" hidden></div>
                        </section>
                    @endif

                    <div class="form-actions create-actions detail-actions">
                        @if ($isPending && $isLostReason)
                            <a class="cancel-btn" href="{{ route('asset.disposals.approval.index') }}">ย้อนกลับ</a>
                            <button class="reject-action-btn" type="button" id="rejectTriggerBtn">ไม่อนุมัติ</button>
                            <button class="approve-action-btn" type="button" id="approveTriggerBtn">อนุมัติ</button>
                        @elseif ($isPending)
                            <button class="approve-action-btn" type="button" id="approveTriggerBtn">อนุมัติ</button>
                            <button class="reject-action-btn" type="button" id="rejectTriggerBtn">ไม่อนุมัติ</button>
                            <a class="cancel-btn" href="{{ route('asset.disposals.approval.index') }}">ยกเลิก</a>
                        @endif
                    </div>
                </div>
            </form>

            {{-- Search reloads this page server-side; no item data is submitted --}}
            @unless ($isLostReason)
                <form id="itemSearchForm" method="GET" action="{{ route('asset.disposals.approval.consider', $record->id) }}"></form>
            @endunless
        </section>

        @if ($isPending)
            <div class="confirm-overlay" id="approvalConfirmOverlay" aria-hidden="true">
                <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="approvalConfirmTitle">
                    <div class="confirm-icon" id="approvalConfirmIcon">
                        <svg><use id="approvalConfirmIconUse" href="#icon-success"></use></svg>
                    </div>
                    <h3 id="approvalConfirmTitle"></h3>
                    <p id="approvalConfirmMessage"></p>
                    <div class="confirm-actions">
                        <button class="modal-cancel-btn" type="button" id="cancelApprovalButton">ยกเลิก</button>
                        <button class="modal-confirm-btn success-confirm-btn" type="button" id="confirmApprovalButton">ยืนยัน</button>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-007-approve-asset-disposal/approval.js'])
@endsection

