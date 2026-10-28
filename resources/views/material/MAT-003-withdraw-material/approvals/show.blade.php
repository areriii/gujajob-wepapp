@extends('layouts.app')

@section('title', 'อนุมัติรายการเบิก')

@section('page-style')
    @vite([
        'resources/css/components/table-actions.css',
        'resources/css/material/MAT-003-withdraw-material/style.css',
        'resources/css/material/MAT-003-withdraw-material/approval-style.css',
    ])
@endsection

@section('content')
    @php
        $isPending  = (int) $record->status === \App\Models\MaterialWithdrawn::STATUS_PENDING;
        $isApproved = (int) $record->status === \App\Models\MaterialWithdrawn::STATUS_APPROVED;
    @endphp

    <div class="page-container withdraw-create-page">
        <x-page-header :title="$pageTitle" />

        @if (session('error'))
            <div class="form-error-box">{{ session('error') }}</div>
        @endif

        {{-- ====================================================== --}}
        {{-- Approve form (wraps the whole card)                    --}}
        {{-- ====================================================== --}}
        <form
            id="approveForm"
            method="POST"
            action="{{ route('material.withdraw.approval.approve', $record->mat_wd_code) }}"
            data-validate-stock-url="{{ route('material.withdraw.approval.validate-stock', $record->mat_wd_code) }}"
        >
            @csrf

            <section class="withdraw-form-card">

                {{-- Card header --}}
                <div class="wd-show-card-header">
                    <h3 class="wd-show-card-title">รายการคำขอการเบิกวัสดุ</h3>
                    <span class="status-badge {{ $record->status_css }}">{{ $record->status_label }}</span>
                </div>

                {{-- Read-only status notice for already-processed records --}}
                @if (! $isPending)
                    <div class="approval-readonly-notice">
                        ใบเบิกนี้ได้รับการดำเนินการแล้ว ({{ $record->status_label }}) — แสดงข้อมูลแบบอ่านอย่างเดียว
                    </div>
                @endif

                {{-- Header fields --}}
                <div class="withdraw-document-grid">
                    <div class="form-field">
                        <label>เลขที่ใบเบิก</label>
                        <input type="text" value="{{ $record->mat_wd_code }}" readonly>
                    </div>

                    <div class="form-field">
                        <label>วันที่เบิก</label>
                        <input type="text" value="{{ thai_date($record->mat_wd_date) ?? '-' }}" readonly>
                    </div>

                    <div class="form-field">
                        <label>หน่วยงานที่ขอเบิก</label>
                        <input type="text" value="{{ $record->organization?->org_name ?? '-' }}" readonly>
                    </div>

                    <div class="form-field">
                        <label>ชื่อผู้เบิกวัสดุ</label>
                        <input type="text" value="{{ $record->mat_wd_person ?? '-' }}" readonly>
                    </div>

                    <div class="form-field">
                        <label>รูปแบบการเบิก</label>
                        <input type="text" value="{{ $record->withdraw_type_label }}" readonly>
                    </div>

                    <div class="form-field">
                        <label>หน่วยงาน (ผู้อนุมัติ)</label>
                        <input type="text" value="{{ $record->approver_org_name }}" readonly>
                    </div>
                </div>

                {{-- Items section --}}
                <div class="withdraw-items-title">
                    <svg class="section-icon"><use href="#icon-panel"></use></svg>
                    <span>รายการวัสดุที่ขอเบิก</span>
                </div>

                <div class="table-wrapper compact-table-wrapper">
                    <table class="approval-table">
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวนที่เบิก</th>
                                <th>หน่วยนับ</th>
                                <th>จำนวนคงเหลือ</th>
                                <th>คงเหลือหลังอนุมัติ</th>
                            </tr>
                        </thead>
                        <tbody id="approvalItemsBody">
                            @forelse ($record->details as $index => $item)
                                @php
                                    $matId        = (int) $item->mat_id;
                                    $currentBal   = $inventoryMap[$matId] ?? null;
                                    $wdAmount     = (int) $item->wd_amount;
                                    $balAttr      = $currentBal !== null ? (string) $currentBal : '';
                                @endphp
                                <tr
                                    data-detail-id="{{ $item->id }}"
                                    data-mat-id="{{ $matId }}"
                                    data-current-balance="{{ $balAttr }}"
                                >
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <span class="approval-mat-code">{{ $item->material?->mat_code ?? '-' }}</span>
                                        <span class="approval-mat-name">{{ $item->material?->mat_name ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <div class="approval-qty-cell">
                                            <input
                                                class="approval-qty-input"
                                                type="number"
                                                value="{{ old('details.' . $item->id . '.approved_quantity', $wdAmount) }}"
                                                min="0"
                                                @if ($currentBal !== null)
                                                    max="{{ $currentBal }}"
                                                @endif
                                                step="1"
                                                disabled
                                                aria-label="จำนวนที่อนุมัติ"
                                            >
                                            <input
                                                type="hidden"
                                                class="approval-qty-hidden"
                                                name="details[{{ $item->id }}][approved_quantity]"
                                                value="{{ old('details.' . $item->id . '.approved_quantity', $wdAmount) }}"
                                            >
                                            @if ($isPending)
                                                <button type="button"
                                                    class="qty-action-btn table-action-icon table-action-edit"
                                                    data-mode="edit"
                                                    aria-label="แก้ไขจำนวน"
                                                    data-tooltip="แก้ไขจำนวน">
                                                    <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                                                </button>
                                            @endif
                                        </div>
                                        <span class="qty-error-msg" data-quantity-error hidden></span>
                                    </td>
                                    <td>{{ $item->material?->unit ?? '-' }}</td>
                                    <td>
                                        @if ($currentBal !== null)
                                            {{ number_format($currentBal) }}
                                        @else
                                            <span style="color:#94a3b8">-</span>
                                        @endif
                                    </td>
                                    <td class="remaining-after-cell">
                                        @if ($currentBal !== null)
                                            {{ number_format($currentBal - $wdAmount) }}
                                        @else
                                            –
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="no-data" colspan="6">ไม่มีรายการวัสดุ</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Validation alert --}}
                <div id="approvalValidationAlert" class="approval-validation-alert" hidden></div>

                {{-- Action buttons --}}
                <div class="approval-actions">
                    @if ($isPending)
                        <button type="button" id="approveBtnMain" class="approve-btn">อนุมัติ</button>
                        <button type="button" id="rejectBtnMain" class="reject-btn">ไม่อนุมัติ</button>
                    @else
                        <button type="button" class="approve-btn" disabled>อนุมัติ</button>
                        <button type="button" class="reject-btn" disabled>ไม่อนุมัติ</button>
                    @endif

                    <a class="cancel-approval-link" href="{{ route('material.withdraw.approval.index') }}">ย้อนกลับ</a>
                </div>

            </section>
        </form>

        {{-- Hidden reject form (no inputs needed beyond CSRF) --}}
        <form
            id="rejectForm"
            method="POST"
            action="{{ route('material.withdraw.approval.reject', $record->mat_wd_code) }}"
            style="display:none"
        >
            @csrf
        </form>
    </div>

    {{-- ====================================================== --}}
    {{-- Approve Confirmation Popup                             --}}
    {{-- ====================================================== --}}
    <div
        id="approveOverlay"
        class="confirm-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="approveModalTitle"
        aria-hidden="true"
    >
        <div class="confirm-modal">
            <div class="confirm-icon approve-confirm-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
            </div>

            <h3 id="approveModalTitle">ยืนยันการอนุมัติคำขอ</h3>

            <p>
                คุณแน่ใจหรือไม่ว่าต้องการอนุมัติใบเบิกวัสดุ<br>
                <strong>{{ $record->mat_wd_code }}</strong>
            </p>

            <div class="confirm-actions">
                <button type="button" id="approveCancelBtn" class="modal-cancel-btn">ยกเลิก</button>
                <button type="button" id="approveConfirmBtn" class="modal-confirm-btn approve-confirm-btn">ยืนยันการอนุมัติ</button>
            </div>
        </div>
    </div>

    {{-- ====================================================== --}}
    {{-- Reject Confirmation Popup                              --}}
    {{-- ====================================================== --}}
    <div
        id="rejectOverlay"
        class="confirm-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rejectModalTitle"
        aria-hidden="true"
    >
        <div class="confirm-modal">
            <div class="confirm-icon reject-confirm-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                    <path d="M12 9v4"/>
                    <path d="M12 17h.01"/>
                </svg>
            </div>

            <h3 id="rejectModalTitle">ยืนยันการไม่อนุมัติคำขอนี้</h3>

            <p>
                คุณแน่ใจหรือไม่ว่าต้องการไม่อนุมัติใบเบิกวัสดุ<br>
                <strong>{{ $record->mat_wd_code }}</strong>
            </p>

            <div class="confirm-actions">
                <button type="button" id="rejectCancelBtn" class="modal-cancel-btn">ยกเลิก</button>
                <button type="button" id="rejectConfirmBtn" class="modal-confirm-btn reject-confirm-btn">ยืนยันไม่อนุมัติ</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/material/MAT-003-withdraw-material/approval-script.js'])
@endsection
