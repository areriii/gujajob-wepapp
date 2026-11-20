@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/table-actions.css',
        'resources/css/components/pagination.css',
        'resources/css/components/sort-icon.css',
        'resources/css/asset/ASS-006-request-asset-disposal/style.css',
    ])
@endsection

@section('content')
    <div class="page-container disposal-page">
        <x-page-header :title="$pageTitle" />

        <section class="disposal-card">
            <div class="card-title">
                <svg class="card-title-icon" aria-hidden="true"><use href="#icon-list-check"></use></svg>
                <span>ผลการขอจำหน่ายครุภัณฑ์</span>
            </div>

            <form method="GET" action="{{ route('asset.disposals.index') }}" id="disposalSearchForm">
                <div class="toolbar">
                    <div class="field-group search-type-field">
                        <label for="searchType">ค้นหาจาก</label>
                        <select id="searchType" name="search_by">
                            <option value="all"        @selected(($searchBy ?? 'all') === 'all')>ทั้งหมด</option>
                            <option value="request_no" @selected(($searchBy ?? 'all') === 'request_no')>เลขที่ใบขอจำหน่ายครุภัณฑ์</option>
                            <option value="org_name"   @selected(($searchBy ?? 'all') === 'org_name')>หน่วยงานผู้แจ้งขออนุมัติ</option>
                        </select>
                    </div>

                    <div class="field-group search-input-field">
                        <label for="searchInput">คำค้นหา</label>
                        <input
                            id="searchInput"
                            name="keyword"
                            type="search"
                            value="{{ $keyword }}"
                            placeholder="กรอกคำค้นหา"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                        >
                    </div>

                    <div class="field-group status-filter-field">
                        <label for="statusFilter">สถานะ</label>
                        <select id="statusFilter" name="status">
                            <option value=""         @selected(($status ?? '') === '')>ทั้งหมด</option>
                            <option value="pending"  @selected(($status ?? '') === 'pending')>รอการอนุมัติ</option>
                            <option value="approved" @selected(($status ?? '') === 'approved')>อนุมัติ</option>
                            <option value="rejected" @selected(($status ?? '') === 'rejected')>ไม่อนุมัติ</option>
                        </select>
                    </div>

                    @if ($sort)
                        <input type="hidden" name="sort" value="{{ $sort }}">
                        <input type="hidden" name="direction" value="{{ $direction }}">
                    @endif

                    <button class="search-btn" type="submit">ค้นหา</button>

                    <div class="toolbar-spacer"></div>

                    <a class="create-btn" href="{{ route('asset.disposals.create') }}">บันทึกการแจ้งขอจำหน่ายใหม่</a>
                </div>
            </form>

            <div class="table-wrapper">
                <table class="disposal-table">
                    <colgroup>
                        <col class="col-request-no">
                        <col class="col-request-date">
                        <col class="col-request-org">
                        <col class="col-reason">
                        <col class="col-status">
                        <col class="col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <x-sortable-th label="เลขที่ใบขอจำหน่ายครุภัณฑ์"    key="request_no"   :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$status]" />
                            <x-sortable-th label="วันที่แจ้งจำหน่าย"             key="request_date" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$status]" />
                            <x-sortable-th label="หน่วยงานผู้แจ้งขออนุมัติ"     key="org_name"     :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$status]" />
                            <th>เหตุผล</th>
                            <x-sortable-th label="ผลการอนุมัติ" key="status" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$status]" />
                            <th class="action-column">จัดการ</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($disposals as $disposal)
                            <tr>
                                <td>
                                    <a class="request-no-link" href="{{ route('asset.disposals.show', $disposal->id) }}">
                                        <span class="request-no">{{ $disposal->selling_code ?? '-' }}</span>
                                    </a>
                                </td>
                                <td class="date-text">{{ $disposal->req_date_th ?? '-' }}</td>
                                <td>{{ $disposal->req_org_name ?? '-' }}</td>
                                <td class="reason-text">{{ \App\Http\Controllers\AssetDisposalController::getReasonLabel($disposal->reason ?? '') }}</td>
                                <td class="status-cell">
                                    <span class="status-pill {{ $disposal->status_type }}">{{ $disposal->status_label }}</span>
                                </td>
                                <td class="action-column">
                                    <div class="table-action-buttons">
                                        @if ($disposal->status_type === 'approved')
                                            <span class="table-action-icon table-action-edit is-disabled"
                                                  aria-label="แก้ไขไม่ได้หลังอนุมัติ" aria-disabled="true"
                                                  data-tooltip="แก้ไขไม่ได้หลังอนุมัติ">
                                                <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                                            </span>
                                            <button class="table-action-icon table-action-delete is-disabled"
                                                    type="button" disabled aria-disabled="true"
                                                    aria-label="ลบไม่ได้หลังอนุมัติ" data-tooltip="ลบไม่ได้หลังอนุมัติ">
                                                <svg aria-hidden="true"><use href="#icon-trash"></use></svg>
                                            </button>
                                        @else
                                            @php
                                                $editHint = $disposal->status_type === 'rejected'
                                                    ? 'แก้ไขแล้วส่งพิจารณาใหม่'
                                                    : 'แก้ไข';
                                            @endphp
                                            <a class="table-action-icon table-action-edit"
                                               aria-label="{{ $editHint }}" data-tooltip="{{ $editHint }}"
                                               href="{{ route('asset.disposals.edit', $disposal->selling_code) }}">
                                                <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                                            </a>
                                            <button class="table-action-icon table-action-delete js-disposal-delete-btn"
                                                    type="button"
                                                    aria-label="ลบ" data-tooltip="ลบ"
                                                    data-disposal-id="{{ $disposal->id }}"
                                                    data-selling-code="{{ $disposal->selling_code ?? '-' }}"
                                                    data-delete-url="{{ route('asset.disposals.destroy', $disposal->id) }}">
                                                <svg aria-hidden="true"><use href="#icon-trash"></use></svg>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="no-data" colspan="6">ไม่พบข้อมูลการแจ้งขอจำหน่ายครุภัณฑ์</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <p class="result-summary">
                    แสดง {{ $disposals->firstItem() ?? 0 }}–{{ $disposals->lastItem() ?? 0 }}
                    จาก {{ $disposals->total() }} รายการ
                </p>
                <x-app-pagination :paginator="$disposals" />
            </div>
        </section>

        {{-- Delete confirmation modal --}}
        <div class="confirm-overlay" id="disposalDeleteOverlay" aria-hidden="true">
            <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="disposalDeleteConfirmTitle">
                <div class="confirm-icon delete-confirm-icon">
                    <svg><use href="#icon-alert-triangle"></use></svg>
                </div>
                <h3 id="disposalDeleteConfirmTitle">ยืนยันการลบข้อมูล</h3>
                <p>คุณแน่ใจหรือไม่ว่าต้องการลบเลขที่ใบขอจำหน่าย <strong id="disposalDeleteCode"></strong><br>การดำเนินการนี้ไม่สามารถเรียกคืนได้</p>
                <div class="confirm-actions">
                    <button class="modal-cancel-btn" id="cancelDisposalDeleteBtn" type="button">ยกเลิก</button>
                    <button class="modal-confirm-btn delete-confirm-btn" id="confirmDisposalDeleteBtn" type="button">ยืนยันการลบ</button>
                </div>
            </div>
        </div>
        <form id="disposalDeleteForm" method="POST" style="display:none;">
            @csrf
            @method('DELETE')
        </form>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-006-request-asset-disposal/script.js'])
@endsection