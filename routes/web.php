<?php

use App\Http\Controllers\AssetAssignmentController;
use App\Http\Controllers\AssetDepartmentReceivingController;
use App\Http\Controllers\AssetDisposalController;
use App\Http\Controllers\AssetCategoryController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetReportController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DealerController;
use App\Http\Controllers\MaterialBalanceSettingController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MaterialReceivingController;
use App\Http\Controllers\MaterialRegisterController;
use App\Http\Controllers\MaterialTransferReceiveController;
use App\Http\Controllers\MaterialWithdrawApprovalController;
use App\Http\Controllers\MaterialWithdrawController;
use App\Http\Controllers\MaterialReportController;
use App\Http\Controllers\SearchSuggestionsController;
use Illuminate\Support\Facades\Route;

if (! function_exists('gujajob_material_items')) {
    function gujajob_material_items(): array
    {
        return [
            [
                'code' => 'MAT-1001',
                'name' => 'กระดาษถ่ายเอกสาร A4 80 แกรม',
                'description' => 'คุณลักษณะเฉพาะของกระดาษคุณภาพมาตรฐาน A4 80 แกรม',
                'unit' => 'รีม',
                'balance' => 10,
                'max' => 500,
            ],
            [
                'code' => 'MAT-3012',
                'name' => 'หมึกพิมพ์ Brother TN-2380',
                'description' => 'ตลับหมึกพิมพ์สำหรับเครื่องพิมพ์ Brother รุ่น TN-2380',
                'unit' => 'กล่อง',
                'balance' => 5,
                'max' => 50,
            ],
        ];
    }
}

if (! function_exists('gujajob_find_material')) {
    function gujajob_find_material(string $code): array
    {
        foreach (gujajob_material_items() as $material) {
            if ($material['code'] === $code) {
                return $material;
            }
        }

        abort(404);
    }
}

if (! function_exists('gujajob_material_receiving_records')) {
    function gujajob_material_receiving_records(): array
    {
        return [
            [
                'receipt_no' => 'REC-66-00124',
                'received_date' => '05/15/2024',
                'department' => 'สำนักบริหารกลาง (คลังส่วนกลาง)',
                'vendor' => 'บริษัท ออฟฟิศเมท (ไทย) จำกัด (มหาชน)',
                'contract_no' => 'CN123456',
                'procurement_method' => 'เฉพาะเจาะจง',
                'quotation_no' => 'QN123456',
                'contract_date' => '05/15/2024',
                'vat_mode' => 'รวม VAT',
                'vat_rate' => 7,
                'items' => [
                    [
                        'code' => 'MAT-1001',
                        'name' => 'กระดาษถ่ายเอกสาร A4 80 แกรม',
                        'quantity' => 50,
                        'unit' => 'รีม',
                        'unit_price' => '110.00',
                    ],
                ],
            ],
            [
                'receipt_no' => 'REC-66-00125',
                'received_date' => '05/15/2024',
                'department' => 'ฝ่ายพัสดุ',
                'vendor' => 'บริษัท ตัวอย่าง จำกัด',
                'contract_no' => 'CN789101',
                'procurement_method' => 'เฉพาะเจาะจง',
                'quotation_no' => 'QN789101',
                'contract_date' => '05/15/2024',
                'vat_mode' => 'รวม VAT',
                'vat_rate' => 7,
                'items' => [
                    [
                        'code' => 'MAT-3012',
                        'name' => 'หมึกพิมพ์ Brother TN-2380',
                        'quantity' => 20,
                        'unit' => 'กล่อง',
                        'unit_price' => '950.00',
                    ],
                ],
            ],
        ];
    }
}

if (! function_exists('gujajob_find_receiving_record')) {
    function gujajob_find_receiving_record(string $receiptNo): array
    {
        foreach (gujajob_material_receiving_records() as $record) {
            if ($record['receipt_no'] === $receiptNo) {
                return $record;
            }
        }

        abort(404);
    }
}


if (! function_exists('gujajob_material_withdrawal_records')) {
    function gujajob_material_withdrawal_records(): array
    {
        return [
            [
                'withdraw_no' => 'CI-2026-001',
                'withdraw_date' => '15/05/2024',
                'requester' => 'xxxxxxxxxx',
                'department' => 'xxxxxxxxxx',
                'status' => 'รอการอนุมัติ',
            ],
            [
                'withdraw_no' => 'CI-2026-002',
                'withdraw_date' => '15/05/2024',
                'requester' => 'xxxxxxxxxx',
                'department' => 'xxxxxxxxxx',
                'status' => 'รอการอนุมัติ',
            ],
        ];
    }
}


