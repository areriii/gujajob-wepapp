@php
    $isEdit = $mode === 'edit';
    $formAction = $isEdit
        ? route('material.receiving.update', $record->mat_pro_code)
        : route('material.receiving.store');

    $receiptCode    = old('mat_pro_code', $record->mat_pro_code ?? $nextReceiptNo ?? '');
    $selectedOrgId    = old('org_id',    $record?->org_id    ?? '');
    $selectedDealerId = old('dealer_id', $record?->dealer_id ?? '');
    $selectedMethod = (int) old('mat_pro_method', $record->mat_pro_method ?? 0);
    $selectedVatType = (int) old('vat_type', $record->vat_type ?? 1);
    // VAT rate: only 7 or 10 allowed; default 7; 0 for no-VAT type
    $selectedVatRate = match ($selectedVatType) {
        3       => 0,
        default => (int) old('vat_rate', $record?->vat_rate ?? 7),
    };
    // Clamp to valid options
    if (! in_array($selectedVatRate, [7, 10], true)) {
        $selectedVatRate = 7;
    }

    // Items always come from the already-saved header's own details - items are
    // never submitted as part of this page's own form/old-input anymore, they are
    // added one at a time via AJAX once the header exists.
    $detailItems = ($record?->details ?? collect())->map(function ($detail) {
        return [
            'id' => (int) $detail->id,
            'mat_id' => (int) $detail->mat_id,
            'mat_code' => $detail->material?->mat_code,
            'mat_name' => $detail->material?->mat_name,
            'unit' => $detail->material?->unit,
            'mat_amt' => (float) $detail->mat_amt,
            'mat_price' => (float) $detail->mat_price,
        ];
    })->values()->all();

    $materialJson = $materials->map(function ($material) {
        return [
            'id' => (int) $material->id,
            'code' => $material->mat_code,
            'name' => $material->mat_name,
            'unit' => $material->unit,
        ];
    })->values()->all();
@endphp

@if (session('success'))
    <div class="form-success-box">
        {{ session('success') }}
    </div>
@endif

@if ($errors->any())
    <div class="form-error-box">
        <strong>พบข้อผิดพลาดในการบันทึกข้อมูล</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (session('error'))
    <div class="form-error-box">
        {{ session('error') }}
    </div>
@endif

{{-- ===== Combined single-card wrapper (doc fields + items table) ===== --}}
<div class="mat002-combined-card">

