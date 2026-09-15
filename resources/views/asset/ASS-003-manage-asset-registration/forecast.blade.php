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
    // '__all__' lets the user pick "ทั้งหมด" again after choosing a value; the page script sends it as no filter.
    $allOption = ['value' => '__all__', 'label' => 'ทั้งหมด', 'searchText' => 'ทั้งหมด'];
@endphp

@section('content')
    <div
        class="page-container forecast-page"
        id="forecastPage"
        data-forecast-url="{{ route('asset.registrations.ai-forecast', absolute: false) }}"
        data-print-url="{{ route('asset.registrations.forecast-print', absolute: false) }}"
    >
        <x-page-header :title="$pageTitle" />

        <section class="registration-card forecast-filter-card">
            <div class="card-title">
                <svg class="card-title-icon"><use href="#icon-filter-report"></use></svg>
                <span>เงื่อนไขการพยากรณ์</span>
            </div>

            <div class="toolbar forecast-toolbar">
                <div class="field-group forecast-years-field">
                    <label for="forecastYears">พยากรณ์ล่วงหน้า</label>
                    <select id="forecastYears">
                        <option value="1" selected>1 ปี</option>
                        <option value="2">2 ปี</option>
                        <option value="3">3 ปี</option>
                    </select>
                </div>

                <div class="field-group forecast-cat-field">
                    <label for="forecastCatGroup">หมวดครุภัณฑ์</label>
                    <x-searchable-select
                        name="forecast_cat_group"
                        id="forecastCatGroup"
                        placeholder="ทั้งหมด"
                        :options="array_merge([$allOption], $forecastCategories)"
                        selected=""
                    />
                </div>

                <div class="field-group forecast-org-field">
                    <label for="forecastOrgId">หน่วยงาน</label>
                    <x-searchable-select
                        name="forecast_org_id"
                        id="forecastOrgId"
                        placeholder="ทั้งหมด"
                        :options="array_merge([$allOption], $forecastOrgs)"
                        selected=""
                    />
                </div>

                <button class="create-btn" type="button" id="forecastCalcBtn">คำนวณ</button>
                <button class="search-btn" type="button" id="forecastPrintBtn" disabled>จัดพิมพ์รายงาน</button>
            </div>

            <p class="forecast-rule-note">
                ปีที่ครบกำหนดทดแทน = ปีที่ค่าเสื่อมราคาครบอายุการใช้งานตามทะเบียน (เริ่มคิดเดือนที่ตรวจรับ ถ้าหลังวันที่ 15 เริ่มเดือนถัดไป คงเหลือ 1 บาท) · AI ใช้พยากรณ์ราคาจัดซื้อทดแทนของครุภัณฑ์ที่ครบกำหนดเท่านั้น
            </p>
        </section>

        <section class="registration-card forecast-result-card">
            <div class="card-title">
                <svg class="card-title-icon"><use href="#icon-report-chart"></use></svg>
                <span>ผลการพยากรณ์</span>
            </div>

            <div class="forecast-result-body">
                <div id="forecastResult" aria-live="polite">
                    <p class="forecast-placeholder-msg">เลือกเงื่อนไข แล้วกด "คำนวณ" เพื่อแสดงผลการพยากรณ์</p>
                </div>
            </div>
        </section>

        <div class="form-actions">
            <a class="cancel-btn" href="{{ route('asset.registrations.index') }}">ย้อนกลับ</a>
        </div>
    </div>
@endsection

@section('page-script')
    @vite(['resources/js/asset/ASS-003-manage-asset-registration/forecast.js'])
@endsection
