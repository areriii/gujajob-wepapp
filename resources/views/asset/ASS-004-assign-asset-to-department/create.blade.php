@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/search-autocomplete.css',
        'resources/css/components/searchable-select.css',
        'resources/css/components/table-actions.css',
        'resources/css/asset/ASS-004-assign-asset-to-department/create.css',
    ])
@endsection

@section('content')
    <div class="page-container assignment-create-page">
        <x-page-header :title="$pageTitle" />

        <section class="assignment-create-card">
            <form method="POST" action="{{ route('asset.assignments.store') }}" id="createAssignmentForm"
                  data-asset-search-url="{{ route('asset.assignments.assets.search') }}">
                @csrf

                <h3 class="card-form-heading">การจัดสรรครุภัณฑ์ใหม่</h3>

                {{-- Section 1: Header info --}}
                <div class="section-block">
                    <h3 class="section-title">1. เลือกหน่วยงานที่จะจัดสรรให้</h3>

                    <div class="form-grid two-col">
                        <div class="form-group">
                            <label class="form-label">หน่วยงานผู้จัดสรร</label>
                            <div class="form-control-readonly">{{ $userOrg->org_name ?? '-' }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">หน่วยงานผู้รับจัดสรร <span class="required">*</span></label>
                                                        <div class="guja-autocomplete @error('target_org_id') is-invalid @enderror"
                                                                 data-server-select data-min-chars="0"
                                 data-endpoint="{{ route('search.suggestions') }}?entity=assign_org&limit=20&q="
                                 data-initial-label="{{ old('_target_org_label', '') }}">
                                <input type="text"   class="guja-autocomplete__input" placeholder="พิมพ์ชื่อหน่วยงาน" autocomplete="off">
                                <span               class="guja-autocomplete__arrow">▼</span>
                                <div               class="guja-autocomplete__items"></div>
                                <input type="hidden" class="guja-autocomplete__value" name="target_org_id" value="{{ old('target_org_id', '') }}" required>
                            </div>
                            @error('target_org_id')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="form-grid two-col" style="max-width:760px;margin-top:10px;">
                        <div class="form-group">
                            <label class="form-label">ผู้จัดสรร <span class="required">*</span></label>
                                                        <div class="guja-autocomplete @error('assigner_id') is-invalid @enderror"
                                                                 data-server-select data-min-chars="0"
                                 data-endpoint="{{ route('search.suggestions') }}?entity=assign_user&limit=20&q="
                                 data-initial-label="{{ old('_assigner_label', $currentUser->user_name ?? '') }}">
                                <input type="text"   class="guja-autocomplete__input" placeholder="พิมพ์ชื่อผู้จัดสรร" autocomplete="off">
                                <span               class="guja-autocomplete__arrow">▼</span>
                                <div               class="guja-autocomplete__items"></div>
                                <input type="hidden" class="guja-autocomplete__value" name="assigner_id" value="{{ old('assigner_id', $currentUser->id ?? '') }}" required>
                            </div>
                            @error('assigner_id')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="assign_date">วันที่จัดสรร <span class="required">*</span></label>
                            <input class="form-control js-date-picker @error('assign_date') is-invalid @enderror"
                                   type="text" id="assign_date" name="assign_date"
                                   placeholder="วว-ดด-ปปปป"
                                   value="{{ old('assign_date', now()->format('Y-m-d')) }}"
                                   autocomplete="off" required>
                            @error('assign_date')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                {{-- Section 2: Available assets --}}
                <div class="section-block asset-selection-frame">
                    <h3 class="section-title">2. ครุภัณฑ์ที่ยังไม่ได้จัดสรร - เลือกรายการที่ต้องการ</h3>
                    <div class="asset-search-bar">
                        <div class="form-group">
                            <label class="form-label" for="assetSearchField">ค้นหาจาก</label>
                            <select id="assetSearchField" class="search-field-select">
                                <option value="">ทั้งหมด</option>
                                <option value="name">ชื่อครุภัณฑ์</option>
                                <option value="code">รหัสครุภัณฑ์</option>
                                <option value="category">หมวดครุภัณฑ์</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="assetSearchInput">คำค้นหา</label>
                            <input type="text" id="assetSearchInput" class="asset-search-input" placeholder="พิมพ์เพื่อค้นหา" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="visibility:hidden">&nbsp;</label>
                            <button type="button" id="assetSearchBtn" class="asset-search-btn">ค้นหา</button>
                        </div>
                    </div>

                    @error('asset_ids')<p class="field-error">{{ $message }}</p>@enderror

                    <div class="table-wrapper">
                        <table class="asset-select-table" id="availableAssetsTable">
                            <thead>
                                <tr>
                                    <th class="col-check"><input type="checkbox" id="selectAllAvailable"></th>
                                    <th>รหัสครุภัณฑ์</th>
                                    <th>ชื่อครุภัณฑ์</th>
                                    <th>หมวดครุภัณฑ์</th>
                                    <th class="col-value">มูลค่า (บาท)</th>
                                    <th>วันที่ตรวจรับ</th>
                                </tr>
                            </thead>
                            <tbody id="availableAssetsBody">
                                @foreach ($availableAssets as $asset)
                                    <tr data-asset-id="{{ $asset->id }}"
                                        data-asset-code="{{ $asset->asset_code }}"
                                        data-asset-name="{{ $asset->asset_name }}"
                                        data-category="{{ $asset->category_name }}">
                                        <td class="col-check">
                                            <input class="asset-checkbox" type="checkbox"
                                                   value="{{ $asset->id }}"
                                                   data-asset-code="{{ $asset->asset_code }}"
                                                   data-asset-name="{{ $asset->asset_name }}"
                                                   data-category="{{ $asset->category_name }}"
                                                   data-asset-value="{{ number_format((float)($asset->asset_value ?? 0), 2) }}"
                                                   data-inspect-date="{{ $asset->inspect_date_th ?? '-' }}">
                                        </td>
                                        <td>{{ $asset->asset_code }}</td>
                                        <td>{{ $asset->asset_name }}</td>
                                        <td>{{ $asset->category_name }}</td>
                                        <td class="right">{{ number_format((float)($asset->asset_value ?? 0), 2) }}</td>
                                        <td>{{ $asset->inspect_date_th ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Section 3: Selected assets summary --}}
                <div class="section-block">
                    <h3 class="section-title">3. รายการครุภัณฑ์ที่เลือกจัดสรร</h3>
                    <div class="table-wrapper">
                        <table class="asset-selected-table" id="selectedAssetsTable">
                            <thead>
                                <tr>
                                    <th>รหัสครุภัณฑ์</th>
                                    <th>ชื่อครุภัณฑ์</th>
                                    <th>หมวดครุภัณฑ์</th>
                                    <th class="col-value">มูลค่า (บาท)</th>
                                    <th>วันที่ตรวจรับ</th>
                                    <th class="col-action">นำออก</th>
                                </tr>
                            </thead>
                            <tbody id="selectedAssetsBody">
                                <tr class="no-selection-row" id="noSelectionRow">
                                    <td class="no-data" colspan="6">ยังไม่ได้เลือกรายการ</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="selected-count">เลือกแล้ว: <strong id="selectedCount">0</strong> รายการ</p>
                </div>

                {{-- Actions --}}
                <div class="form-actions">
                    <a class="cancel-btn" href="{{ route('asset.assignments.index') }}">ย้อนกลับ</a>
                    <button class="save-btn" type="button" id="saveAssignmentBtn">บันทึก</button>
                </div>
            </form>
        </section>
    </div>

    {{-- Save confirmation modal --}}
    <div class="confirm-overlay" id="saveConfirmOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon save-confirm-icon">
                <svg><use href="#icon-success"></use></svg>
            </div>
            <h3>ยืนยันการบันทึก</h3>
            <p>คุณต้องการบันทึกการจัดสรรครุภัณฑ์นี้ใช่หรือไม่</p>
            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelSaveBtn">ยกเลิก</button>
                <button class="modal-confirm-btn save-confirm-btn" type="button" id="confirmSaveBtn">บันทึก</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-004-assign-asset-to-department/create.js'])
@endsection