{{-- ===== Step 1: Header form (MATERIAL_PROCUREMENT). Saved independently of items. ===== --}}
<form id="receivingHeaderForm" action="{{ $formAction }}" method="POST" autocomplete="off">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <section class="document-card">
        <div class="section-title">
            <svg class="section-icon"><use href="#icon-document"></use></svg>
            <span>{{ $isEdit ? 'แก้ไขข้อมูลการรับวัสดุ' : 'บันทึกข้อมูลการรับวัสดุ' }}</span>
        </div>

        <div class="mat002-document-grid">
            <div class="mat002-field">
                <label for="receiptNo">เลขที่ใบรับวัสดุ</label>
                <input id="receiptNo" name="mat_pro_code" type="text" value="{{ $receiptCode }}" readonly>
                <div class="mat002-field-error" id="receiptNoError" aria-live="polite">@error('mat_pro_code'){{ $message }}@enderror</div>
            </div>

            <div class="mat002-field">
                <label for="receivedDate">วันที่รับวัสดุ <span class="required">*</span></label>
                <input id="receivedDate" name="mat_pro_date" type="text" class="js-date-picker @error('mat_pro_date') gujajob-is-invalid @enderror" placeholder="dd/mm/yyyy" data-picker-position="below" value="{{ old('mat_pro_date', optional($record?->mat_pro_date)->format('Y-m-d')) }}" required>
                <div class="mat002-field-error" id="receivedDateError" aria-live="polite">@error('mat_pro_date'){{ $message }}@enderror</div>
            </div>

            <div class="mat002-field">
                <label for="receiverDepartment">หน่วยงานที่รับเข้า <span class="required">*</span></label>
                <x-searchable-select
                    name="org_id"
                    id="receiverDepartment"
                    placeholder="-- เลือกหน่วยงานที่รับเข้า --"
                    :options="$organizationOptions"
                    :selected="$selectedOrgId"
                    :required="true"
                />
                <div class="mat002-field-error" id="receiverDepartmentError" aria-live="polite">@error('org_id'){{ $message }}@enderror</div>
            </div>

            <div class="mat002-field">
                <label for="dealerSelect">ผู้ประกอบการ <span class="required">*</span></label>
                <x-searchable-select
                    name="dealer_id"
                    id="dealerSelect"
                    placeholder="-- เลือกผู้ประกอบการ --"
                    :options="$dealerOptions"
                    :selected="$selectedDealerId"
                    :required="true"
                />
                <div class="mat002-field-error" id="dealerSelectError" aria-live="polite">@error('dealer_id'){{ $message }}@enderror</div>
            </div>

            <div class="mat002-field">
                <label for="procurementMethod">วิธีการจัดซื้อจัดจ้าง</label>
                <select id="procurementMethod" name="mat_pro_method">
                    <option value="">เลือกวิธีการจัดซื้อจัดจ้าง</option>
                    @foreach ($methodOptions as $methodValue => $methodLabel)
                        <option value="{{ $methodValue }}" {{ $selectedMethod === (int) $methodValue ? 'selected' : '' }}>
                            {{ $methodLabel }}
                        </option>
                    @endforeach
                </select>
                <div class="mat002-field-error" aria-live="polite">@error('mat_pro_method'){{ $message }}@enderror</div>
            </div>

            <div class="mat002-field">
                <label for="quotationNo">หมายเลขใบเสนอราคา</label>
                <input id="quotationNo" name="mat_pro_quotation" type="text" value="{{ old('mat_pro_quotation', $record->mat_pro_quotation ?? '') }}" placeholder="ระบุหมายเลขใบเสนอราคา">
                <div class="mat002-field-error" aria-live="polite">@error('mat_pro_quotation'){{ $message }}@enderror</div>
            </div>

            <div class="mat002-field">
                <label for="contractNo">เลขที่สัญญา</label>
                <input id="contractNo" name="mat_pro_contact_no" type="text" value="{{ old('mat_pro_contact_no', $record->mat_pro_contact_no ?? '') }}" placeholder="ระบุเลขที่สัญญา">
                <div class="mat002-field-error" aria-live="polite">@error('mat_pro_contact_no'){{ $message }}@enderror</div>
            </div>

            <div class="mat002-field">
                <label for="contractDate">วันที่ของสัญญา</label>
                <input id="contractDate" name="mat_pro_contact_date" type="text" class="js-date-picker" placeholder="dd/mm/yyyy" data-picker-position="below" value="{{ old('mat_pro_contact_date', optional($record?->mat_pro_contact_date)->format('Y-m-d')) }}">
                <div class="mat002-field-error" aria-live="polite">@error('mat_pro_contact_date'){{ $message }}@enderror</div>
            </div>
        </div>

        <div class="vat-box">
            <div>
                <p class="vat-title">ภาษีมูลค่าเพิ่ม (VAT)</p>
                @foreach ($vatOptions as $vatValue => $vatLabel)
                    <label class="radio-label">
                        <input type="radio" name="vat_type" value="{{ $vatValue }}" {{ $selectedVatType === (int) $vatValue ? 'checked' : '' }}>
                        <span>{{ $vatLabel }}</span>
                    </label>
                @endforeach
            </div>

            <div class="vat-rate">
                <label for="vatRate">อัตราภาษี :</label>
                <select id="vatRate" name="vat_rate"
                        {{ $selectedVatType === 3 ? 'disabled' : '' }}>
                    <option value="7"  {{ $selectedVatRate === 7  ? 'selected' : '' }}>7</option>
                    <option value="10" {{ $selectedVatRate === 10 ? 'selected' : '' }}>10</option>
                </select>
                <span>%</span>
                {{-- send 0 when disabled (no-VAT) --}}
                @if ($selectedVatType === 3)
                    <input type="hidden" name="vat_rate" value="0">
                @endif
            </div>
        </div>

        {{-- saveHeaderButton kept hidden; JS confirmation flow clicks it programmatically --}}
        <button id="saveHeaderButton" type="button" hidden aria-hidden="true"></button>
        @if (!$isEdit)
            {{-- Draft items JSON for single-pass create submit --}}
            <input type="hidden" id="draftItemsJson" name="draft_items_json" value="{{ old('draft_items_json', '[]') }}">
        @endif
    </section>