if (! function_exists('gujajob_material_register_data')) {
    function gujajob_material_register_data(): array
    {
        return [
            'MAT-1001' => [
                'code' => 'MAT-1001',
                'name' => 'กระดาษถ่ายเอกสาร A4 80 แกรม',
                'department' => 'กองคลังพัสดุ',
                'summary' => [
                    'forward' => 120,
                    'in_total' => 300,
                    'out_total' => 270,
                    'balance' => 150,
                ],
                'transactions' => [
                    [
                        'date' => '01/01/2024',
                        'transaction_no' => 'TRX-67-001',
                        'reference_no' => '-',
                        'detail' => 'ยอดยกมา (Balance Forward)',
                        'operator' => 'ระบบ (System)',
                        'role' => '',
                        'in' => '-',
                        'out' => '-',
                        'balance' => 120,
                    ],
                    [
                        'date' => '15/02/2024',
                        'transaction_no' => 'TRX-67-104',
                        'reference_no' => '-',
                        'detail' => 'รับวัสดุเข้าคลัง (บจก. ออฟฟิศเมท)',
                        'operator' => 'นายสมชาย รักดี',
                        'role' => 'กรรมการตรวจรับ',
                        'in' => 150,
                        'out' => '-',
                        'balance' => 270,
                    ],
                    [
                        'date' => '20/02/2024',
                        'transaction_no' => 'TRX-67-122',
                        'reference_no' => '-',
                        'detail' => 'เบิกวัสดุ (กองยุทธศาสตร์และแผนงาน)',
                        'operator' => 'นางสาวชมพู่ใจ ใจเย็น',
                        'role' => 'ผู้รับโอน/ผู้เบิก',
                        'in' => '-',
                        'out' => 50,
                        'balance' => 220,
                    ],
                    [
                        'date' => '10/03/2024',
                        'transaction_no' => 'TRX-67-189',
                        'reference_no' => '-',
                        'detail' => 'เบิกวัสดุ (ศูนย์เทคโนโลยีสารสนเทศ)',
                        'operator' => 'นายมานะ อดทน',
                        'role' => 'ผู้รับโอน/ผู้เบิก',
                        'in' => '-',
                        'out' => 120,
                        'balance' => 100,
                    ],
                    [
                        'date' => '05/04/2024',
                        'transaction_no' => 'TRX-67-201',
                        'reference_no' => 'RCV-67-0040',
                        'detail' => 'รับโอนวัสดุเข้าคลัง',
                        'operator' => 'นายสมชาย รักดี',
                        'role' => 'กรรมการตรวจรับ',
                        'in' => 150,
                        'out' => '-',
                        'balance' => 250,
                    ],
                    [
                        'date' => '20/05/2024',
                        'transaction_no' => 'TRX-67-250',
                        'reference_no' => '-',
                        'detail' => 'เบิกวัสดุ (สำนักบริหารกลาง)',
                        'operator' => 'นางสาวมารี ศรีสวัสดิ์',
                        'role' => 'ผู้รับโอน/ผู้เบิก',
                        'in' => '-',
                        'out' => 100,
                        'balance' => 150,
                    ],
                ],
            ],
            'MAT-3012' => [
                'code' => 'MAT-3012',
                'name' => 'หมึกพิมพ์ Brother TN-2380',
                'department' => 'กองคลังพัสดุ',
                'summary' => [
                    'forward' => 40,
                    'in_total' => 80,
                    'out_total' => 65,
                    'balance' => 55,
                ],
                'transactions' => [
                    [
                        'date' => '01/01/2024',
                        'transaction_no' => 'TRX-67-301',
                        'reference_no' => '-',
                        'detail' => 'ยอดยกมา (Balance Forward)',
                        'operator' => 'ระบบ (System)',
                        'role' => '',
                        'in' => '-',
                        'out' => '-',
                        'balance' => 40,
                    ],
                    [
                        'date' => '18/03/2024',
                        'transaction_no' => 'TRX-67-332',
                        'reference_no' => 'RCV-67-0068',
                        'detail' => 'รับวัสดุเข้าคลัง',
                        'operator' => 'นายสมชาย รักดี',
                        'role' => 'กรรมการตรวจรับ',
                        'in' => 80,
                        'out' => '-',
                        'balance' => 120,
                    ],
                    [
                        'date' => '25/04/2024',
                        'transaction_no' => 'TRX-67-354',
                        'reference_no' => '-',
                        'detail' => 'เบิกวัสดุ (ฝ่ายเทคโนโลยีสารสนเทศ)',
                        'operator' => 'นางสาวมารี ศรีสวัสดิ์',
                        'role' => 'ผู้รับโอน/ผู้เบิก',
                        'in' => '-',
                        'out' => 65,
                        'balance' => 55,
                    ],
                ],
            ],
        ];
    }
}


