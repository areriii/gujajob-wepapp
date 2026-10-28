@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/pagination.css',
        'resources/css/components/search-autocomplete.css',
        'resources/css/components/searchable-select.css',
        'resources/css/components/sort-icon.css',
        'resources/css/components/table-actions.css',
        'resources/css/material/MAT-006-record-balance-setting/style.css',
    ])
@endsection

@section('content')
    <div class="page-container">
        <x-page-header :title="$pageTitle" />

        <section class="balance-card">
            <form method="GET" action="{{ route('material.balance.index') }}" id="balanceSearchForm">
                <div class="filter-area">
                    <div class="filter-row">
                        <div class="field-group">
                            <label for="orgId">หน่วยงาน</label>
                            {{-- '__all__' is not numeric, so the controller reads it as every organization --}}
                            <x-searchable-select
                                name="org_id"
                                id="orgId"
                                placeholder="ทุกหน่วยงาน"
                                :options="collect([['value' => '__all__', 'label' => 'ทุกหน่วยงาน']])->merge($organizations->map(fn ($org) => ['value' => $org->org_id, 'label' => $org->org_name]))"
                                :selected="$orgId ?? '__all__'"
                            />
                        </div>

                        <div class="field-group">
                            <label for="fiscalYearSelect">ปีงบประมาณ</label>
                            <select id="fiscalYearSelect" name="fiscal_year">
                                @foreach ($availableFiscalYears as $fy)
                                    <option value="{{ $fy }}" @selected($fiscalYear == $fy)>
                                        {{ $fy }}{{ $fy === $currentFY ? ' (ปัจจุบัน)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field-group">
                            <label for="searchBy">ค้นหาจาก</label>
                            <select id="searchBy" name="search_by">
                                <option value="name" {{ $searchBy === 'name' ? 'selected' : '' }}>ชื่อวัสดุ</option>
                                <option value="code" {{ $searchBy === 'code' ? 'selected' : '' }}>รหัสวัสดุ</option>
                            </select>
                        </div>

                        <div class="field-group search-field">
                            <label for="searchInput">คำค้นหา</label>
                            <input
                                id="searchInput"
                                name="keyword"
                                type="search"
                                placeholder="กรอกชื่อวัสดุ"
                                value="{{ $keyword }}"
                                autocomplete="off"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                            >
                        </div>

                        <button class="search-btn" type="submit">ค้นหา</button>
                    </div>
                </div>

                {{-- preserve sort params through search --}}
                @if ($sort)
                    <input type="hidden" name="sort" value="{{ $sort }}">
                    <input type="hidden" name="direction" value="{{ $direction }}">
                @endif
            </form>

            @if (! $isEditable)
                <div class="readonly-notice" style="margin:12px 0;padding:10px 14px;background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;font-size:13px;color:#92400e;">
                    ปีงบประมาณ {{ $fiscalYear }} เป็นปีที่ผ่านมา — ดูได้อย่างเดียว ไม่สามารถแก้ไขได้
                </div>
            @endif

            <div class="list-title" style="display:flex;align-items:center;gap:12px;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <svg class="card-title-icon"><use href="#icon-material-list"></use></svg>
                    <span>รายการ ({{ $materials->total() }} รายการ)
                        — ปีงบประมาณ {{ $fiscalYear }}
                        @if ($orgId !== null)
                            @php $selOrg = $organizations->firstWhere('org_id', $orgId); @endphp
                            — {{ $selOrg?->org_name ?? '' }}
                        @endif
                    </span>
                </div>
                <div style="display:flex;gap:8px;">
                    <button
                        class="print-btn"
                        type="button"
                        id="printBalanceButton"
                        {{-- Same filters as the list on screen, every page of it --}}
                        data-pdf-url="{{ route('material.balance.download', array_merge(
                            ['search_by' => $searchBy, 'keyword' => $keyword, 'org_id' => $orgId, 'fiscal_year' => $fiscalYear, 'sort' => $sort, 'direction' => $direction],
                            request()->boolean('pdf_test') ? ['pdf_test' => 1] : []
                        )) }}"
                        data-pdf-filename="material-balance-setting-{{ $fiscalYear }}.pdf"
                    >
                        <svg class="print-btn-icon"><use href="#icon-printer"></use></svg>
                        จัดพิมพ์รายงาน
                    </button>
                    @if ($canAdjust)
                        <button
                            class="adjust-btn"
                            type="button"
                            id="bulkUpdateButton"
                            disabled
                            data-bulk-url="{{ $bulkUpdateUrl }}"
                            data-fiscal-year="{{ $fiscalYear }}"
                            data-org-id="{{ $orgId }}"
                        >
                            ปรับปรุง
                        </button>
                    @endif
                </div>
            </div>

            <div class="table-wrapper">
                <table class="balance-table">
                    <thead>
                        <tr>
                            <x-sortable-th label="ปีงบประมาณ"         key="fiscal_year" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'org_id'=>$orgId,'fiscal_year'=>$fiscalYear]" />
                            <x-sortable-th label="รหัสวัสดุ"           key="code"        :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'org_id'=>$orgId,'fiscal_year'=>$fiscalYear]" />
                            <x-sortable-th label="ชื่อวัสดุ"           key="name"        :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'org_id'=>$orgId,'fiscal_year'=>$fiscalYear]" />
                            <th>หน่วย</th>
                            <x-sortable-th label="ราคาเฉลี่ย/หน่วย" key="avg_price" :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'org_id'=>$orgId,'fiscal_year'=>$fiscalYear]" />
                            <x-sortable-th label="ยอดคงเหลือ"          key="balance"     :currentSort="$sort" :currentDirection="$direction" :extraParams="['search_by'=>$searchBy,'keyword'=>$keyword,'org_id'=>$orgId,'fiscal_year'=>$fiscalYear]" />
                            <th class="recommended-column" title="{{ $recommendationNote }}">
                                จำนวนเฉลี่ยในการเบิกต่อเดือน
                                @if ($recommendationMethod === \App\Services\MaterialStockRecommendationService::METHOD_AI)
                                    <span class="recommended-method">AI</span>
                                @endif
                            </th>
                            @if ($canAdjust)
                                <th class="action-column">จัดการ</th>
                            @endif
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($materials as $mat)
                            @php
                                $invAmt   = $mat->current_balance;
                                $avgPrice = $avgPrices[(int) $mat->id] ?? null;
                                $matCode  = $mat->mat_code;
                            @endphp
                            <tr data-material-id="{{ $mat->id }}" data-material-code="{{ $matCode }}">
                                <td>{{ $fiscalYear }}</td>
                                <td><span class="blue-text">{{ $mat->mat_code }}</span></td>
                                <td><span class="blue-text material-name">{{ $mat->mat_name }}</span></td>
                                <td>{{ $mat->unit }}</td>
                                <td>
                                    {{ $avgPrice !== null ? number_format($avgPrice, 2) : '-' }}
                                </td>
                                <td>
                                    <input
                                        class="balance-qty-input"
                                        type="number"
                                        value="{{ (int) ($invAmt ?? 0) }}"
                                        data-original-value="{{ (int) ($invAmt ?? 0) }}"
                                        min="0"
                                        step="1"
                                        {{-- Read-only until the pen icon is clicked; the tick locks it again --}}
                                        disabled
                                    >
                                </td>
                                <td class="recommended-column recommended-balance">{{ isset($recommended[(int) $mat->id]) ? number_format($recommended[(int) $mat->id]) : '-' }}</td>
                                @if ($canAdjust)
                                    <td class="action-column">
                                        <button
                                            class="table-action-icon table-action-edit"
                                            type="button"
                                            aria-label="แก้ไขยอดคงเหลือ"
                                            title="แก้ไขยอดคงเหลือ"
                                            data-tooltip="แก้ไขยอดคงเหลือ"
                                            data-mode="edit"
                                            data-material-id="{{ $mat->id }}"
                                            data-material-code="{{ $matCode }}"
                                        >
                                            <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td class="no-data" colspan="{{ $canAdjust ? 8 : 7 }}">ไม่พบข้อมูลวัสดุ</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <p>
                    @if ($materials->total() > 0)
                        แสดง {{ $materials->firstItem() }}–{{ $materials->lastItem() }} จากทั้งหมด {{ $materials->total() }} รายการ
                    @else
                        ไม่พบรายการตั้งยอดคงเหลือ
                    @endif
                </p>

                <x-app-pagination :paginator="$materials" />
            </div>
        </section>
    </div>

    {{-- Bulk Update Confirm Modal --}}
    <div class="confirm-overlay" id="bulkUpdateOverlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true">
            <div class="confirm-icon success-confirm-icon save-confirm-icon">
                <svg><use href="#icon-success"></use></svg>
            </div>
            <h3>ยืนยันการปรับปรุงยอดคงเหลือ</h3>
            <p>ต้องการบันทึกยอดคงเหลือที่แก้ไขทั้งหมดใช่หรือไม่?</p>
            <div class="confirm-actions">
                <button class="modal-cancel-btn" type="button" id="cancelBulkUpdateButton">ยกเลิก</button>
                <button class="modal-confirm-btn success-confirm-btn" type="button" id="confirmBulkUpdateButton">ปรับปรุง</button>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/material/MAT-006-record-balance-setting/script.js'])
@endsection