</form>

<hr class="mat002-section-divider">

{{-- ===== Step 2: Items (MATERIAL_PROCUREMENT_LIST). Enabled only once the header exists. ===== --}}
<section class="items-card">
    <div class="section-title">
        <svg class="section-icon"><use href="#icon-panel"></use></svg>
        <span>{{ $isEdit ? 'แก้ไขรายการวัสดุที่รับเข้าคลัง' : 'รายการที่รับวัสดุเข้าคลัง' }}</span>
    </div>

    <div class="mat002-search-grid">
        <div class="mat002-field">
            <label for="materialSearchType">ค้นหาจาก</label>
            <select id="materialSearchType">
                <option value="code">รหัสวัสดุ</option>
                <option value="name">ชื่อวัสดุ</option>
            </select>
            <div class="mat002-field-error" id="materialSearchTypeError" aria-live="polite"></div>
        </div>

        <div class="mat002-field mat002-search-field">
            <label for="materialSearchInput">คำค้นหา</label>
            <input id="materialSearchInput" type="search" placeholder="กรอกรหัสวัสดุ" autocomplete="off">
            <div id="materialSearchResults" class="mat002-search-results" aria-live="polite"></div>
            <div class="mat002-field-error" id="materialSearchInputError" aria-live="polite"></div>
        </div>

        <div class="mat002-field mat002-btn-field">
            <label aria-hidden="true">&nbsp;</label>
            <button class="search-btn" type="button" id="findMaterialButton">ค้นหา</button>
        </div>
    </div>

    <div class="mat002-item-grid">
        <div class="mat002-field" style="display:none">
            <label for="materialId">Material ID</label>
            <input id="materialId" type="text" readonly>
        </div>

        <div class="mat002-field">
            <label for="materialCode">รหัสวัสดุ</label>
            <input id="materialCode" type="text" placeholder="-" readonly>
            <div class="mat002-field-error" id="materialCodeError" aria-live="polite"></div>
        </div>

        <div class="mat002-field">
            <label for="materialName">ชื่อวัสดุ</label>
            <input id="materialName" type="text" placeholder="-" readonly>
            <div class="mat002-field-error" id="materialNameError" aria-live="polite"></div>
        </div>

        <div class="mat002-field">
            <label for="receiveQty">จำนวน <span class="required">*</span></label>
            <input id="receiveQty" type="number" min="0" step="0.01" placeholder="0.00" disabled>
            <div class="mat002-field-error" id="receiveQtyError" aria-live="polite"></div>
        </div>

        <div class="mat002-field">
            <label for="materialUnit">หน่วยนับ</label>
            <input id="materialUnit" type="text" placeholder="-" readonly>
            <div class="mat002-field-error" id="materialUnitError" aria-live="polite"></div>
        </div>

        <div class="mat002-field">
            <label for="unitPrice">ราคา/หน่วย <span class="required">*</span></label>
            <input id="unitPrice" type="number" min="0" step="0.01" placeholder="0.00" disabled>
            <div class="mat002-field-error" id="unitPriceError" aria-live="polite"></div>
        </div>

        <div class="mat002-field mat002-btn-field">
            <label aria-hidden="true">&nbsp;</label>
            <button class="add-btn" type="button" id="addMaterialButton" disabled>เพิ่ม</button>
        </div>
    </div>

    <div class="table-wrapper compact-table-wrapper">
        <table class="receiving-items-table">
            <thead>
                <tr>
                    <th>ลำดับ</th>
                    <th>รหัสวัสดุ</th>
                    <th>ชื่อวัสดุ</th>
                    <th>จำนวน</th>
                    <th>หน่วยนับ</th>
                    <th>ราคา/หน่วย</th>
                    <th>จัดการ</th>
                </tr>
            </thead>

            <tbody id="receivingItemsBody">
                @forelse ($detailItems as $index => $item)
                    <tr
                        data-item-id="{{ $item['id'] }}"
                        data-mat-id="{{ (int) ($item['mat_id'] ?? 0) }}"
                        data-code="{{ $item['mat_code'] ?? '' }}"
                        data-name="{{ $item['mat_name'] ?? '' }}"
                        data-unit="{{ $item['unit'] ?? '' }}"
                        data-qty="{{ (float) ($item['mat_amt'] ?? 0) }}"
                        data-price="{{ (float) ($item['mat_price'] ?? 0) }}"
                    >
                        <td>{{ $index + 1 }}</td>
                        <td><span class="material-code">{{ $item['mat_code'] ?? '' }}</span></td>
                        <td>{{ $item['mat_name'] ?? '' }}</td>
                        <td class="mat002-qty-cell">
                            @if ($isEdit)
                                <input class="mat002-qty-visible mat002-inline-number-input"
                                       type="number"
                                       value="{{ (float) ($item['mat_amt'] ?? 0) }}"
                                       min="0.01" step="0.01" disabled>
                                <input class="mat002-qty-hidden"
                                       type="hidden"
                                       form="receivingHeaderForm"
                                       name="items[{{ $item['id'] }}][qty]"
                                       value="{{ (float) ($item['mat_amt'] ?? 0) }}">
                            @else
                                {{ (float) ($item['mat_amt'] ?? 0) }}
                            @endif
                        </td>
                        <td>{{ $item['unit'] ?? '' }}</td>
                        <td class="mat002-price-cell">
                            @if ($isEdit)
                                <input class="mat002-price-visible mat002-inline-number-input"
                                       type="number"
                                       value="{{ number_format((float) ($item['mat_price'] ?? 0), 2, '.', '') }}"
                                       min="0" step="0.01" disabled>
                                <input class="mat002-price-hidden"
                                       type="hidden"
                                       form="receivingHeaderForm"
                                       name="items[{{ $item['id'] }}][price]"
                                       value="{{ number_format((float) ($item['mat_price'] ?? 0), 2, '.', '') }}">
                            @else
                                {{ number_format((float) ($item['mat_price'] ?? 0), 2, '.', '') }}
                            @endif
                        </td>
                        <td>
                            <div class="table-action-buttons">
                                <button class="table-action-icon table-action-edit" type="button"
                                        title="{{ $isEdit ? 'แก้ไขจำนวน' : 'แก้ไข' }}"
                                        aria-label="{{ $isEdit ? 'แก้ไขจำนวน' : 'แก้ไข' }}"
                                        data-tooltip="{{ $isEdit ? 'แก้ไขจำนวน' : 'แก้ไข' }}">
                                    <svg aria-hidden="true"><use href="#icon-square-pen"></use></svg>
                                </button>
                                <button class="table-action-icon table-action-delete" type="button" title="ลบ" aria-label="ลบ" data-tooltip="ลบ">
                                    <svg aria-hidden="true"><use href="#icon-trash"></use></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr class="empty-row">
                        <td colspan="7" class="no-data">ยังไม่มีรายการ</td>
                    </tr>
                @endforelse
            </tbody>

            <tfoot>
                <tr>
                    <td colspan="7" class="total-summary-cell">
                        รวมจำนวนรับเข้าทั้งสิ้น
                        <span class="total-value" id="totalQty">0</span>
                        รายการ
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