if (! function_exists('gujajob_material_balance_setting_records')) {
    function gujajob_material_balance_setting_records(): array
    {
        return [
            [
                'budget_year' => '2568',
                'material_code' => 'MAT-1001',
                'material_name' => 'กระดาษถ่ายเอกสาร A4 80 แกรม',
                'department' => 'กองคลังพัสดุ',
                'unit' => 'รีม',
                'average_price' => '112.50',
                'balance_quantity' => 120,
            ],
            [
                'budget_year' => '2568',
                'material_code' => 'MAT-1002',
                'material_name' => 'หมึกพิมพ์ Brother TN-2380',
                'department' => 'กองคลังพัสดุ',
                'unit' => 'กล่อง',
                'average_price' => '115.00',
                'balance_quantity' => 50,
            ],
            [
                'budget_year' => '2568',
                'material_code' => 'MAT-1003',
                'material_name' => 'หมึกพิมพ์ สีแดง',
                'department' => 'กองคลังพัสดุ',
                'unit' => 'กล่อง',
                'average_price' => '200.00',
                'balance_quantity' => 20,
            ],
        ];
    }
}



if (! function_exists('gujajob_asset_registration_records')) {
    function gujajob_asset_registration_records(): array
    {
        return [
            [
                'asset_code' => '7440-001-001',
                'sub_code' => '03/001/69',
                'asset_name' => 'Dell OptiPlex 3090',
                'asset_detail' => 'คอมพิวเตอร์และอุปกรณ์',
                'department' => 'กองคลังพัสดุ',
                'sub_department' => 'กลุ่มคลังวัสดุ',
                'check_date' => '20/03/2563',
                'value' => '32,500.00',
                'remaining_value' => '19,500.00',
                'status' => 'ปกติ',
                'status_type' => 'normal',
            ],
            [
                'asset_code' => '7440-001-002',
                'sub_code' => '',
                'asset_name' => 'HP LaserJet Pro M404dn',
                'asset_detail' => 'คอมพิวเตอร์และอุปกรณ์',
                'department' => '-',
                'sub_department' => '',
                'check_date' => '25/06/2565',
                'value' => '8,900.00',
                'remaining_value' => '1.00',
                'status' => 'พร้อมจำหน่าย',
                'status_type' => 'dispose',
            ],
            [
                'asset_code' => '7440-001-003',
                'sub_code' => '',
                'asset_name' => 'โต๊ะทำงานผู้บริหาร',
                'asset_detail' => 'เฟอร์นิเจอร์สำนักงาน',
                'department' => '-',
                'sub_department' => '',
                'check_date' => '05/01/2566',
                'value' => '15,000.00',
                'remaining_value' => '12,000.00',
                'status' => 'ปกติ',
                'status_type' => 'normal',
            ],
            [
                'asset_code' => '7440-001-004',
                'sub_code' => '',
                'asset_name' => 'Toyota Hilux Revo',
                'asset_detail' => 'ยานพาหนะ',
                'department' => '-',
                'sub_department' => '',
                'check_date' => '15/08/2564',
                'value' => '750,000.00',
                'remaining_value' => '525,000.00',
                'status' => 'ปกติ',
                'status_type' => 'normal',
            ],
            [
                'asset_code' => '7440-001-005',
                'sub_code' => '',
                'asset_name' => 'Canon iR2525',
                'asset_detail' => 'เครื่องถ่ายสำนักงาน',
                'department' => '-',
                'sub_department' => '',
                'check_date' => '05/10/2563',
                'value' => '45,000.00',
                'remaining_value' => '1.00',
                'status' => 'พร้อมจำหน่าย',
                'status_type' => 'dispose',
            ],
        ];
    }
}

if (! function_exists('gujajob_find_asset_registration_record')) {
    function gujajob_find_asset_registration_record(string $assetCode): array
    {
        foreach (gujajob_asset_registration_records() as $asset) {
            if ($asset['asset_code'] === $assetCode) {
                return $asset;
            }
        }

        abort(404);
    }
}

if (! function_exists('gujajob_asset_assignment_records')) {
    function gujajob_asset_assignment_records(): array
    {
        return [
            [
                'sequence' => 1,
                'assign_date' => '18/04/2568',
                'department' => 'กองคลังพัสดุ',
                'group' => 'กลุ่มจัดซื้อ',
                'quantity' => 2,
                'assigner' => 'สมชาย ใจดี',
            ],
            [
                'sequence' => 2,
                'assign_date' => '15/04/2568',
                'department' => 'สำนักบริหาร',
                'group' => 'กลุ่มงานบุคคล',
                'quantity' => 1,
                'assigner' => 'สมชาย ใจดี',
            ],
            [
                'sequence' => 3,
                'assign_date' => '10/04/2568',
                'department' => 'สนง.ภูมิภาค ภาคเหนือ',
                'group' => 'ฝ่ายคลัง',
                'quantity' => 3,
                'assigner' => 'มาลี รักดี',
            ],
        ];
    }
}

