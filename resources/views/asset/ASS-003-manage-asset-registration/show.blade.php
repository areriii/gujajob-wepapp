@extends('layouts.app')

@section('page-style')
    @vite(['resources/css/asset/ASS-003-manage-asset-registration/create.css'])
@endsection

@section('content')
    <div class="page-container asset-registration-create-page">
        <x-page-header :title="$pageTitle" />

        <section class="form-card">
            <div class="form-title">
                <svg class="form-title-icon"><use href="#icon-info"></use></svg>
                <span>รายละเอียดทะเบียนครุภัณฑ์</span>
            </div>

            {{-- ──────────────────────────────────────────────── --}}
            <h3 class="section-title">1. ข้อมูลทั่วไปของครุภัณฑ์</h3>

            <div class="form-row one-col short-row">
                <div class="form-field">
                    <label>รหัสครุภัณฑ์ประจำหน่วยงาน</label>
                    <input type="text" value="{{ app(\App\Services\AssetDisplayService::class)->displayCode($asset) }}" readonly>
                </div>
            </div>

            <div class="form-row four-col compact-top">
                <div class="form-field">
                    <label>รหัสประเภทครุภัณฑ์</label>
                    <input type="text" readonly value="{{ $asset->category->asscat_code ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>หมวดครุภัณฑ์</label>
                    <input type="text" readonly value="{{ $asset->category->asscat_group ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>ชนิดครุภัณฑ์</label>
                    <input type="text" readonly value="{{ $asset->category->asscat_type ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>ชื่อครุภัณฑ์</label>
                    <input type="text" readonly value="{{ $asset->category->asscat_name ?? '-' }}">
                </div>
            </div>

            <div class="form-row two-col unit-rate-row">
                <div class="form-field">
                    <label>หน่วยนับ</label>
                    <input type="text" readonly value="{{ $asset->category->asscat_unit ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>อัตราค่าเสื่อม</label>
                    <input type="text" readonly value="{{ $asset->category->depreciation_rate ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>สถานะการซื้อทดแทน</label>
                    <input type="text" disabled value="{{ \App\Http\Controllers\AssetController::REPLACEMENT_STATUS[$asset->replacement_status ?? 'no'] ?? '-' }}">
                </div>
            </div>

            <div class="form-row one-col">
                <div class="form-field">
                    <label>ลักษณะ / รายละเอียดครุภัณฑ์</label>
                    <textarea rows="3" readonly>{{ $asset->ass_desc ?? '-' }}</textarea>
                </div>
            </div>

            <div class="form-row three-col">
                <div class="form-field">
                    <label>รุ่น / แบบ</label>
                    <input type="text" readonly value="{{ $asset->ass_model ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>หมายเลขเครื่อง</label>
                    <input type="text" readonly value="{{ $asset->ass_serail ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>มูลค่าครุภัณฑ์ (บาท)</label>
                    <input type="text" readonly value="{{ $asset->ass_price !== null ? number_format((float)$asset->ass_price, 2) : '-' }}">
                </div>
            </div>

            {{-- ──────────────────────────────────────────────── --}}
            <h3 class="section-title">2. ผู้ประกอบการและสัญญา</h3>

            <div class="form-row four-col">
                <div class="form-field">
                    <label>ผู้ประกอบการ</label>
                    <input type="text" readonly value="{{ $asset->dealer->dealer_name ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>เลขที่สัญญา</label>
                    <input type="text" readonly value="{{ $asset->ass_contact_no ?? '-' }}">
                </div>
                <div class="form-field">
                    <label>วันที่ทำสัญญา</label>
                    <input type="text" readonly value="{{ $asset->ass_contact_date ? thai_date($asset->ass_contact_date) : '-' }}">
                </div>
                <div class="form-field">
                    <label>วันที่ตรวจรับ</label>
                    <input type="text" readonly value="{{ $asset->inspect_date ? thai_date($asset->inspect_date) : '-' }}">
                </div>
            </div>

            {{-- ──────────────────────────────────────────────── --}}
            <h3 class="section-title">3. อายุการใช้งานและการรับประกัน</h3>

            <div class="section3-grid">
                <div class="section3-left">
                    <div class="form-row two-col">
                        <div class="form-field">
                            <label>หน่วยงานผู้ใช้</label>
                            <input type="text" readonly value="{{ $asset->subOrganization->org_name ?? ($asset->sub_org_id ? '-' : '') }}">
                        </div>
                        <div class="form-field">
                            @php $statusInfo = $displayStatus ?? \App\Http\Controllers\AssetController::assetStatusLabel($asset->ass_status ?? ''); @endphp
                            <label>สถานะ</label>
                            <input type="text" readonly value="{{ $statusInfo['label'] }}">
                        </div>
                    </div>
                    <div class="form-row two-col">
                        <div class="form-field">
                            <label>ระยะเวลารับประกัน (วัน)</label>
                            <input type="text" readonly value="{{ $asset->warranty ?? '-' }}">
                        </div>
                        <div class="form-field">
                            <label>อายุครุภัณฑ์ (ปี)</label>
                            <input type="text" readonly value="{{ $asset->ass_lifetime ?? '-' }}">
                        </div>
                    </div>
                    <div class="form-row one-col">
                        <div class="form-field">
                            <label>มูลค่าคงเหลือปัจจุบัน (บาท)</label>
                            <input type="text" readonly value="{{ $asset->remain_price !== null ? number_format((float)$asset->remain_price, 2) : '-' }}">
                        </div>
                    </div>
                    <div class="form-row one-col">
                        <div class="form-field">
                            <label>หมายเหตุ</label>
                            <textarea rows="3" readonly>{{ $asset->remarks ?? '-' }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- Right: images from ASSET_IMAGE --}}
                <div class="section3-right">
                    <div class="form-field upload-field">
                        <label>รูปภาพครุภัณฑ์</label>
                        @if ($images->isNotEmpty())
                            <div class="existing-images-list">
                                @foreach ($images as $img)
                                    <div class="existing-image-item">
                                        <img src="{{ $img['url'] }}" alt="รูปครุภัณฑ์" class="existing-image-thumb">
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="upload-box" style="cursor:default;">
                                <svg aria-hidden="true"><use href="#icon-image"></use></svg>
                                <span style="color:#9ca3af;">ไม่มีรูปภาพ</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a class="cancel-btn" href="{{ route('asset.registrations.index') }}">ย้อนกลับ</a>
            </div>
        </section>
    </div>
@endsection
