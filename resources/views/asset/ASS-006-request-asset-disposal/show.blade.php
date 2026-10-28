@extends('layouts.app')

@section('page-style')
    @vite(['resources/css/asset/ASS-006-request-asset-disposal/style.css'])
@endsection

@section('content')
    <div class="page-container disposal-page disposal-detail-page">
        <x-page-header :title="$pageTitle" />

        <section class="detail-card disposal-detail-card">
            <h3 class="create-title">รายละเอียดการแจ้งขอจำหน่ายครุภัณฑ์</h3>

            <div class="create-shell">
                <section class="create-section">
                    <h4 class="create-section-title">
                        <svg class="section-title-icon" aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                        ใบขอจำหน่ายครุภัณฑ์
                    </h4>

                    <div class="detail-grid three-col">
                        <div class="field-group">
                            <label>เลขที่ใบแจ้งขอจำหน่ายครุภัณฑ์</label>
                            <input type="text" value="{{ $disposal->selling_code ?? '-' }}" readonly>
                        </div>

                        <div class="field-group">
                            <label>วันที่แจ้งขอจำหน่าย</label>
                            <input type="text" value="{{ $disposal->req_date_th ?? '-' }}" readonly>
                        </div>

                        <div class="field-group">
                            <label>ผลการอนุมัติ</label>
                            <div class="readonly-badge-cell">
                                <span class="status-pill {{ $statusInfo['type'] }}">{{ $statusInfo['label'] }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="detail-grid three-col">
                        <div class="field-group">
                            <label>หน่วยงานผู้แจ้งขออนุมัติ</label>
                            <input type="text" value="{{ $disposal->req_org_name ?? '-' }}" readonly>
                        </div>

                        <div class="field-group">
                            <label>เหตุผล</label>
                            <input type="text" value="{{ $reasonLabel }}" readonly>
                        </div>

                        @if ($disposal->buyer)
                            <div class="field-group">
                                <label>ผู้รับซื้อ</label>
                                <input type="text" value="{{ $disposal->buyer }}" readonly>
                            </div>
                        @endif
                    </div>

                    @if ($disposal->selling_approval_status == 2 && $disposal->reject_reason)
                        <div class="detail-grid">
                            <div class="field-group">
                                <label>เหตุผลการไม่อนุมัติ</label>
                                <input type="text" value="{{ $disposal->reject_reason }}" readonly>
                            </div>
                        </div>
                    @endif
                </section>

                <section class="create-section asset-items-section">
                    <h4 class="create-section-title">
                        <svg class="section-title-icon" aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                        รายการครุภัณฑ์
                    </h4>

                    <div class="asset-table-shell">
                        <table class="asset-item-table">
                            <thead>
                                <tr>
                                    <th>ลำดับ</th>
                                    <th>รหัสทะเบียนครุภัณฑ์</th>
                                    <th>ชื่อครุภัณฑ์</th>
                                    <th>มูลค่าครุภัณฑ์</th>
                                    <th>มูลค่าคงเหลือ</th>
                                    <th>ราคาจำหน่ายขั้นต่ำ</th>
                                    <th>ราคาที่ขายได้จริง</th>
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
                                        <td class="center">
                                            {{ $item->ass_price !== null ? number_format((float) $item->ass_price, 2) : '-' }}
                                        </td>
                                        <td class="center">
                                            {{ $item->remain_price !== null ? number_format((float) $item->remain_price, 2) : '-' }}
                                        </td>
                                        <td class="center">
                                            {{ $item->selling_min_price !== null ? number_format((float) $item->selling_min_price, 2) : '-' }}
                                        </td>
                                        <td class="center">
                                            {{ $item->selling_real_price !== null ? number_format((float) $item->selling_real_price, 2) : '-' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="no-data" colspan="7">ไม่มีรายการครุภัณฑ์</td>
                                    </tr>
                                @endforelse
                                <tr class="summary-row">
                                    <td colspan="7">รวมจำนวนรายการทั้งสิ้น <strong>{{ $items->count() }}</strong> รายการ</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="form-actions create-actions detail-actions">
                    <a class="cancel-btn" href="{{ route('asset.disposals.index') }}">ย้อนกลับ</a>
                </div>
            </div>
        </section>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-006-request-asset-disposal/script.js'])
@endsection