if (! function_exists('gujajob_asset_department_receiving_records')) {
    function gujajob_asset_department_receiving_records(): array
    {
        return [
            [
                'asset_code' => '7440-001-0001',
                'sub_code' => '',
                'asset_name' => 'Dell OptiPlex 3090',
                'category' => 'คอมพิวเตอร์และอุปกรณ์',
                'value' => '32,500.00',
                'receive_date' => null,
                'model_name' => 'Dell OptiPlex 3090 SFF',
                'serial_no' => 'DL2023-00124',
                'storage_name' => 'คลังอ้อมกลาง',
                'assigned_department' => 'กองคลังพัสดุ',
                'receiver_name' => '-',
                'receive_remark' => '-',
                'receive_subunit' => '-',
            ],
            [
                'asset_code' => '7110-002-0004',
                'sub_code' => '',
                'asset_name' => 'โต๊ะทำงานผู้บริหาร',
                'category' => 'เฟอร์นิเจอร์สำนักงาน',
                'value' => '15,000.00',
                'receive_date' => null,
                'model_name' => 'โต๊ะทำงานผู้บริหาร',
                'serial_no' => '-',
                'storage_name' => 'คลังอ้อมกลาง',
                'assigned_department' => 'กองคลังพัสดุ',
                'receiver_name' => '-',
                'receive_remark' => '-',
                'receive_subunit' => '-',
            ],
            [
                'asset_code' => '7440-003-0001',
                'sub_code' => '03/001/68',
                'asset_name' => 'Toyota Hilux Revo',
                'category' => 'ยานพาหนะ',
                'value' => '750,000.00',
                'receive_date' => '18/04/2568',
                'model_name' => 'Toyota Hilux Revo',
                'serial_no' => 'THR-2024-0098',
                'storage_name' => 'คลังอ้อมกลาง',
                'assigned_department' => 'กองคลังพัสดุ',
                'receiver_name' => '-',
                'receive_remark' => '-',
                'receive_subunit' => 'ห้องทอง บ.',
            ],
        ];
    }
}

if (! function_exists('gujajob_find_asset_department_receiving_record')) {
    function gujajob_find_asset_department_receiving_record(string $assetCode): array
    {
        foreach (gujajob_asset_department_receiving_records() as $record) {
            if ($record['asset_code'] === $assetCode) {
                return $record;
            }
        }

        abort(404);
    }
}

if (! function_exists('gujajob_asset_disposal_request_records')) {
    function gujajob_asset_disposal_request_records(): array
    {
        return [
            [
                'request_no' => 'SL2567001',
                'request_date' => '05/15/2024',
                'request_department' => 'xxxxxxxxxx',
                'reason' => 'ชำรุด',
                'approval_status' => 'รอการอนุมัติ',
                'approval_status_type' => 'pending',
                'asset_code' => '7440-001-002',
                'asset_name' => 'HP LaserJet Pro M404dn',
                'requested_by' => 'xxxxxxxxxx',
                'remark' => '-',
            ],
            [
                'request_no' => 'SL2567002',
                'request_date' => '05/15/2024',
                'request_department' => 'xxxxxxxxxx',
                'reason' => 'หมดความจำเป็นใช้งาน',
                'approval_status' => 'อนุมัติ',
                'approval_status_type' => 'approved',
                'asset_code' => '7440-004-0001',
                'asset_name' => 'Toyota Hilux Revo',
                'requested_by' => 'xxxxxxxxxx',
                'remark' => '-',
            ],
        ];
    }
}

if (! function_exists('gujajob_find_asset_disposal_request_record')) {
    function gujajob_find_asset_disposal_request_record(string $requestNo): array
    {
        foreach (gujajob_asset_disposal_request_records() as $record) {
            if ($record['request_no'] === $requestNo) {
                return $record;
            }
        }

        abort(404);
    }
}

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.post');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {

Route::get('/', function () {
    return redirect('/material/MAT-001-manage-material-items');
});

Route::get('/material/search-api', [MaterialController::class, 'searchApi'])
    ->name('material.search-api');

Route::get('/material/MAT-001-manage-material-items', [MaterialController::class, 'index'])
    ->name('material.items.index');
Route::get('/material/MAT-001-manage-material-items/create', [MaterialController::class, 'create'])
    ->name('material.items.create');
Route::post('/material/MAT-001-manage-material-items', [MaterialController::class, 'store'])
    ->name('material.items.store');
Route::get('/material/MAT-001-manage-material-items/{code}', [MaterialController::class, 'show'])
    ->name('material.items.show');
Route::get('/material/MAT-001-manage-material-items/{code}/edit', [MaterialController::class, 'edit'])
    ->name('material.items.edit');
Route::put('/material/MAT-001-manage-material-items/{code}', [MaterialController::class, 'update'])
    ->name('material.items.update');
Route::delete('/material/MAT-001-manage-material-items/{code}', [MaterialController::class, 'destroy'])
    ->name('material.items.destroy');

Route::get('/material/MAT-002-record-material-receiving', [MaterialReceivingController::class, 'index'])
    ->name('material.receiving.index');
Route::get('/material/MAT-002-record-material-receiving/create', [MaterialReceivingController::class, 'create'])
    ->name('material.receiving.create');
