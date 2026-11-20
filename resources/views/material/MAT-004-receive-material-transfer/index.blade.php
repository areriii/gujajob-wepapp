@extends('layouts.app')

@section('title', 'รับโอนวัสดุ')

@section('page-style')
    @vite([
        'resources/css/components/pagination.css',
        'resources/css/components/table-actions.css',
        'resources/css/components/search-autocomplete.css',
        'resources/css/components/sort-icon.css',
        'resources/css/material/MAT-004-receive-material-transfer/style.css',
    ])
@endsection

@section('content')
    <div class="page-container">
        <x-page-header :title="$pageTitle" />

        @if (session('success'))
            <div class="form-success-box">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="form-error-box">{{ session('error') }}</div>
        @endif

        @if (($pendingCount ?? 0) > 0)
            {{-- ใบที่รอรับโอนถูกจัดให้ขึ้นก่อนแล้ว บรรทัดนี้บอกจำนวนไว้กันผู้ใช้ไล่เปิดทีละหน้า --}}
            <div class="transfer-pending-note" role="status">
                มีใบเบิกรอรับโอนอยู่ <strong>{{ number_format($pendingCount) }}</strong> ใบ แสดงไว้บนสุดของรายการแล้ว
            </div>
        @endif

        <section class="transfer-card">
            <div class="card-title">
                <svg class="card-title-icon"><use href="#icon-material-list"></use></svg>
                <span>รายการรับโอนวัสดุ</span>
            </div>

            {{-- ── Search toolbar ── --}}
            <form class="transfer-toolbar" method="GET" action="{{ route('material.transfer.index') }}">
                    <div class="transfer-field-group">
                        <label for="transferSearchType">ค้นหาจาก</label>
                        <select id="transferSearchType" name="search_by">
                            <option value="wd_code" {{ $searchBy === 'wd_code' ? 'selected' : '' }}>เลขที่ใบเบิก</option>
                        </select>
                    </div>

                    <div class="transfer-field-group transfer-search-group">
                        <label for="transferSearchInput">คำค้นหา</label>
                        <input
                            id="transferSearchInput"
                            name="keyword"
                            type="search"
                            placeholder="กรอกเลขที่ใบเบิก"
                            value="{{ $keyword }}"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                        >
                    </div>

                    <button class="search-btn" type="submit">ค้นหา</button>
                </form>

            {{-- ── Table ──────────────────────────────────────── --}}
            <div class="transfer-table-wrapper">
                <table class="transfer-table">
                    <thead>
                        <tr>
                            <x-sortable-th label="เลขที่ใบเบิก"   key="wd_code"   :currentSort="$sort" :currentDirection="$direction" />
                            <x-sortable-th label="วันที่เบิก"    key="wd_date"   :currentSort="$sort" :currentDirection="$direction" />
                            <x-sortable-th label="ผู้เบิก"        key="requester" :currentSort="$sort" :currentDirection="$direction" />
                            <th>หน่วยงานที่ขอเบิก</th>
                            <x-sortable-th label="หมายเลขรับโอน" key="insp_code" :currentSort="$sort" :currentDirection="$direction" />
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($withdrawals as $wd)
                            @php
                                $insp        = $inspMap[$wd->id] ?? null;
                                $isReceived  = $insp && ($insp['list_count'] ?? 0) > 0;
                                $ispCode     = $isReceived ? ($insp['mat_insp_code'] ?? '-') : '-';
                                $statusLabel = $isReceived ? 'รับโอนแล้ว' : 'รอรับโอน';
                                $statusClass = $isReceived ? 'received' : 'pending';
                            @endphp
                            <tr>
                                <td>
                                    <a class="mat004-code-link" href="{{ route('material.transfer.show', $wd->mat_wd_code) }}">{{ $wd->mat_wd_code }}</a>
                                </td>
                                <td>{{ thai_date($wd->mat_wd_date) }}</td>
                                <td>{{ $wd->mat_wd_person ?? '-' }}</td>
                                <td>{{ $wd->organization?->org_name ?? '-' }}</td>
                                <td>{{ $ispCode }}</td>
                                <td>
                                    <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="transfer-no-data" colspan="6">ไม่พบรายการใบเบิกที่อนุมัติแล้ว</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="transfer-table-footer">
                <p class="app-pagination-summary">
                    @if ($withdrawals->total() > 0)
                        แสดง {{ $withdrawals->firstItem() }}–{{ $withdrawals->lastItem() }}
                        จากทั้งหมด {{ $withdrawals->total() }} รายการ
                    @else
                        แสดงทั้งหมด 0 รายการ
                    @endif
                </p>
                <x-app-pagination :paginator="$withdrawals" />
            </div>

        </section>


    </div>
@endsection

@section('page-script')
    <script>window.searchSuggestionsUrl = "{{ route('search.suggestions') }}";</script>
    @vite([
        'resources/js/components/pagination.js',
        'resources/js/material/MAT-004-receive-material-transfer/script.js',
    ])
@endsection
