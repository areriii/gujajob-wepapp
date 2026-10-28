@extends('layouts.app')

@section('page-style')
    @vite([
        'resources/css/components/pagination.css',
        'resources/css/components/sort-icon.css',
        'resources/css/asset/ASS-003-manage-asset-registration/style.css',
        'resources/css/asset/ASS-003-manage-asset-registration/forecast.css',
    ])
@endsection

@php
    $thaiYear = fn ($ceYear) => (int) $ceYear + 543;
    $money = fn ($value) => is_numeric($value) ? number_format((float) $value, 2) : '-';
    $sortParams = ['year' => $year];
@endphp

@section('content')
    <div class="page-container forecast-page">
        <x-page-header :title="$pageTitle" />

        <section class="registration-card forecast-result-card">
            <div class="card-title">
                <svg class="card-title-icon"><use href="#icon-report-chart"></use></svg>
                <span>{{ $heading }}</span>
                @if ($hasResult && $yearKnown)
                    {{-- Same as the print and download buttons of the report pages: a PDF and an Excel file of the whole list. --}}
                    <div class="forecast-export-actions">
                        <a class="forecast-export-btn forecast-export-btn--print" href="{{ route('asset.registrations.forecast-assets.pdf', $exportQuery) }}" title="พิมพ์ (PDF)" aria-label="พิมพ์ (PDF)">
                            <svg aria-hidden="true"><use href="#icon-printer"></use></svg>
                        </a>
                        <a class="forecast-export-btn forecast-export-btn--excel" href="{{ route('asset.registrations.forecast-assets.excel', $exportQuery) }}" title="ดาวน์โหลด Excel" aria-label="ดาวน์โหลด Excel">
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <path d="M14 2v6h6"/>
                                <path d="m9 12 6 6M15 12l-6 6"/>
                            </svg>
                        </a>
                    </div>
                @endif
            </div>

            <div class="forecast-result-body">
                @if (! $hasResult)
                    <p class="forecast-empty-msg">ไม่พบผลการพยากรณ์ กรุณากลับไปกด "คำนวณ" ที่หน้าพยากรณ์งบประมาณก่อน</p>
                @elseif (! $yearKnown)
                    <p class="forecast-empty-msg">ปี พ.ศ. {{ $thaiYear($year) }} ไม่อยู่ในผลการพยากรณ์ล่าสุด ({{ $period }}) กรุณากลับไปเลือกปีจากหน้าพยากรณ์งบประมาณ</p>
                @else
                    {{-- The list always comes from the last calculation; show its conditions so the two can be matched. --}}
                    <div class="forecast-ai-summary">
                        <div class="ai-summary-row">
                            <div class="ai-summary-item">
                                <span>ระยะเวลาพยากรณ์</span>
                                <strong>{{ $params['forecast_years'] ?? ($result['forecast_years'] ?? '-') }} ปี</strong>
                                @if ($period !== '')<small class="forecast-period">{{ $period }}</small>@endif
                            </div>
                            <div class="ai-summary-item">
                                <span>จำนวนครุภัณฑ์ที่คาดว่าจะทดแทน</span>
                                <strong>{{ number_format($assets->total()) }} รายการ</strong>
                            </div>
                            <div class="ai-summary-item highlight">
                                <span>งบประมาณที่คาดการณ์</span>
                                <strong>{{ number_format($totalBudget, 2) }} บาท</strong>
                                <small class="forecast-period">มูลค่าตอนซื้อ + {{ $percent }}%</small>
                            </div>
                        </div>
                    </div>
                    <p class="forecast-rule-note">
                        หมวดครุภัณฑ์: {{ $params['filter_cat_name'] ?? 'ทั้งหมด' }} · หน่วยงาน: {{ $params['filter_org_name'] ?? 'ทั้งหมด' }}
                        @if (! empty($params['calculated_at'])) · คำนวณเมื่อ {{ $params['calculated_at'] }} @endif
                    </p>

                    @if ($assets->total() === 0)
                        <p class="forecast-empty-msg">ไม่พบครุภัณฑ์ที่คาดว่าจะถึงกำหนดทดแทน{{ $year !== null ? ' ในปี พ.ศ. ' . $thaiYear($year) : '' }}</p>
                    @else
                        <div class="forecast-table-card">
                            <div class="forecast-table-scroll">
                                <table class="forecast-table forecast-candidate-table">
                                    <thead>
                                        <tr>
                                            <x-sortable-th label="ลำดับ" key="sequence" class="forecast-center" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="รหัสครุภัณฑ์" key="asset_code" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="ชื่อครุภัณฑ์" key="category_name" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="หมวดครุภัณฑ์" key="category_group" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="หน่วยงาน" key="organization_name" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="วันที่ตรวจรับ" key="inspect_date" class="forecast-center" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="ปีที่ครบกำหนดทดแทน" key="forecast_year" class="forecast-center" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="มูลค่า" key="acquisition_value" class="forecast-num" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="ค่าเสื่อมราคาสะสม" key="accumulated_depreciation" class="forecast-num" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th label="มูลค่าคงเหลือ" key="current_value" class="forecast-num" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                            <x-sortable-th :label="'งบประมาณทดแทน (+' . $percent . '%)'" key="replacement_budget" class="forecast-num" :currentSort="$sort" :currentDirection="$direction" :extraParams="$sortParams" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($assets as $asset)
                                            <tr>
                                                <td class="forecast-center">{{ $asset['sequence'] }}</td>
                                                <td>
                                                    <a class="ass-code-link asset-code" href="{{ route('asset.registrations.index', ['search_by' => 'code', 'keyword' => $asset['asset_code'] ?? '']) }}">{{ $asset['display_code'] }}</a>
                                                </td>
                                                <td>{{ $asset['category_name'] ?? '-' }}</td>
                                                <td>{{ $asset['category_group'] ?? '-' }}</td>
                                                <td>{{ $asset['organization_name'] ?? '-' }}</td>
                                                <td class="forecast-center">{{ $asset['acceptance_date'] ?? '-' }}</td>
                                                <td class="forecast-center">พ.ศ. {{ $thaiYear($asset['forecast_year'] ?? 0) }}</td>
                                                <td class="forecast-num">{{ $money($asset['acquisition_value'] ?? null) }}</td>
                                                <td class="forecast-num">{{ $money($asset['accumulated_depreciation'] ?? null) }}</td>
                                                <td class="forecast-num">{{ $money($asset['current_value'] ?? null) }}</td>
                                                <td class="forecast-num ai-cost-cell">{{ $money($asset['replacement_budget'] ?? null) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="table-footer forecast-table-footer">
                                <p class="app-pagination-summary">แสดง {{ number_format($assets->firstItem()) }}–{{ number_format($assets->lastItem()) }} จากทั้งหมด {{ number_format($assets->total()) }} รายการ</p>
                                <x-app-pagination :paginator="$assets" />
                            </div>
                        </div>
                    @endif

                @endif

                <div class="form-actions forecast-frame-actions">
                    <a class="cancel-btn" href="{{ route('asset.registrations.replacement-forecast', ['restore' => 1]) }}">ย้อนกลับ</a>
                </div>
            </div>
        </section>
    </div>
@endsection

@section('page-script')
    {{-- Sidebar behaviour only: the forecast calculator is not on this page. --}}
    @vite(['resources/js/asset/ASS-003-manage-asset-registration/forecast.js'])
@endsection