Route::get('/material/MAT-002-record-material-receiving/preview-code', [MaterialReceivingController::class, 'previewCode'])
    ->name('material.receiving.preview-code');
Route::post('/material/MAT-002-record-material-receiving/save-items', [MaterialReceivingController::class, 'saveItems'])
    ->name('material.receiving.save-items');
Route::post('/material/MAT-002-record-material-receiving', [MaterialReceivingController::class, 'storeHeader'])
    ->name('material.receiving.store');
Route::get('/material/MAT-002-record-material-receiving/{receiptNo}', [MaterialReceivingController::class, 'show'])
    ->name('material.receiving.show');
Route::get('/material/MAT-002-record-material-receiving/{receiptNo}/edit', [MaterialReceivingController::class, 'edit'])
    ->name('material.receiving.edit');
Route::put('/material/MAT-002-record-material-receiving/{receiptNo}', [MaterialReceivingController::class, 'update'])
    ->name('material.receiving.update');
Route::post('/material/MAT-002-record-material-receiving/{receiptNo}/items', [MaterialReceivingController::class, 'storeItem'])
    ->name('material.receiving.items.store');
Route::delete('/material/MAT-002-record-material-receiving/{receiptNo}/items/{itemId}', [MaterialReceivingController::class, 'destroyItem'])
    ->name('material.receiving.items.destroy');
Route::post('/material/MAT-002-record-material-receiving/{receiptNo}/finalize', [MaterialReceivingController::class, 'finalize'])
    ->name('material.receiving.finalize');
Route::delete('/material/MAT-002-record-material-receiving/{receiptNo}', [MaterialReceivingController::class, 'destroy'])
    ->name('material.receiving.destroy');



Route::get('/material/MAT-003-withdraw-material', [MaterialWithdrawController::class, 'index'])->name('material.withdraw.index');
Route::get('/material/MAT-003-withdraw-material/create', [MaterialWithdrawController::class, 'create'])->name('material.withdraw.create');
Route::post('/material/MAT-003-withdraw-material', [MaterialWithdrawController::class, 'store'])->name('material.withdraw.store');
Route::get('/material/MAT-003-withdraw-material/stock-check', [MaterialWithdrawController::class, 'stockCheck'])->name('material.withdraw.stock-check');

// ─── Approval sub-routes — must be declared before {code} wildcard routes ───
Route::get('/material/MAT-003-withdraw-material/approvals', [MaterialWithdrawApprovalController::class, 'index'])->name('material.withdraw.approval.index');
Route::get('/material/MAT-003-withdraw-material/approvals/{code}', [MaterialWithdrawApprovalController::class, 'show'])->name('material.withdraw.approval.show');
Route::post('/material/MAT-003-withdraw-material/approvals/{code}/validate-stock', [MaterialWithdrawApprovalController::class, 'validateStock'])->name('material.withdraw.approval.validate-stock');
Route::post('/material/MAT-003-withdraw-material/approvals/{code}/approve', [MaterialWithdrawApprovalController::class, 'approve'])->name('material.withdraw.approval.approve');
Route::post('/material/MAT-003-withdraw-material/approvals/{code}/reject', [MaterialWithdrawApprovalController::class, 'reject'])->name('material.withdraw.approval.reject');
Route::get('/material/MAT-003-withdraw-material/approvals/{code}/edit', [MaterialWithdrawApprovalController::class, 'edit'])->name('material.withdraw.approval.edit');

// ─── MAT-004: รับโอนวัสดุ ─────────────────────────────────────────────────
// MAT-004: {code} refers to mat_insp_code (ISP-YYYYY). Static sub-paths before wildcard.

// Unified search-suggestions autocomplete endpoint
Route::get('/search/suggestions', [SearchSuggestionsController::class, 'index'])->name('search.suggestions');

Route::get('/material/MAT-004-receive-material-transfer', [MaterialTransferReceiveController::class, 'index'])->name('material.transfer.index');
Route::post('/material/MAT-004-receive-material-transfer/create', [MaterialTransferReceiveController::class, 'createInspection'])->name('material.transfer.create');
Route::get('/material/MAT-004-receive-material-transfer/{code}/edit', [MaterialTransferReceiveController::class, 'edit'])->name('material.transfer.edit');
Route::post('/material/MAT-004-receive-material-transfer/{code}/confirm', [MaterialTransferReceiveController::class, 'confirm'])->name('material.transfer.confirm');
Route::put('/material/MAT-004-receive-material-transfer/{code}', [MaterialTransferReceiveController::class, 'update'])->name('material.transfer.update');
Route::get('/material/MAT-004-receive-material-transfer/{code}', [MaterialTransferReceiveController::class, 'show'])->name('material.transfer.show');

