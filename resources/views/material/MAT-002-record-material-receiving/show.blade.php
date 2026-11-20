@extends('layouts.app')

@section('title', 'บันทึกการรับวัสดุเข้าคลัง')

@section('page-style')
    @vite(['resources/css/material/MAT-002-record-material-receiving/style.css'])
@endsection

@section('content')
    @php
        $totalQuantity = $record->details->sum('mat_amt');
    @endphp

    <div class="page-container receiving-create-page">
        <x-page-header :title="$pageTitle" />

        <div class="mat002-combined-card">

        <section class="document-card">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-document"></use></svg>
                <span>ข้อมูลการรับวัสดุ</span>
            </div>

            <div class="mat002-document-grid">
                <div class="mat002-field">
                    <label>เลขที่ใบรับวัสดุ</label>
                    <input type="text" value="{{ $record->mat_pro_code }}" readonly>
                </div>

                <div class="mat002-field">
                    <label>วันที่รับวัสดุ</label>
                    <input type="text" value="{{ thai_date($record->mat_pro_date) }}" readonly>
                </div>

                <div class="mat002-field">
                    <label>หน่วยงานที่รับเข้า</label>
                    <input type="text" value="{{ $record->organization?->org_name ?? '-' }}" readonly>
                </div>

                <div class="mat002-field">
                    <label>ผู้ประกอบการ</label>
                    @php
                        $dealerLabel = collect($dealerOptions)
                            ->firstWhere('value', (int) $record->dealer_id);
                    @endphp
                    <input type="text" value="{{ $dealerLabel['label'] ?? ($record->dealer?->dealer_name ?? '-') }}" readonly>
                </div>

                <div class="mat002-field">
                    <label>เลขที่สัญญา</label>
                    <input type="text" value="{{ $record->mat_pro_contact_no ?? '-' }}" readonly>
                </div>

                <div class="mat002-field">
                    <label>วิธีการจัดซื้อจัดจ้าง</label>
                    <input type="text" value="{{ $methodOptions[(int) ($record->mat_pro_method ?? 0)] ?? '-' }}" readonly>
                </div>

                <div class="mat002-field">
                    <label>หมายเลขใบเสนอราคา</label>
                    <input type="text" value="{{ $record->mat_pro_quotation ?? '-' }}" readonly>
                </div>

                <div class="mat002-field">
                    <label>วันที่ของสัญญา</label>
                    <input type="text" value="{{ thai_date($record->mat_pro_contact_date) }}" readonly>
                </div>
            </div>

            <div class="vat-box">
                <div>
                    <p class="vat-title">ภาษีมูลค่าเพิ่ม (VAT)</p>

                    @foreach ([1 => 'รวม VAT', 2 => 'ไม่รวม VAT', 3 => 'ไม่มีภาษี'] as $typeVal => $typeLabel)
                        <label class="radio-label">
                            <input type="radio"
                                   @if ((int) ($record->vat_type ?? 0) === $typeVal) checked @endif
                                   disabled>
                            <span>{{ $typeLabel }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="vat-rate">
                    <label>อัตราภาษี :</label>
                    <input type="number" value="{{ $record->vat_rate !== null ? $record->vat_rate : '' }}" readonly>
                    <span>%</span>
                </div>
            </div>
        </section>

        <hr class="mat002-section-divider">

        <section class="items-card">
            <div class="section-title">
                <svg class="section-icon"><use href="#icon-panel"></use></svg>
                <span>รายการวัสดุที่รับเข้าคลัง</span>
            </div>

            <div class="table-wrapper compact-table-wrapper">
                <table class="receiving-items-table receiving-detail-table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>รหัสวัสดุ</th>
                            <th>ชื่อวัสดุ</th>
                            <th>จำนวน</th>
                            <th>หน่วยนับ</th>
                            <th>ราคา/หน่วย</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($record->details as $index => $item)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td><span class="material-code">{{ $item->material?->mat_code ?? '-' }}</span></td>
                                <td>{{ $item->material?->mat_name ?? '-' }}</td>
                                <td>{{ $item->mat_amt }}</td>
                                <td>{{ $item->material?->unit ?? '-' }}</td>
                                <td>{{ number_format((float) $item->mat_price, 2, '.', '') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="no-data">ยังไม่มีรายการวัสดุ</td>
                            </tr>
                        @endforelse
                    </tbody>

                    <tfoot>
                        <tr>
                            <td colspan="3"></td>
                            <td class="total-label">รวมจำนวนรับเข้าทั้งสิ้น</td>
                            <td class="total-value">{{ $totalQuantity }}</td>
                            <td class="total-unit">จำนวน</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="form-actions">
                <a class="cancel-btn" href="{{ route('material.receiving.index') }}">ย้อนกลับ</a>
            </div>
        </section>

        </div>{{-- end .mat002-combined-card --}}
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/material/MAT-002-record-material-receiving/script.js'])
@endsection
