<?php

namespace App\Http\Controllers;

use App\Models\MaterialWithdrawn;
use App\Models\MaterialWithdrawnList;
use App\Services\MaterialInventoryService;
use App\Services\OrganizationVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class MaterialWithdrawApprovalController extends Controller
{
    public function __construct(
        private readonly MaterialInventoryService $inventory,
        private readonly OrganizationVisibilityService $orgVisibility,
    ) {}

    // =========================================================
    //  1. List – show all withdrawal requests with search
    // =========================================================

    public function index(Request $request): View
    {
        $searchBy  = $request->string('search_by', 'withdraw_no')->value();
        $keyword   = trim($request->string('keyword')->value());
        $status    = $request->string('status', '')->value();
        $sort      = $request->string('sort', '')->value();
        $direction = strtolower($request->string('direction', 'asc')->value()) === 'desc' ? 'desc' : 'asc';

        $sortableColumns = [
            'wd_code'       => 'mat_wd_code',
            'wd_date'       => 'mat_wd_date',
            'requester'     => 'mat_wd_person',
            'withdraw_type' => 'withdraw_type',
            'status'        => 'status',
        ];

        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $query = MaterialWithdrawn::query()
            ->with(['organization', 'updatedByUser.organization'])
            ->whereIn('org_id', $visibleOrgIds)
            ->where('status', '!=', MaterialWithdrawn::STATUS_DRAFT);

        if (array_key_exists($sort, $sortableColumns)) {
            $query->orderBy($sortableColumns[$sort], $direction)->orderBy('id', $direction);
        } else {
            $query->orderByRaw('UPPER(mat_wd_code) ASC')->orderBy('id', 'ASC');
        }

        if ($keyword !== '') {
            match ($searchBy) {
                'requester' => $query->whereRaw(
                    'UPPER(mat_wd_person) LIKE ?',
                    ['%' . mb_strtoupper($keyword) . '%']
                ),
                'department' => $query->whereHas(
                    'organization',
                    fn ($q) => $q->whereRaw('UPPER(org_name) LIKE ?', ['%' . mb_strtoupper($keyword) . '%'])
                ),
                default => $query->whereRaw(
                    'UPPER(mat_wd_code) LIKE ?',
                    ['%' . mb_strtoupper($keyword) . '%']
                ),
            };
        }

        if ($status !== '') {
            $query->where('status', (int) $status);
        }

        return view('material.MAT-003-withdraw-material.approvals.index', [
            'pageTitle'         => 'เบิกวัสดุ',
            'withdrawalRecords' => $query->paginate(10)->withQueryString(),
            'searchBy'          => $searchBy,
            'keyword'           => $keyword,
            'statusFilter'      => $status,
            'sort'              => $sort,
            'direction'         => $direction,
        ]);
    }

    // =========================================================
    //  2. Show – detail page for review / approval
    // =========================================================

    public function show(string $code): View
    {
        $record = $this->findByCode($code);

        // ─── Batch-fetch current balances from the balance service ───────────
        $matIds = $record->details
            ->pluck('mat_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $balances = empty($matIds) ? [] : $this->sourceAvailable($record, $matIds);

        // Fill 0 for materials with no transaction history (no procurement yet)
        foreach ($matIds as $matId) {
            if (! array_key_exists($matId, $balances)) {
                $balances[$matId] = 0;
            }
        }

        return view('material.MAT-003-withdraw-material.approvals.show', [
            'pageTitle'    => 'เบิกวัสดุ',
            'record'       => $record,
            'inventoryMap' => $balances,
            'canEdit'      => (int) $record->status === MaterialWithdrawn::STATUS_APPROVED,
        ]);
    }

    // =========================================================
    //  2b. Edit – allowed only when status = STATUS_APPROVED
    //  Stock moves only when the transfer is received (MAT-004); editing qty
    //  here is restricted to non-qty fields.
    // =========================================================

    public function edit(string $code): View|RedirectResponse
    {
        $record = $this->findByCode($code);

        if ((int) $record->status !== MaterialWithdrawn::STATUS_APPROVED) {
            return redirect()
                ->route('material.withdraw.approval.show', $code)
                ->with('error', 'สามารถแก้ไขได้เฉพาะเอกสารที่อนุมัติแล้วเท่านั้น');
        }

        return view('material.MAT-003-withdraw-material.approvals.show', [
            'pageTitle'    => 'เบิกวัสดุ (แก้ไขข้อมูลทั่วไป)',
            'record'       => $record,
            'inventoryMap' => [],
            'canEdit'      => true,
            'editMode'     => true,
        ]);
    }

    // =========================================================
    //  3. Approve – update wd_amount; stock moves on receipt in MAT-004
    // =========================================================

    public function approve(Request $request, string $code): RedirectResponse
    {
        $details = $request->input('details', []);

        if (! is_array($details) || count($details) === 0) {
            return back()->with('error', 'กรุณาระบุจำนวนที่อนุมัติ');
        }

        // Pre-validate numeric values before entering transaction
        foreach ($details as $rawId => $data) {
            $qty = $data['approved_quantity'] ?? '';
            if (! is_numeric($qty) || (int) $qty < 0) {
                return back()
                    ->withInput()
                    ->with('error', 'กรุณาระบุจำนวนที่อนุมัติให้ถูกต้อง');
            }
        }

        // Check at least one qty > 0
        $hasPositive = false;

        foreach ($details as $data) {
            if ((int) ($data['approved_quantity'] ?? 0) > 0) {
                $hasPositive = true;
                break;
            }
        }

        if (! $hasPositive) {
            return back()
                ->withInput()
                ->with('error', 'กรุณาระบุจำนวนที่อนุมัติอย่างน้อย 1 รายการ');
        }

        try {
            DB::connection('oracle')->transaction(function () use ($code, $details): void {
                $userId = (int) Auth::id();
                $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

                // ─── Lock header — scoped to current user's visible orgs ──────
                $record = MaterialWithdrawn::query()
                    ->where('mat_wd_code', $code)
                    ->whereIn('org_id', $visibleOrgIds)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $record->status !== MaterialWithdrawn::STATUS_PENDING) {
                    throw new \RuntimeException('รายการนี้ได้รับการดำเนินการแล้ว');
                }

                // ─── Load details belonging to this header only ───────────────
                $existingDetails = MaterialWithdrawnList::query()
                    ->with('material')
                    ->where('mat_wd_id', $record->id)
                    ->get();

                $existingDetailIds = $existingDetails
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $available = $this->sourceAvailable($record, $existingDetails->pluck('mat_id')->map(fn ($id) => (int) $id)->all());

                foreach ($details as $rawDetailId => $data) {
                    $detailId    = (int) $rawDetailId;
                    $approvedQty = (int) ($data['approved_quantity'] ?? 0);

                    // Security: reject any detail ID that doesn't belong to this header
                    if (! in_array($detailId, $existingDetailIds, true)) {
                        throw new \RuntimeException("รายการวัสดุ #{$detailId} ไม่ได้อยู่ในใบเบิกนี้");
                    }

                    if ($approvedQty < 0) {
                        throw new \RuntimeException('จำนวนที่อนุมัติต้องไม่น้อยกว่า 0');
                    }

                    $detail = $existingDetails->first(fn ($d) => (int) $d->id === $detailId);
                    $matId  = (int) $detail->mat_id;

                    // ─── Check the source store; stock moves only when the transfer is received (MAT-004) ─
                    if ($approvedQty > 0 && $approvedQty > ($available[$matId] ?? 0)) {
                        throw new \RuntimeException(
                            "ไม่สามารถอนุมัติรายการได้ " .
                            "เนื่องจากจำนวนคงเหลือในคลังของหน่วยงานต้นทางไม่เพียงพอ " .
                            "(คงเหลือ: " . number_format($available[$matId] ?? 0) . ") กรุณาตรวจสอบอีกครั้ง"
                        );
                    }

                    // ─── Update approved quantity (overwrites wd_amount) ──────
                    MaterialWithdrawnList::query()
                        ->where('id', $detailId)
                        ->where('mat_wd_id', $record->id)
                        ->update([
                            'wd_amount'  => $approvedQty,
                            'updated_by' => $userId,
                        ]);
                }

                // ─── Approve header ───────────────────────────────────────────
                $record->update([
                    'status'     => MaterialWithdrawn::STATUS_APPROVED,
                    'updated_by' => $userId,
                ]);
            });

            return redirect()
                ->route('material.withdraw.approval.index')
                ->with('success', 'อนุมัติคำขอเบิกวัสดุเรียบร้อยแล้ว');
        } catch (\RuntimeException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('MAT-003 approve failed', ['code' => $code, 'error' => $e->getMessage()]);

            return back()
                ->withInput()
                ->with('error', 'ไม่สามารถดำเนินการอนุมัติได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    // =========================================================
    //  4. Validate Stock – pre-check before approve popup (JSON)
    // =========================================================

    public function validateStock(Request $request, string $code): JsonResponse
    {
        try {
            $header = MaterialWithdrawn::query()
                ->where('mat_wd_code', $code)
                ->firstOrFail();

            if ((int) $header->status !== MaterialWithdrawn::STATUS_PENDING) {
                return response()->json([
                    'valid' => false,
                    'error' => 'รายการนี้ได้รับการดำเนินการแล้ว',
                ], 422);
            }

            $existingDetails = MaterialWithdrawnList::query()
                ->with('material')
                ->where('mat_wd_id', $header->id)
                ->get();

            $existingDetailIds = $existingDetails
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            // ─── Batch-fetch current balances from the balance service ───────
            $matIds = $existingDetails
                ->pluck('mat_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $balances = empty($matIds)
                ? []
                : $this->sourceAvailable($header, $matIds);

            $details = $request->input('details', []);

            if (! is_array($details) || count($details) === 0) {
                return response()->json([
                    'valid' => false,
                    'error' => 'กรุณาระบุจำนวนที่อนุมัติ',
                ], 422);
            }

            $overItems = [];

            foreach ($details as $rawDetailId => $data) {
                $detailId    = (int) $rawDetailId;
                $qtyRaw      = $data['approved_quantity'] ?? '';
                $approvedQty = is_numeric($qtyRaw) ? (int) $qtyRaw : null;

                // Skip unknown detail IDs (security: only process details belonging to this header)
                if (! in_array($detailId, $existingDetailIds, true)) {
                    continue;
                }

                // Skip null / negative values — basic validation is handled client-side
                if ($approvedQty === null || $approvedQty < 0) {
                    continue;
                }

                $detail    = $existingDetails->first(fn ($d) => (int) $d->id === $detailId);
                $matId     = (int) $detail->mat_id;
                $available = $balances[$matId] ?? 0;

                if ($approvedQty > $available) {
                    $overItems[] = [
                        'detail_id'                => $detailId,
                        'available_quantity'       => $available,
                        'remaining_after_approval' => $available - $approvedQty,
                        'message'                  => 'จำนวนที่เบิกเกินจำนวนคงเหลือในคลัง (คงเหลือ: ' . number_format($available) . ')',
                    ];
                }
            }

            return response()->json([
                'valid' => count($overItems) === 0,
                'items' => $overItems,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['valid' => false, 'error' => 'ไม่พบรายการที่ต้องการ'], 404);
        } catch (Throwable $e) {
            Log::error('MAT-003 validate-stock failed', ['code' => $code, 'error' => $e->getMessage()]);

            return response()->json(['valid' => false, 'error' => 'ไม่สามารถตรวจสอบจำนวนคงเหลือได้ กรุณาลองใหม่'], 500);
        }
    }

    // =========================================================
    //  5. Reject – status change only, no stock deduction
    // =========================================================

    public function reject(string $code): RedirectResponse
    {
        try {
            DB::connection('oracle')->transaction(function () use ($code): void {
                $userId = (int) Auth::id();
                $currentOrgId = (int) Auth::user()->org_id;

                $record = MaterialWithdrawn::query()
                    ->where('mat_wd_code', $code)
                    ->where('org_id', $currentOrgId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $record->status !== MaterialWithdrawn::STATUS_PENDING) {
                    throw new \RuntimeException('รายการนี้ได้รับการดำเนินการแล้ว');
                }

                // Change status only – no stock deduction, no ledger entry
                $record->update([
                    'status'     => MaterialWithdrawn::STATUS_REJECTED,
                    'updated_by' => $userId,
                ]);
            });

            return redirect()
                ->route('material.withdraw.approval.index')
                ->with('success', 'ไม่อนุมัติคำขอเบิกวัสดุเรียบร้อยแล้ว');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('MAT-003 reject failed', ['code' => $code, 'error' => $e->getMessage()]);

            return back()->with('error', 'ไม่สามารถดำเนินการได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    // =========================================================
    //  Private helpers
    // =========================================================

    /**
     * Stock still available in the store this withdrawal draws on (the requester's parent for LARGE_LOT, the
     * requester for INTERNAL_USE), not counting this withdrawal's own reservation.
     *
     * @return array<int, float>
     */
    private function sourceAvailable(MaterialWithdrawn $record, array $matIds): array
    {
        $sourceOrgId = $this->inventory->sourceOrgId($record->org_id !== null ? (int) $record->org_id : null, $record->withdraw_type ?? 'LARGE_LOT');

        return $sourceOrgId !== null ? $this->inventory->available($sourceOrgId, $matIds, (int) $record->id) : array_fill_keys($matIds, 0.0);
    }

    private function findByCode(string $code): MaterialWithdrawn
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        return MaterialWithdrawn::query()
            ->with(['organization', 'details.material', 'updatedByUser.organization'])
            ->where('mat_wd_code', $code)
            ->whereIn('org_id', $visibleOrgIds)
            ->firstOrFail();
    }
}