Route::get('/material/MAT-003-withdraw-material/{code}/edit', [MaterialWithdrawController::class, 'edit'])->name('material.withdraw.edit');
Route::put('/material/MAT-003-withdraw-material/{code}', [MaterialWithdrawController::class, 'update'])->name('material.withdraw.update');
Route::get('/material/MAT-003-withdraw-material/{code}', [MaterialWithdrawController::class, 'show'])->name('material.withdraw.show');
Route::delete('/material/MAT-003-withdraw-material/{code}', [MaterialWithdrawController::class, 'destroy'])->name('material.withdraw.destroy');
Route::delete('/material/MAT-003-withdraw-material/{code}/items/{id}', [MaterialWithdrawController::class, 'destroyItem'])->name('material.withdraw.item.destroy');
Route::post('/material/MAT-003-withdraw-material/{code}/finalize', [MaterialWithdrawController::class, 'finalizeWithdraw'])->name('material.withdraw.finalize');



Route::get('/material/MAT-005-material-register', [MaterialRegisterController::class, 'index'])->name('material.register.index');




if (! function_exists('gujajob_find_material_balance_record')) {
    function gujajob_find_material_balance_record(string $materialCode): array
    {
        foreach (gujajob_material_balance_setting_records() as $record) {
            if ($record['material_code'] === $materialCode) {
                return $record;
            }
        }

        abort(404);
    }
}

if (! function_exists('gujajob_material_balance_lot_records')) {
    function gujajob_material_balance_lot_records(string $materialCode): array
    {
        return [
            [
                'receive_date' => '01/10/2568',
                'receive_no' => 'PR2567001',
                'receive_quantity' => 100,
                'unit_price' => '110.00',
                'balance_quantity' => 100,
            ],
            [
                'receive_date' => '05/03/2568',
                'receive_no' => 'PR2567002',
                'receive_quantity' => 50,
                'unit_price' => '115.00',
                'balance_quantity' => 20,
            ],
        ];
    }
}

Route::get('/material/MAT-006-record-balance-setting', [MaterialBalanceSettingController::class, 'index'])->name('material.balance.index');

Route::post('/material/MAT-006-record-balance-setting/bulk-update', [MaterialBalanceSettingController::class, 'bulkUpdate'])->name('material.balance.bulk-update');

Route::post('/material/MAT-006-record-balance-setting/{materialCode}/balance', [MaterialBalanceSettingController::class, 'updateBalance'])->name('material.balance.update');

Route::get('/material/MAT-006-record-balance-setting/{materialCode}/edit', [MaterialBalanceSettingController::class, 'edit'])->name('material.balance.edit');



Route::get('/material/MAT-007-print-material-report', [MaterialReportController::class, 'form'])->name('material.report.index');
Route::get('/material/MAT-007-print-material-report/organizations/search', [MaterialReportController::class, 'searchOrganizations'])->name('material.report.organizations.search');
Route::get('/material/MAT-007-print-material-report/sub-organizations/search', [MaterialReportController::class, 'searchSubOrganizations'])->name('material.report.sub-organizations.search');
Route::get('/material/MAT-007-print-material-report/report-stock', [MaterialReportController::class, 'index'])->name('material.report.stock');
Route::get('/material/MAT-007-print-material-report/report-stock/export', [MaterialReportController::class, 'export'])->name('material.report.stock.export');





Route::get('/asset/ASS-001-manage-asset-categories/create', [AssetCategoryController::class, 'create'])->name('asset.categories.create');
Route::post('/asset/ASS-001-manage-asset-categories', [AssetCategoryController::class, 'store'])->name('asset.categories.store');
Route::get('/asset/ASS-001-manage-asset-categories', [AssetCategoryController::class, 'index'])->name('asset.categories.index');
Route::get('/asset/ASS-001-manage-asset-categories/{id}', [AssetCategoryController::class, 'show'])->name('asset.categories.show');
Route::get('/asset/ASS-001-manage-asset-categories/{id}/edit', [AssetCategoryController::class, 'edit'])->name('asset.categories.edit');
Route::put('/asset/ASS-001-manage-asset-categories/{id}', [AssetCategoryController::class, 'update'])->name('asset.categories.update');
Route::delete('/asset/ASS-001-manage-asset-categories/{id}', [AssetCategoryController::class, 'destroy'])->name('asset.categories.destroy');




Route::get('/asset/ASS-002-manage-supplier-information', [DealerController::class, 'index'])->name('asset.suppliers.index');
Route::get('/asset/ASS-002-manage-supplier-information/create', [DealerController::class, 'create'])->name('asset.suppliers.create');
Route::post('/asset/ASS-002-manage-supplier-information', [DealerController::class, 'store'])->name('asset.suppliers.store');
Route::get('/asset/ASS-002-manage-supplier-information/{id}', [DealerController::class, 'show'])->name('asset.suppliers.show');
Route::get('/asset/ASS-002-manage-supplier-information/{id}/edit', [DealerController::class, 'edit'])->name('asset.suppliers.edit');
Route::put('/asset/ASS-002-manage-supplier-information/{id}', [DealerController::class, 'update'])->name('asset.suppliers.update');
Route::delete('/asset/ASS-002-manage-supplier-information/{id}', [DealerController::class, 'destroy'])->name('asset.suppliers.destroy');
Route::get('/api/amphurs', [DealerController::class, 'amphursByProvince'])->name('api.amphurs');
Route::get('/api/tambons', [DealerController::class, 'tambonsByAmphur'])->name('api.tambons');