</section>

{{-- ===== Single action row at the bottom of the combined card ===== --}}
<div class="form-actions" style="margin-top:28px">
    <a class="cancel-btn" href="{{ route('material.receiving.index') }}">ย้อนกลับ</a>
    @if ($isEdit)
        <button class="submit-btn" type="button" id="finalizeReceivingButton" @disabled(! $record)>บันทึก</button>
    @else
        <button class="submit-btn" type="button" id="saveHeaderButtonBottom">บันทึก</button>
    @endif
</div>

</div>{{-- end .mat002-combined-card --}}

@if ($record)
    <form id="finalizeForm" action="{{ route('material.receiving.finalize', $record->mat_pro_code) }}" method="POST" style="display:none">
        @csrf
    </form>
@endif

<div class="confirm-overlay" id="saveReceivingOverlay" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="saveReceivingConfirmTitle">
        <div class="confirm-icon save-confirm-icon">
            <svg><use href="#icon-success"></use></svg>
        </div>

        <h3 id="saveReceivingConfirmTitle">ยืนยันการบันทึกข้อมูล</h3>
        <p>คุณแน่ใจหรือไม่ว่าต้องการบันทึกข้อมูลการรับวัสดุนี้</p>

        <div class="confirm-actions">
            <button class="modal-cancel-btn" type="button" id="cancelSaveReceivingButton">ยกเลิก</button>
            <button class="modal-confirm-btn" type="button" id="confirmSaveReceivingButton">ยืนยันการบันทึก</button>
        </div>
    </div>
