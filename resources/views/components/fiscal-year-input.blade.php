{{--
    ช่องปีงบประมาณสำหรับหน้าค้นหา

    เดิมเป็น <select> ที่มีให้เลือกแค่ 5 ปี ผู้ใช้ที่ต้องการย้อนดูปีเก่ากว่านั้นทำไม่ได้เลย
    จึงเปลี่ยนเป็นช่องพิมพ์ที่มี datalist แนบไว้ด้วย — พิมพ์ปีเองก็ได้ กดเลือกจากรายการก็ได้
    ค่าเริ่มต้นเป็นปีงบประมาณปัจจุบันเสมอ
--}}
@props([
    'name' => 'fiscal_year',
    'id' => 'fiscalYearInput',
    'value' => null,
    'years' => [],
    'currentYear' => null,
    'label' => 'ปีงบประมาณ',
    'notice' => null,
])

@php
    $listId = $id . 'Options';
    $yearOptions = collect($years)->map(fn ($year) => (int) $year)->unique()->values();
    $currentFiscalYear = $currentYear !== null ? (int) $currentYear : null;
    $fieldValue = ($value !== null && $value !== '') ? (int) $value : $currentFiscalYear;
    $oldest = $yearOptions->min();
    $newest = $yearOptions->max();
@endphp

<div class="field-group fiscal-year-field">
    <label for="{{ $id }}">{{ $label }}</label>

    <input
        id="{{ $id }}"
        class="fiscal-year-input"
        type="text"
        name="{{ $name }}"
        value="{{ $fieldValue }}"
        list="{{ $listId }}"
        inputmode="numeric"
        pattern="\d{4}"
        maxlength="4"
        autocomplete="off"
        placeholder="{{ $currentFiscalYear ?? 'พ.ศ.' }}"
        aria-describedby="{{ $id }}Hint"
    >

    <datalist id="{{ $listId }}">
        @foreach ($yearOptions as $year)
            <option value="{{ $year }}">{{ $year }}{{ $year === $currentFiscalYear ? ' (ปัจจุบัน)' : '' }}</option>
        @endforeach
    </datalist>

    @if ($notice)
        <span class="fiscal-year-notice">{{ $notice }}</span>
    @elseif ($oldest !== null && $newest !== null)
        <span class="fiscal-year-hint" id="{{ $id }}Hint">พิมพ์ปี พ.ศ. ได้เอง ({{ $oldest }}–{{ $newest }})</span>
    @endif
</div>
