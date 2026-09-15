@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/table-actions.css',
        'resources/css/components/search-autocomplete.css',
        'resources/css/components/pagination.css',
        'resources/css/components/sort-icon.css',
        'resources/css/asset/ASS-003-manage-asset-registration/style.css',
    ])
@endsection

@section('content')
    <div class="page-container asset-registration-page">
        <x-page-header :title="$pageTitle" />

        @if (session('asset_success'))
            <div class="form-success-box" style="margin-bottom:12px;padding:10px 14px;background:#d1fae5;border:1px solid #34d399;border-radius:6px;font-size:13px;color:#065f46;">
                {{ session('asset_success') }}
            </div>
        @endif
        @if (session('asset_error'))
            <div class="form-error-box" style="margin-bottom:12px;">{{ session('asset_error') }}</div>
        @endif

        <section class="registration-card">
            <div class="card-title">
                <svg class="card-title-icon"><use href="#icon-material-list"></use></svg>
                <span>รายการทะเบียนครุภัณฑ์</span>
            </div>

            <form method="GET" action="{{ route('asset.registrations.index') }}" id="assetSearchForm">
                <div class="toolbar">
                    <div class="field-group search-type-field">
                        <label for="assetSearchType">ค้นหาจาก</label>
                        <select id="assetSearchType" name="search_by">
                            <option value="all" @selected(($searchBy ?? 'all') === 'all')>ทั้งหมด</option>
                            <option value="code" @selected(($searchBy ?? 'all') === 'code')>รหัสทะเบียนครุภัณฑ์</option>
                            <option value="name" @selected(($searchBy ?? 'all') === 'name')>ชื่อครุภัณฑ์</option>
                            <option value="org"  @selected(($searchBy ?? 'all') === 'org')>หน่วยงาน</option>
                        </select>
                    </div>

                    <div class="field-group search-input-field">
                        <label for="assetSearchInput">คำค้นหา</label>
                        <input
                            id="assetSearchInput"
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
                        <label for="assetStatusFilter">สถานะ</label>
                        <select id="assetStatusFilter" name="status_filter">
                            <option value=""  @selected(($statusFilter ?? '') === '')>ทั้งหมด</option>
                            <option value="1" @selected(($statusFilter ?? '') === '1')>รอจัดสรร</option>
                            <option value="2" @selected(($statusFilter ?? '') === '2')>ปกติ</option>
                            <option value="3" @selected(($statusFilter ?? '') === '3')>พร้อมจำหน่าย</option>
                            <option value="4" @selected(($statusFilter ?? '') === '4')>จำหน่ายแล้ว</option>
                            <option value="5" @selected(($statusFilter ?? '') === '5')>ชำรุด</option>
                            <option value="6" @selected(($statusFilter ?? '') === '6')>สูญหาย</option>
                        </select>
                    </div>

                    @if ($sort)
                        <input type="hidden" name="sort" value="{{ $sort }}">
                        <input type="hidden" name="direction" value="{{ $direction }}">
                    @endif

                    <button class="search-btn" type="submit">ค้นหา</button>

                    <div class="toolbar-spacer"></div>

                    <a class="forecast-open-btn" href="{{ route('asset.registrations.replacement-forecast') }}">
                        พยากรณ์งบประมาณทดแทน
                    </a>

                    <a class="create-btn link-button" href="{{ route('asset.registrations.create') }}">
                        บันทึกทะเบียนใหม่
                    </a>
                </div>
            </form>

            <div class="table-wrapper">
                <table class="registration-table">
                    <thead>
                        <tr>
                            <x-sortable-th label="รหัสทะเบียนครุภัณฑ์" key="code"         :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status_filter'=>$statusFilter]" />
                            <x-sortable-th label="ชื่อครุภัณฑ์"    key="name"         :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status_filter'=>$statusFilter]" />
                            <th>หน่วยงาน</th>
                            <x-sortable-th label="วันที่ตรวจรับ"   key="inspect_date" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status_filter'=>$statusFilter]" />
                            <x-sortable-th label="มูลค่า"           key="price"        :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status_filter'=>$statusFilter]" />
                            <x-sortable-th label="มูลค่าคงเหลือ"    key="remain"       :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status_filter'=>$statusFilter]" style="text-align:right;" />
                            <x-sortable-th label="สถานะ"            key="status"       :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'status_filter'=>$statusFilter]" />
                            <th class="action-column">จัดการ</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($assets as $asset)
                            @php
                                $statusInfo = \App\Http\Controllers\AssetController::assetStatusLabel($asset->ass_status ?? '');
                            @endphp
                            <tr class="{{ in_array($asset->ass_status, ['3','4','5','6']) ? 'dispose-row' : '' }}">
                                <td>
                                    <a class="ass-code-link" href="{{ route('asset.registrations.show', $asset->id) }}">
                                        <span class="asset-code">
                                            {{ app(\App\Services\AssetDisplayService::class)->displayCode($asset) }}
                                        </span>
                                    </a>
                                </td>
                                <td>
                                    <div class="asset-name">{{ $asset->asscat_name ?? '-' }}</div>
                                    <div class="asset-detail">{{ $asset->asscat_type ?? '' }}</div>
                                </td>
                                <td>{{ $asset->org_name ?? '-' }}</td>
                                <td>{{ $asset->inspect_date_th ?? '-' }}</td>
                                <td class="number-cell" style="text-align:right;">
                                    {{ $asset->ass_price !== null ? number_format((float)$asset->ass_price, 2) : '-' }}
                                </td>
                                <td class="number-cell" style="text-align:right;">
                                    @if ($asset->remain_price !== null)
                                        @php $rp = (float) $asset->remain_price; @endphp
                                        <span class="{{ $rp <= 1 ? 'danger-value' : 'balance-value' }}">
                                            {{ number_format($rp, 2) }}
                                        </span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="status-cell">
                                    @if ($statusInfo['class'])
                                        <span class="status-badge {{ $statusInfo['class'] }}">{{ $statusInfo['label'] }}</span>
                                    @else
                                        {{ $statusInfo['label'] }}
                                    @endif
                                </td>
                                <td class="action-column">
                                    <div class="table-action-buttons">
                                        <a class="table-action-icon table-action-edit"
                                           aria-label="แก้ไข" title="แก้ไข" data-tooltip="แก้ไข"
                                           href="{{ route('asset.registrations.edit', $asset->id) }}"
                                        >
                                            <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                                        </a>
                                        <button
                                            class="table-action-icon table-action-delete"
                                            type="button"
                                            aria-label="ลบ" title="ลบ" data-tooltip="ลบ"
                                            data-delete-id="{{ $asset->id }}"
                                            data-delete-code="{{ $asset->ass_code ?? '' }}"
                                            data-delete-url="{{ route('asset.registrations.destroy', $asset->id) }}"
                                        >
                                            <svg aria-hidden="true"><use href="#icon-trash"></use></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="no-data" colspan="8">ไม่พบข้อมูลครุภัณฑ์</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <p class="app-pagination-summary">
                    @if ($assets->total() > 0)
                        แสดง {{ $assets->firstItem() }}–{{ $assets->lastItem() }} จากทั้งหมด {{ $assets->total() }} รายการ
                    @else
                        ไม่พบรายการครุภัณฑ์
                    @endif
                </p>
                <x-app-pagination :paginator="$assets" />
            </div>
        </section>

    </div>

    {{-- Delete modal --}}
    <div class="confirm-overlay" id="deleteAssetOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon delete-confirm-icon">
                <svg><use href="#icon-alert-triangle"></use></svg>
            </div>
            <h3>ยืนยันการลบข้อมูล</h3>
            <p id="deleteAssetMessage">คุณแน่ใจหรือไม่ว่าต้องการลบครุภัณฑ์นี้<br>การดำเนินการนี้ไม่สามารถเรียกคืนได้</p>
            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelDeleteAssetButton">ยกเลิก</button>
                <button class="modal-confirm-btn delete-confirm-btn" type="button" id="confirmDeleteAssetButton">ยืนยันการลบ</button>
            </div>
        </div>
    </div>

    <form id="deleteAssetForm" method="POST" style="display:none;">
        @csrf
        @method('DELETE')
    </form>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-003-manage-asset-registration/script.js'])
@endsection