Route::get('/asset/ASS-003-manage-asset-registration', [AssetController::class, 'index'])->name('asset.registrations.index');
Route::get('/asset/ASS-003-manage-asset-registration/create', [AssetController::class, 'create'])->name('asset.registrations.create');
Route::get('/asset/ASS-003-manage-asset-registration/forecast-data', [AssetController::class, 'forecastData'])->name('asset.registrations.forecast');
Route::post('/asset/ASS-003-manage-asset-registration/ai-forecast', [AssetController::class, 'aiForecastBudget'])->name('asset.registrations.ai-forecast');
Route::get('/asset/ASS-003-manage-asset-registration/forecast-print', [AssetController::class, 'forecastPrint'])->name('asset.registrations.forecast-print');
Route::get('/asset/ASS-003-manage-asset-registration/replacement-forecast', [AssetController::class, 'forecastPage'])->name('asset.registrations.replacement-forecast');
Route::post('/asset/ASS-003-manage-asset-registration', [AssetController::class, 'store'])->name('asset.registrations.store');
Route::get('/asset/ASS-003-manage-asset-registration/{id}', [AssetController::class, 'show'])->name('asset.registrations.show');
Route::get('/asset/ASS-003-manage-asset-registration/{id}/edit', [AssetController::class, 'edit'])->name('asset.registrations.edit');
Route::put('/asset/ASS-003-manage-asset-registration/{id}', [AssetController::class, 'update'])->name('asset.registrations.update');
Route::delete('/asset/ASS-003-manage-asset-registration/{id}', [AssetController::class, 'destroy'])->name('asset.registrations.destroy');

Route::get('/asset/ASS-004-assign-asset-to-department', [AssetAssignmentController::class, 'index'])->name('asset.assignments.index');
Route::get('/asset/ASS-004-assign-asset-to-department/create', [AssetAssignmentController::class, 'create'])->name('asset.assignments.create');
Route::post('/asset/ASS-004-assign-asset-to-department', [AssetAssignmentController::class, 'store'])->name('asset.assignments.store');
Route::get('/asset/ASS-004-assign-asset-to-department/{id}', [AssetAssignmentController::class, 'show'])->name('asset.assignments.show')->where('id', '[0-9]+');
Route::get('/asset/ASS-004-assign-asset-to-department/{id}/edit', [AssetAssignmentController::class, 'edit'])->name('asset.assignments.edit')->where('id', '[0-9]+');
Route::put('/asset/ASS-004-assign-asset-to-department/{id}', [AssetAssignmentController::class, 'update'])->name('asset.assignments.update')->where('id', '[0-9]+');
Route::delete('/asset/ASS-004-assign-asset-to-department/{id}', [AssetAssignmentController::class, 'cancel'])->name('asset.assignments.cancel')->where('id', '[0-9]+');
Route::get('/asset/ASS-004-assign-asset-to-department/assets/search', [AssetAssignmentController::class, 'searchAssets'])->name('asset.assignments.assets.search');

