@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/pagination.css',
        'resources/css/components/searchable-select.css',
        'resources/css/components/sort-icon.css',
        'resources/css/material/MAT-005-material-register/style.css',
    ])
@endsection

@section('content')
    <div class="page-container">
        <x-page-header :title="$pageTitle" />

        <section class="register-filter-card">
            <form method="GET" action="{{ route('material.register.index') }}" id="registerSearchForm">
                <div class="filter-grid">
                    <div class="field-group">
                        <label for="orgId">หน่วยงาน</label>
                        {{-- Organizations the user may see; '__all__' is not numeric, so the controller reads it as every organization --}}
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
                                <option value="{{ $fy }}" @selected($fiscalYear == $fy)>{{ $fy }}{{ $fy === $currentFY ? ' (ปัจจุบัน)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="searchType">ค้นหาจาก</label>
                        <select id="searchType" name="search_type">
                            <option value="code" @selected($searchType === 'code')>รหัสวัสดุ</option>
                            <option value="name" @selected($searchType === 'name')>ชื่อวัสดุ</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="searchInput">คำค้นหา</label>
                        <input
                            id="searchInput"
                            name="keyword"
                            type="search"
                            placeholder="{{ $searchType === 'name' ? 'กรอกชื่อวัสดุ' : 'กรอกรหัสวัสดุ' }}"
                            value="{{ $keyword }}"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                        >
                    </div>

                    <div class="field-group">
                        <label for="dateFrom">ตั้งแต่วันที่</label>
                        <input id="dateFrom" name="date_from" type="text" class="js-date-picker" placeholder="วว-ดด-ปปปป" data-picker-position="below" value="{{ $dateFrom }}">
                    </div>

                    <div class="field-group">
                        <label for="dateTo">ถึงวันที่</label>
                        <input id="dateTo" name="date_to" type="text" class="js-date-picker" placeholder="วว-ดด-ปปปป" data-picker-position="below" value="{{ $dateTo }}">
                    </div>

                    <div class="search-action">
                        <button class="search-btn" type="submit">ค้นหา</button>
                    </div>
                </div>

                @if ($materialId)
                    <input type="hidden" name="material_id" value="{{ $materialId }}">
                @endif
                @if ($sort)
                    <input type="hidden" name="sort" value="{{ $sort }}">
                    <input type="hidden" name="direction" value="{{ $direction }}">
                @endif
            </form>
        </section>

        @if ($dateError)
            <div class="form-error-box">{{ $dateError }}</div>
        @endif

        @if (!$searched)
            {{-- Not searched yet: show all-materials list --}}
            <section class="register-card">
                <div class="register-card-header" style="border-bottom: 1px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 16px;">
                    <div class="register-title">
                        <svg class="register-title-icon"><use href="#icon-material-list"></use></svg>
                        <span>รายการวัสดุทั้งหมด</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="register-table" style="table-layout: auto;">
                        <thead>
                            <tr>
                                <th style="width:12%">รหัสวัสดุ</th>
                                <th>ชื่อวัสดุ</th>
                                <th style="width:8%">หน่วยนับ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($allMaterials as $mat)
                                <tr>
                                    <td>
                                        <a class="mat-code-link" href="{{ route('material.register.index', ['material_id' => $mat->id]) }}">
                                            {{ $mat->mat_code }}
                                        </a>
                                    </td>
                                    <td>{{ $mat->mat_name }}</td>
                                    <td>{{ $mat->unit }}</td>
                                </tr>
                            @empty
                                <tr><td class="no-data" colspan="3">ไม่พบข้อมูลวัสดุ</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <p class="app-pagination-summary">
                        @if ($allMaterials->total() > 0)
                            แสดง {{ $allMaterials->firstItem() }}–{{ $allMaterials->lastItem() }} จากทั้งหมด {{ $allMaterials->total() }} รายการ
                        @else
                            แสดง 0 รายการ
                        @endif
                    </p>
                    <x-app-pagination :paginator="$allMaterials" />
                </div>
            </section>

        @elseif ($multipleFound)
            {{-- Multiple results found --}}
            <section class="register-card">
                <div class="register-card-header" style="border-bottom: 1px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 16px;">
                    <div class="register-title">
                        <svg class="register-title-icon"><use href="#icon-clipboard"></use></svg>
                        <span>พบหลายรายการ — เลือกวัสดุที่ต้องการ</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="register-table" style="table-layout: auto;">
                        <thead>
                            <tr>
                                <th style="width:12%">รหัสวัสดุ</th>
                                <th>ชื่อวัสดุ</th>
                                <th style="width:8%">หน่วยนับ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($materials as $mat)
                                <tr>
                                    <td>
                                        <a class="mat-code-link" href="{{ route('material.register.index', ['material_id' => $mat->id, 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}">
                                            {{ $mat->mat_code }}
                                        </a>
                                    </td>
                                    <td>{{ $mat->mat_name }}</td>
                                    <td>{{ $mat->unit }}</td>
                                </tr>
                            @empty
                                <tr><td class="no-data" colspan="3">ไม่พบรายการ</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <p class="app-pagination-summary">
                        @if ($materials->total() > 0)
                            แสดง {{ $materials->firstItem() }}–{{ $materials->lastItem() }} จากทั้งหมด {{ $materials->total() }} รายการ
                        @else
                            แสดง 0 รายการ
                        @endif
                    </p>
                    <x-app-pagination :paginator="$materials" />
                </div>
            </section>

        @elseif ($material === null)
            <section class="register-card" style="padding: 28px;">
                <p class="no-data">ไม่พบข้อมูลวัสดุที่ค้นหา</p>
            </section>

        @else
            {{-- Ledger view for single found material --}}
            <section class="register-card">
                <div class="register-card-header">
                    <div class="register-title">
                        <svg class="register-title-icon"><use href="#icon-clipboard"></use></svg>
                        <span>{{ $material->mat_code }} : {{ $material->mat_name }}</span>
                    </div>

                    <button
                        class="print-btn"
                        type="button"
                        id="printRegisterButton"
                        {{-- Same material and filters as on screen, every transaction row --}}
                        data-pdf-url="{{ route('material.register.download', array_merge(
                            ['material_id' => $material->id, 'org_id' => $orgId, 'fiscal_year' => $fiscalYear, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'sort' => $sort, 'direction' => $direction],
                            request()->boolean('pdf_test') ? ['pdf_test' => 1] : []
                        )) }}"
                        data-pdf-filename="material-register-{{ $material->mat_code }}.pdf"
                    >
                        <svg class="print-icon"><use href="#icon-printer"></use></svg>
                        <span>พิมพ์คุมทะเบียนวัสดุ (PDF)</span>
                    </button>
                </div>

                <div class="summary-grid">
                    <div class="summary-box">
                        <p>ยอดยกมา (ยอดยกไปปีก่อน)</p>
                        <strong>{{ number_format($summary['forward']) }}</strong>
                    </div>

                    <div class="summary-box">
                        <p>รับเข้าสะสม (ปีงบปัจจุบัน)</p>
                        <strong class="green-number">+ {{ number_format($summary['in_total']) }}</strong>
                    </div>

                    <div class="summary-box">
                        <p>เบิกจ่ายสะสม (ปีงบปัจจุบัน)</p>
                        <strong class="red-number">- {{ number_format($summary['out_total']) }}</strong>
                    </div>

                    <div class="summary-box balance-box">
                        <p>ยอดคงเหลือปัจจุบัน (Balance)</p>
                        <strong>{{ number_format($summary['balance']) }}</strong>
                    </div>
                </div>

                <div class="history-title">ประวัติการเคลื่อนไหว (Transaction History)</div>

                <div class="table-wrapper">
                    <table class="register-table">
                        <thead>
                            <tr>
                                <x-sortable-th label="วันที่"             key="txn_date"        :currentSort="$sort" :currentDirection="$direction" />
                                <x-sortable-th label="เลขที่รายการ"       key="doc_code"        :currentSort="$sort" :currentDirection="$direction" />
                                <th>อ้างอิง REQ/RCV<br><span>(เลขที่เอกสาร)</span></th>
                                <th>รายการ (รายละเอียด)</th>
                                <th>ผู้ทำรายการ/ตรวจรับ</th>
                                <x-sortable-th label="รับ (In)"           key="in_qty_raw"      :currentSort="$sort" :currentDirection="$direction" />
                                <x-sortable-th label="จ่าย (Out)"         key="out_qty_raw"     :currentSort="$sort" :currentDirection="$direction" />
                                <x-sortable-th label="คงเหลือ (Bal)"      key="running_balance" :currentSort="$sort" :currentDirection="$direction" />
                            </tr>
                        </thead>

                        <tbody id="registerTableBody">
                            @forelse ($transactions as $txn)
                                <tr>
                                    <td>{{ $txn['date'] }}</td>
                                    <td>{{ $txn['doc_code'] }}</td>
                                    <td>
                                        @if (($txn['reference_no'] ?? '') !== '')
                                            <span class="reference-link">{{ $txn['reference_no'] }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ $txn['detail'] }}</td>
                                    <td>
                                        <div>{{ $txn['operator'] ?: '-' }}</div>
                                        @if ($txn['operator_role'])
                                            <small>{{ $txn['operator_role'] }}</small>
                                        @endif
                                    </td>
                                    <td class="in-column">{{ $txn['in_qty'] }}</td>
                                    <td class="out-column">{{ $txn['out_qty'] }}</td>
                                    <td class="balance-column">{{ $txn['balance'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="no-data" colspan="8">ไม่พบรายการเคลื่อนไหว</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <p class="app-pagination-summary">
                        @if ($transactions->total() > 0)
                            แสดง {{ $transactions->firstItem() }}–{{ $transactions->lastItem() }} จากทั้งหมด {{ $transactions->total() }} รายการ
                        @else
                            แสดง 0 รายการ
                        @endif
                    </p>
                    <x-app-pagination :paginator="$transactions" />
                </div>
            </section>
        @endif
    </div>
@endsection

@section('page-script')
    @vite([
        'resources/js/components/pagination.js',
        'resources/js/components/date-picker.js',
        'resources/js/material/MAT-005-material-register/script.js',
    ])
@endsection

