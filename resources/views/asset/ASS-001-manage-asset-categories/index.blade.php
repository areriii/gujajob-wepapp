@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/search-autocomplete.css',
        'resources/css/components/table-actions.css',
        'resources/css/components/pagination.css',
        'resources/css/components/sort-icon.css',
        'resources/css/asset/ASS-001-manage-asset-categories/style.css',
    ])
@endsection

@section('content')
    <div class="page-container">
        <x-page-header :title="$pageTitle" />

        @if (session('asset_category_error'))
            <div class="form-error-box" style="margin-bottom:12px;">{{ session('asset_category_error') }}</div>
        @endif

        <section class="asset-category-card">
            <div class="card-title">
                <svg class="card-title-icon"><use href="#icon-material-list"></use></svg>
                <span>รายการประเภทครุภัณฑ์ในระบบ</span>
            </div>

            <form method="GET" action="{{ route('asset.categories.index') }}" id="assetCategorySearchForm">
                <div class="toolbar">
                    <div class="field-group">
                        <label for="assetSearchType">ค้นหาจาก</label>
                        <select id="assetSearchType" name="search_by">
                            <option value="all"   @selected(($searchBy ?? 'all') === 'all')>ทั้งหมด</option>
                            <option value="name"  @selected($searchBy === 'name')>ชื่อครุภัณฑ์</option>
                            <option value="code"  @selected($searchBy === 'code')>รหัสประเภทครุภัณฑ์</option>
                            <option value="type"  @selected($searchBy === 'type')>ชนิดครุภัณฑ์</option>
                            <option value="group" @selected($searchBy === 'group')>หมวดครุภัณฑ์</option>
                            <option value="unit"  @selected($searchBy === 'unit')>หน่วยนับ</option>
                        </select>
                    </div>

                    <div class="field-group search-group">
                        <label for="assetSearchInput">คำค้นหา</label>
                        <input
                            id="assetSearchInput"
                            name="keyword"
                            type="search"
                            placeholder="กรอกคำค้นหา"
                            value="{{ $keyword }}"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                        >
                    </div>

                    <button class="search-btn" type="submit">ค้นหา</button>

                    @if ($sort)
                        <input type="hidden" name="sort" value="{{ $sort }}">
                        <input type="hidden" name="direction" value="{{ $direction }}">
                    @endif

                    <a class="create-btn link-button" href="{{ route('asset.categories.create') }}">
                        บันทึกประเภทครุภัณฑ์ใหม่
                    </a>
                </div>
            </form>

            <div class="table-wrapper">
                <table class="asset-category-table">
                    <thead>
                        <tr>
                            <x-sortable-th label="รหัสประเภทครุภัณฑ์" key="code"  :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword]" />
                            <x-sortable-th label="ชื่อครุภัณฑ์"        key="name"  :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword]" />
                            <x-sortable-th label="ชนิดครุภัณฑ์"        key="type"  :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword]" />
                            <x-sortable-th label="หมวดครุภัณฑ์"        key="group" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword]" />
                            <x-sortable-th label="หน่วยนับ"           key="unit"             :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword]" />
                            <x-sortable-th label="อัตราค่าเสื่อม (%)" key="depreciation_rate" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword]" />
                            <th class="action-column">จัดการ</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($categories as $cat)
                            <tr>
                                <td>
                                    <a class="asset-code-link" href="{{ route('asset.categories.show', $cat->id) }}">
                                        <span class="asset-code">{{ $cat->asscat_code }}</span>
                                    </a>
                                </td>
                                <td>{{ $cat->asscat_name }}</td>
                                <td>{{ $cat->asscat_type }}</td>
                                <td>{{ $cat->asscat_group }}</td>
                                <td>{{ $cat->asscat_unit ?? '-' }}</td>
                                <td>{{ $cat->depreciation_rate !== null ? number_format((float)$cat->depreciation_rate, 0) . '%' : '-' }}</td>
                                <td class="action-column">
                                    <div class="table-action-buttons">
                                        <a class="table-action-icon table-action-edit"
                                           aria-label="แก้ไข" title="แก้ไข" data-tooltip="แก้ไข"
                                           href="{{ route('asset.categories.edit', $cat->id) }}"
                                        >
                                            <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                                        </a>
                                        <button
                                            class="table-action-icon table-action-delete"
                                            type="button"
                                            aria-label="ลบ" title="ลบ" data-tooltip="ลบ"
                                            data-delete-id="{{ $cat->id }}"
                                            data-delete-code="{{ $cat->asscat_code }}"
                                            data-delete-url="{{ route('asset.categories.destroy', $cat->id) }}"
                                        >
                                            <svg aria-hidden="true"><use href="#icon-trash"></use></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="no-data" colspan="7">ไม่พบข้อมูลประเภทครุภัณฑ์</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <p class="app-pagination-summary">
                    @if ($categories->total() > 0)
                        แสดง {{ $categories->firstItem() }}–{{ $categories->lastItem() }} จากทั้งหมด {{ $categories->total() }} รายการ
                    @else
                        ไม่พบรายการประเภทครุภัณฑ์
                    @endif
                </p>
                <x-app-pagination :paginator="$categories" />
            </div>
        </section>
    </div>

    {{-- Delete confirmation modal --}}
    <div class="confirm-overlay" id="deleteAssetCategoryOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon delete-confirm-icon">
                <svg><use href="#icon-alert-triangle"></use></svg>
            </div>
            <h3>ยืนยันการลบข้อมูล</h3>
            <p id="deleteAssetCategoryMessage">คุณต้องการลบประเภทครุภัณฑ์นี้ใช่หรือไม่?<br>การดำเนินการนี้ไม่สามารถเรียกคืนได้</p>
            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelDeleteAssetCategoryButton">ยกเลิก</button>
                <button class="modal-confirm-btn delete-confirm-btn" type="button" id="confirmDeleteAssetCategoryButton">
                    ยืนยันการลบ
                </button>
            </div>
        </div>
    </div>

    {{-- Hidden delete form --}}
    <form id="deleteAssetCategoryForm" method="POST" style="display:none;">
        @csrf
        @method('DELETE')
    </form>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-001-manage-asset-categories/script.js'])
@endsection