</div>

@if ($isEdit)
<div class="confirm-overlay" id="updateReceivingOverlay" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="updateReceivingConfirmTitle">
        <div class="confirm-icon edit-icon">
            <svg class="confirm-edit-svg"><use href="#icon-square-pen"></use></svg>
        </div>

        <h3 id="updateReceivingConfirmTitle">ยืนยันการแก้ไขข้อมูล</h3>
        <p>คุณแน่ใจหรือไม่ว่าต้องการบันทึกการแก้ไขข้อมูลการรับวัสดุนี้</p>

        <div class="confirm-actions">
            <button class="modal-cancel-btn" type="button" id="cancelUpdateReceivingButton">ยกเลิก</button>
            <button class="modal-confirm-btn edit-confirm-btn" type="button" id="confirmUpdateReceivingButton">ยืนยันการแก้ไข</button>
        </div>
    </div>
</div>
@endif

<div class="confirm-overlay" id="noItemsOverlay" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="noItemsTitle">
        <div class="confirm-icon danger-confirm-icon">
            <svg><use href="#icon-alert-triangle"></use></svg>
        </div>

        <h3 id="noItemsTitle">ไม่สามารถดำเนินการได้</h3>
        <p id="noItemsMessage">กรุณาเพิ่มรายการวัสดุอย่างน้อย 1 รายการ</p>

        <div class="single-confirm-action">
            <button class="modal-confirm-btn" type="button" id="closeNoItemsButton">ตกลง</button>
        </div>
    </div>
</div>

<script>
    window.matReceivingMaterials = @json($materialJson);
    window.matReceivingRecordSaved = @json((bool) $record);
    window.matReceivingIsEdit = @json($isEdit);
    window.matReceivingItemsBaseUrl = @json($record ? route('material.receiving.items.store', $record->mat_pro_code) : null);
    window.matReceivingSaveItemsUrl = @json(route('material.receiving.save-items'));
    window.matReceivingPreviewCodeUrl = @json(route('material.receiving.preview-code'));
    window.matReceivingSearchUrl = @json(route('material.search-api'));
    window.matReceivingFinalizeError = @json(session('finalize_error'));
    window.mat002OldDraftItems = @json(!$isEdit ? (json_decode(old('draft_items_json', '[]'), true) ?? []) : []);
</script>
