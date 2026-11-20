@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/pagination.css',
        'resources/css/components/sort-icon.css',
        'resources/css/asset/ASS-006-request-asset-disposal/style.css',
        'resources/css/asset/ASS-008-record-asset-disposal-result/style.css',
    ])
@endsection

@section('content')
    <div class="page-container disposal-page result-page">
        <x-page-header :title="$pageTitle" />

        @if (session('success'))
            <div class="form-success-box" style="margin-bottom:12px;padding:10px 14px;background:#d1fae5;border:1px solid #34d399;border-radius:6px;font-size:13px;color:#065f46;">
                {{ session('success') }}
            </div>
        @endif
        @error('_error')
            <div class="disposal-inline-validation-error">{{ $message }}</div>
        @enderror

        <section class="disposal-card">
            <div class="card-title">
                <svg class="card-title-icon" aria-hidden="true"><use href="#icon-list-check"></use></svg>
                <span>รายการบันทึกผลการจำหน่ายครุภัณฑ์ทั้งหมด</span>
            </div>

            <form method="GET" action="{{ route('asset.disposals.results.index') }}" id="resultSearchForm">
                <div class="toolbar result-toolbar">
                    <div class="field-group search-type-field">
                        <label for="searchType">ค้นหาจาก</label>
                        <select id="searchType" name="search_by">
                            <option value="all" @selected(($searchBy ?? 'all') === 'all')>ทั้งหมด</option>
                            <option value="request_no" @selected(($searchBy ?? 'all') === 'request_no')>เลขที่ใบขอจำหน่าย</option>
                            <option value="org_name" @selected(($searchBy ?? 'all') === 'org_name')>หน่วยงานผู้แจ้ง</option>
                        </select>
                    </div>

                    <div class="field-group search-input-field">
                        <label for="searchInput">คำค้นหา</label>
                        <input id="searchInput" name="keyword" type="search" value="{{ $keyword }}" placeholder="กรอกคำค้นหา">
                    </div>

                    <div class="field-group status-filter-field">
                        <label for="statusFilter">สถานะ</label>
                        <select id="statusFilter" name="status">
                            <option value="" @selected(($statusFilter ?? '') === '')>ทั้งหมด</option>
                            <option value="approved" @selected(($statusFilter ?? '') === 'approved')>อนุมัติแล้ว</option>
                            <option value="4" @selected(($statusFilter ?? '') === '4')>จำหน่ายแล้ว</option>
                            <option value="6" @selected(($statusFilter ?? '') === '6')>สูญหาย</option>
                        </select>
                    </div>

                    <div class="field-group buyer-filter-field">
                        <label for="buyerInput">ผู้รับซื้อ</label>
                        <input id="buyerInput" name="buyer" type="search" value="{{ $buyerKeyword }}" placeholder="กรอกชื่อผู้รับซื้อ">
                    </div>

                    @if ($sort)
                        <input type="hidden" name="sort" value="{{ $sort }}">
                        <input type="hidden" name="direction" value="{{ $direction }}">
                    @endif

                    <button class="search-btn" type="submit">ค้นหา</button>
                </div>
            </form>

            <div class="table-wrapper">
                <table class="disposal-table result-table">
                    <colgroup>
                        <col class="col-request-no">
                        <col class="col-request-date">
                        <col class="col-request-org">
                        <col class="col-approval-date">
                        <col class="col-status">
                        <col class="col-buyer">
                        <col class="col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <x-sortable-th label="เลขที่ใบขอจำหน่าย" key="request_no" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$statusFilter,'buyer'=>$buyerKeyword]" />
                            <x-sortable-th label="วันที่แจ้งจำหน่าย" key="request_date" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$statusFilter,'buyer'=>$buyerKeyword]" />
                            <x-sortable-th label="หน่วยงานผู้แจ้ง" key="org_name" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$statusFilter,'buyer'=>$buyerKeyword]" />
                            <x-sortable-th label="วันที่อนุมัติ" key="approval_date" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$statusFilter,'buyer'=>$buyerKeyword]" />
                            <x-sortable-th label="สถานะ" key="status" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$statusFilter,'buyer'=>$buyerKeyword]" />
                            <x-sortable-th label="ผู้รับซื้อ" key="buyer" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status'=>$statusFilter,'buyer'=>$buyerKeyword]" />
                            <th class="action-column">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($records as $record)
                            <tr>
                                <td>
                                    <a class="request-no-link" href="{{ route('asset.disposals.results.show', $record->id) }}">
                                        <span class="request-no">{{ $record->selling_code ?? '-' }}</span>
                                    </a>
                                </td>
                                <td class="date-text">{{ $record->req_date_th ?? '-' }}</td>
                                <td>{{ $record->req_org_name ?? '-' }}</td>
                                <td class="date-text">{{ $record->approval_date_th ?? '-' }}</td>
                                <td class="status-cell">
                                    <span class="status-pill {{ $record->result_status_type }}">{{ $record->result_status_label }}</span>
                                </td>
                                <td>{{ $record->buyer ?: '-' }}</td>
                                <td class="action-column">
                                    {{-- ใบที่บันทึกผลไปแล้วก็ยังต้องแก้ได้ เช่น กรอกราคาผิด หรือชื่อผู้รับซื้อผิด --}}
                                    <a class="create-btn result-save-btn" href="{{ route('asset.disposals.results.create', $record->id) }}">
                                        {{ $record->can_record_result ? 'บันทึกผล' : 'แก้ไขผล' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="no-data" colspan="7">ไม่พบข้อมูลบันทึกผลการจำหน่ายครุภัณฑ์</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <p class="result-summary">แสดง {{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} จากทั้งหมด {{ $records->total() }} รายการ</p>
                <x-app-pagination :paginator="$records" />
            </div>
        </section>
    </div>
@endsection