Route::get('/asset/ASS-006-request-asset-disposal', [AssetDisposalController::class, 'index'])->name('asset.disposals.index');
Route::get('/asset/ASS-006-request-asset-disposal/assets/search', [AssetDisposalController::class, 'searchAssets'])->name('asset.disposals.assets.search');
Route::get('/asset/ASS-006-request-asset-disposal/create', [AssetDisposalController::class, 'create'])->name('asset.disposals.create');
Route::post('/asset/ASS-006-request-asset-disposal', [AssetDisposalController::class, 'store'])->name('asset.disposals.store');
Route::get('/asset/ASS-006-request-asset-disposal/{id}', [AssetDisposalController::class, 'show'])->name('asset.disposals.show')->where('id', '[0-9]+');
Route::delete('/asset/ASS-006-request-asset-disposal/{id}', [AssetDisposalController::class, 'destroy'])->name('asset.disposals.destroy')->where('id', '[0-9]+');
Route::get('/asset/ASS-006-request-asset-disposal/{requestNo}/edit', [AssetDisposalController::class, 'edit'])->name('asset.disposals.edit');
Route::put('/asset/ASS-006-request-asset-disposal/{id}', [AssetDisposalController::class, 'update'])->name('asset.disposals.update')->where('id', '[0-9]+');
Route::get('/asset/ASS-007-approve-asset-disposal', [AssetDisposalController::class, 'approvalIndex'])->name('asset.disposals.approval.index');
Route::get('/asset/ASS-007-approve-asset-disposal/{id}/consider', [AssetDisposalController::class, 'approvalConsider'])->name('asset.disposals.approval.consider')->where('id', '[0-9]+');
Route::get('/asset/ASS-007-approve-asset-disposal/{id}/edit', [AssetDisposalController::class, 'approvalEdit'])->name('asset.disposals.approval.edit')->where('id', '[0-9]+');
Route::put('/asset/ASS-007-approve-asset-disposal/{id}', [AssetDisposalController::class, 'approvalUpdate'])->name('asset.disposals.approval.update')->where('id', '[0-9]+');
Route::get('/asset/ASS-007-approve-asset-disposal/{id}', [AssetDisposalController::class, 'approvalShow'])->name('asset.disposals.approval.show')->where('id', '[0-9]+');
Route::post('/asset/ASS-007-approve-asset-disposal/{id}/approve', [AssetDisposalController::class, 'approve'])->name('asset.disposals.approval.approve')->where('id', '[0-9]+');
Route::post('/asset/ASS-007-approve-asset-disposal/{id}/reject', [AssetDisposalController::class, 'reject'])->name('asset.disposals.approval.reject')->where('id', '[0-9]+');
Route::get('/asset/ASS-008-record-asset-disposal-result', [AssetDisposalController::class, 'resultIndex'])->name('asset.disposals.results.index');
Route::get('/asset/ASS-008-record-asset-disposal-result/{id}/create', [AssetDisposalController::class, 'resultCreate'])->name('asset.disposals.results.create')->where('id', '[0-9]+');
Route::post('/asset/ASS-008-record-asset-disposal-result/{id}', [AssetDisposalController::class, 'resultStore'])->name('asset.disposals.results.store')->where('id', '[0-9]+');
Route::get('/asset/ASS-008-record-asset-disposal-result/{id}', [AssetDisposalController::class, 'resultShow'])->name('asset.disposals.results.show')->where('id', '[0-9]+');

Route::get('/asset/ASS-005-receive-department-registered-asset', [AssetDepartmentReceivingController::class, 'index'])->name('asset.department-receiving.index');
Route::get('/asset/ASS-005-receive-department-registered-asset/{id}/receive', [AssetDepartmentReceivingController::class, 'receive'])->name('asset.department-receiving.receive')->where('id', '[0-9]+');
Route::get('/asset/ASS-005-receive-department-registered-asset/{id}/edit', [AssetDepartmentReceivingController::class, 'edit'])->name('asset.department-receiving.edit')->where('id', '[0-9]+');
Route::post('/asset/ASS-005-receive-department-registered-asset/{id}', [AssetDepartmentReceivingController::class, 'store'])->name('asset.department-receiving.store')->where('id', '[0-9]+');
Route::put('/asset/ASS-005-receive-department-registered-asset/{id}', [AssetDepartmentReceivingController::class, 'update'])->name('asset.department-receiving.update')->where('id', '[0-9]+');
Route::get('/asset/ASS-005-receive-department-registered-asset/{id}', [AssetDepartmentReceivingController::class, 'show'])->name('asset.department-receiving.show')->where('id', '[0-9]+');

// ASS-009: Print Asset Report
Route::get('/asset/ASS-009-print-asset-report', [AssetReportController::class, 'index'])->name('asset.reports.index');
Route::get('/api/asset/categories/search', [AssetReportController::class, 'searchCategories'])->name('asset.api.categories.search');
Route::get('/api/asset/search', [AssetReportController::class, 'searchAssets'])->name('asset.api.assets.search');
Route::get('/api/asset/organizations/search', [AssetReportController::class, 'searchOrganizations'])->name('asset.api.organizations.search');
Route::get('/api/asset/sub-organizations/search', [AssetReportController::class, 'searchSubOrganizations'])->name('asset.api.sub-organizations.search');
Route::get('/asset/ASS-009-print-asset-report/report-register', [AssetReportController::class, 'reportAssetRegister'])->name('asset.reports.register');
Route::get('/asset/ASS-009-print-asset-report/report-register/export', [AssetReportController::class, 'exportAssetRegister'])->name('asset.reports.register.export');
Route::get('/asset/ASS-009-print-asset-report/report-register/download', [AssetReportController::class, 'downloadAssetRegister'])->name('asset.reports.register.download');
Route::get('/asset/ASS-009-print-asset-report/report-ledger', [AssetReportController::class, 'reportAssetLedger'])->name('asset.reports.ledger');
Route::get('/asset/ASS-009-print-asset-report/report-ledger/export', [AssetReportController::class, 'exportAssetLedger'])->name('asset.reports.ledger.export');
Route::get('/asset/ASS-009-print-asset-report/report-ledger/download', [AssetReportController::class, 'downloadAssetLedger'])->name('asset.reports.ledger.download');

}); // end Route::middleware('auth')
