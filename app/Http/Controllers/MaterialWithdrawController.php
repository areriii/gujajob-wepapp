<?php

namespace App\Http\Controllers;

use App\Models\GlbOrganization;
use App\Models\Material;
use App\Models\MaterialWithdrawn;
use App\Models\MaterialWithdrawnList;
use App\Services\MaterialInventoryService;
use App\Services\MaterialWithdrawRecommendationService;
use App\Services\OrganizationVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class MaterialWithdrawController extends Controller
{
    public function __construct(
        private readonly MaterialInventoryService $inventory,
        private readonly OrganizationVisibilityService $orgVisibility,
    ) {}

    // =========================================================
    //  Public routes
    // =========================================================

    public function index(Request $request): View
    {
        $searchBy  = $request->string('search_by', 'withdraw_no')->value();
        $keyword   = trim($request->string('keyword')->value());
        $status    = $request->string('status', '')->value();
        $sort      = $request->string('sort', '')->value();
        $direction = strtolower($request->string('direction', 'asc')->value()) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['mat_wd_code', 'mat_wd_date', 'mat_wd_person', 'status', 'withdraw_type'];

        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $query = MaterialWithdrawn::query()
            ->whereIn('org_id', $visibleOrgIds)
            ->with('organization')
            ->withCount('details as item_count');

        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $direction)->orderBy('id', 'asc');
        } else {
            $query->orderByRaw('UPPER(mat_wd_code) ASC')->orderBy('id', 'ASC');
        }

        if ($keyword !== '') {
            match ($searchBy) {
                'withdraw_date' => $query->whereRaw(
                    "TO_CHAR(mat_wd_date, 'DD/MM/YYYY') LIKE ?",
                    ['%' . $keyword . '%']
                ),
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

        return view('material.MAT-003-withdraw-material.index', [
            'pageTitle'         => 'เบิกวัสดุ',
            'withdrawalRecords' => $query->paginate(10)->withQueryString(),
            'searchBy'          => $searchBy,
            'keyword'           => $keyword,
            'statusFilter'      => $status,
            'sort'              => $sort,
            'direction'         => $direction,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $withdrawMode  = $request->string('withdraw_mode')->value();
        $allowedModes  = ['LARGE_LOT', 'INTERNAL_USE'];

        if (! in_array($withdrawMode, $allowedModes, true)) {
            return redirect()
                ->route('material.withdraw.index')
                ->with('error', 'กรุณาเลือกรูปแบบการเบิกจากเมนู');
        }

        $pageTitle = match ($withdrawMode) {
            'LARGE_LOT'    => 'บันทึกข้อมูลการเบิกวัสดุ(จากหน่วยงานต้นทาง)',
            'INTERNAL_USE' => 'บันทึกข้อมูลการเบิกวัสดุ(ภายในหน่วยงาน)',
        };

        $user = Auth::user();
        $defaultPersonName = $user->user_name ?? '';

        return view('material.MAT-003-withdraw-material.create', [
            'pageTitle'         => $pageTitle,
            'defaultOrg'        => $this->currentUserOrg(),
            'defaultPersonName' => $defaultPersonName,
            'withdrawMode'      => $withdrawMode,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'mat_wd_date'     => ['required', 'date'],
                'mat_wd_person'   => ['required', 'string', 'max:150'],
                'items_json'      => ['required', 'string'],
                'withdraw_action' => ['required', 'string', 'in:LARGE_LOT,INTERNAL_USE'],
            ], [
                'mat_wd_date.required'     => 'กรุณาระบุวันที่เบิก',
                'mat_wd_person.required'   => 'กรุณากรอกชื่อผู้เบิกวัสดุ',
                'items_json.required'      => 'กรุณาเพิ่มรายการวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ',
                'withdraw_action.required' => 'กรุณาเลือกประเภทการเบิก',
                'withdraw_action.in'       => 'ประเภทการเบิกไม่ถูกต้อง',
            ]);

            $rawItems = json_decode($validated['items_json'], true);
            if (! is_array($rawItems) || count($rawItems) === 0) {
                return back()->withInput()
                    ->with('error', 'กรุณาเพิ่มรายการวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ');
            }

            $isInternalUse = $validated['withdraw_action'] === 'INTERNAL_USE';
            $org           = $this->currentUserOrg();

            // For INTERNAL_USE, pre-check stock before transaction
            if ($isInternalUse) {
                $matIds   = collect($rawItems)->pluck('mat_id')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
                $balances = $org ? $this->inventory->available((int) $org->org_id, $matIds) : [];

                foreach ($rawItems as $item) {
                    $matId  = (int) ($item['mat_id'] ?? 0);
                    $amount = (int) ($item['amount']  ?? 0);
                    if ($matId <= 0 || $amount <= 0) {
                        continue;
                    }
                    $currentBalance = $balances[$matId] ?? 0;
                    if ($amount > $currentBalance) {
                        $codeStr = $item['code'] ?? 'รหัสไม่ระบุ';
                        return back()->withInput()
                            ->with('error', "วัสดุ {$codeStr}: จำนวนที่เบิก ({$amount}) เกินจำนวนคงเหลือ ({$currentBalance})");
                    }
                }
            }

            $userId = (int) Auth::id();

            DB::connection('oracle')->transaction(function () use ($validated, $rawItems, $org, $isInternalUse, $userId) {
                $date     = Carbon::parse($validated['mat_wd_date']);
                $code     = $this->generateWithdrawCode($date);
                $headerId = $this->nextWithdrawnId();
                $orgId    = $org ? (int) $org->org_id : null;
                $newStatus = $isInternalUse ? MaterialWithdrawn::STATUS_APPROVED : MaterialWithdrawn::STATUS_PENDING;

                MaterialWithdrawn::create([
                    'id'            => $headerId,
                    'mat_wd_code'   => $code,
                    'mat_wd_date'   => $validated['mat_wd_date'],
                    'org_id'        => $orgId,
                    'mat_wd_person' => trim($validated['mat_wd_person']),
                    'status'        => $newStatus,
                    'withdraw_type' => $validated['withdraw_action'],
                    'created_by'    => $userId,
                    'updated_by'    => $userId,
                ]);

                $seenMatIds = [];
                foreach ($rawItems as $item) {
                    $matId  = (int) ($item['mat_id'] ?? 0);
                    $amount = (int) ($item['amount']  ?? 0);
                    if ($matId <= 0 || $amount <= 0 || isset($seenMatIds[$matId])) {
                        continue;
                    }
                    if (! Material::query()->where('id', $matId)->exists()) {
                        continue;
                    }
                    $seenMatIds[$matId] = true;
                    MaterialWithdrawnList::create([
                        'id'         => $this->nextWithdrawnListId(),
                        'mat_wd_id'  => $headerId,
                        'mat_id'     => $matId,
                        'wd_amount'  => $amount,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                }
            });

            return redirect()
                ->route('material.withdraw.index')
                ->with('success', 'บันทึกข้อมูลใบเบิกวัสดุเรียบร้อยแล้ว');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('MAT-003 store failed', ['error' => $e->getMessage()]);

            return back()->withInput()
                ->with('error', 'ไม่สามารถบันทึกข้อมูลใบเบิกได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    public function show(string $code): View
    {
        $record = $this->findByCode($code);

        return view('material.MAT-003-withdraw-material.show', [
            'pageTitle' => 'เบิกวัสดุ',
            'record'    => $record,
        ]);
    }

    public function edit(string $code): View|RedirectResponse
    {
        $record = $this->findByCode($code);

        $editableStatuses = [MaterialWithdrawn::STATUS_PENDING];
        if (! in_array((int) $record->status, $editableStatuses, true)) {
            return redirect()
                ->route('material.withdraw.show', $code)
                ->with('error', 'ไม่สามารถแก้ไขใบเบิกที่ผ่านกระบวนการแล้วได้');
        }

        return view('material.MAT-003-withdraw-material.edit', [
            'pageTitle' => 'เบิกวัสดุ',
            'record'    => $record,
            'isDraft'   => false,
        ]);
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        $record = $this->findByCode($code);

        if ((int) $record->status !== MaterialWithdrawn::STATUS_PENDING) {
            return redirect()
                ->route('material.withdraw.show', $code)
                ->with('error', 'ไม่สามารถแก้ไขใบเบิกที่อนุมัติหรือไม่ผ่านการอนุมัติแล้วได้');
        }

        try {
            $validated = $request->validate([
                'mat_wd_date'   => ['required', 'date'],
                'mat_wd_person' => ['required', 'string', 'max:150'],
                'items_json'    => ['required', 'string'],
            ], [
                'mat_wd_date.required'   => 'กรุณาระบุวันที่เบิก',
                'mat_wd_person.required' => 'กรุณากรอกชื่อผู้เบิกวัสดุ',
                'items_json.required'    => 'กรุณาเพิ่มรายการวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ',
            ]);

            $rawItems = json_decode($validated['items_json'], true);

            if (! is_array($rawItems) || count($rawItems) === 0) {
                return back()->withInput()
                    ->with('error', 'กรุณาเพิ่มรายการวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ');
            }

            DB::connection('oracle')->transaction(function () use ($record, $validated, $rawItems): void {
                $userId = (int) Auth::id();

                // ─── 1. Update header ────────────────────────────────────────
                $record->update([
                    'mat_wd_date'   => $validated['mat_wd_date'],
                    'mat_wd_person' => trim($validated['mat_wd_person']),
                    'updated_by'    => $userId,
                ]);

                // ─── 2. Fetch existing detail IDs belonging to this header ───
                $existingIds = MaterialWithdrawnList::query()
                    ->where('mat_wd_id', $record->id)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                // ─── Batch-fetch current balances from the balance service ────
                $matIdsToCheck = collect($rawItems)
                    ->pluck('mat_id')
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                $sourceOrgId = $this->inventory->sourceOrgId($record->org_id !== null ? (int) $record->org_id : null, $record->withdraw_type ?? 'LARGE_LOT');
                $balances = $sourceOrgId !== null ? $this->inventory->available($sourceOrgId, $matIdsToCheck, (int) $record->id) : [];

                $submittedDetailIds = [];
                $seenMatIds         = [];

                foreach ($rawItems as $item) {
                    $detailId = (int) ($item['detail_id'] ?? 0);
                    $matId    = (int) ($item['mat_id']    ?? 0);
                    $amount   = (int) ($item['amount']    ?? 0);

                    if ($matId <= 0 || $amount <= 0) {
                        continue;
                    }

                    if (isset($seenMatIds[$matId])) {
                        continue;
                    }

                    if (! Material::query()->where('id', $matId)->exists()) {
                        continue;
                    }

                    // ─── Stock guard: re-check balance from Oracle ────────────
                    $currentBalance = $balances[$matId] ?? 0;

                    if ($amount > $currentBalance) {
                        $codeStr = $item['code'] ?? 'รหัสไม่ระบุ';
                        throw ValidationException::withMessages([
                            'items_json' => "วัสดุ {$codeStr}: จำนวนที่เบิก ({$amount}) เกินจำนวนคงเหลือ ({$currentBalance})",
                        ]);
                    }

                    $seenMatIds[$matId] = true;

                    if ($detailId > 0 && in_array($detailId, $existingIds, true)) {
                        // Update existing detail row
                        MaterialWithdrawnList::query()
                            ->where('id', $detailId)
                            ->where('mat_wd_id', $record->id)
                            ->update([
                                'wd_amount'  => $amount,
                                'updated_by' => $userId,
                            ]);
                        $submittedDetailIds[] = $detailId;
                    } else {
                        // Insert new detail row
                        MaterialWithdrawnList::create([
                            'id'         => $this->nextWithdrawnListId(),
                            'mat_wd_id'  => $record->id,
                            'mat_id'     => $matId,
                            'wd_amount'  => $amount,
                            'created_by' => $userId,
                            'updated_by' => $userId,
                        ]);
                    }
                }

                // ─── 3. Delete detail rows removed by the user ───────────────
                $toDelete = array_diff($existingIds, $submittedDetailIds);

                if (! empty($toDelete)) {
                    MaterialWithdrawnList::query()
                        ->where('mat_wd_id', $record->id)
                        ->whereIn('id', $toDelete)
                        ->delete();
                }
            });

            return redirect()
                ->route('material.withdraw.show', $code)
                ->with('success', 'แก้ไขข้อมูลใบเบิกวัสดุเรียบร้อยแล้ว');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('MAT-003 update failed', ['code' => $code, 'error' => $e->getMessage()]);

            return back()->withInput()
                ->with('error', 'ไม่สามารถแก้ไขข้อมูลใบเบิกวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    public function destroy(string $code): RedirectResponse
    {
        try {
            $currentOrgId = (int) Auth::user()->org_id;
            $record = MaterialWithdrawn::query()
                ->where('mat_wd_code', $code)
                ->where('org_id', $currentOrgId)
                ->firstOrFail();

            $deletable = [MaterialWithdrawn::STATUS_PENDING];
            if (! in_array((int) $record->status, $deletable, true)) {
                return redirect()
                    ->route('material.withdraw.show', $code)
                    ->with('error', 'ไม่สามารถลบใบเบิกที่อนุมัติหรือไม่ผ่านการอนุมัติแล้วได้');
            }

            DB::connection('oracle')->transaction(function () use ($record) {
                // Delete detail rows first to satisfy any FK constraint
                MaterialWithdrawnList::query()
                    ->where('mat_wd_id', $record->id)
                    ->delete();

                // Delete the header
                $record->delete();
            });

            return redirect()
                ->route('material.withdraw.index')
                ->with('success', 'ลบข้อมูลใบเบิกวัสดุเรียบร้อยแล้ว');
        } catch (Throwable $e) {
            Log::error('MAT-003 destroy failed', [
                'code'  => $code,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('material.withdraw.show', $code)
                ->with('error', 'ไม่สามารถลบข้อมูลใบเบิกวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    /**
     * Finalize a Draft withdrawal: save items, set withdraw_type, advance status.
     * Requires WITHDRAW_TYPE column in Oracle (run mat003_withdraw_type_add.sql first).
     */
    public function finalizeWithdraw(Request $request, string $code): RedirectResponse
    {
        $record = $this->findByCode($code);

        if ((int) $record->status !== MaterialWithdrawn::STATUS_DRAFT) {
            return redirect()
                ->route('material.withdraw.show', $code)
                ->with('error', 'รายการนี้ได้รับการบันทึกประเภทการเบิกแล้ว ไม่สามารถดำเนินการซ้ำได้');
        }

        try {
            $validated = $request->validate([
                'mat_wd_date'     => ['required', 'date'],
                'mat_wd_person'   => ['required', 'string', 'max:150'],
                'items_json'      => ['required', 'string'],
                'withdraw_action' => ['required', 'string', 'in:LARGE_LOT,INTERNAL_USE'],
            ], [
                'mat_wd_date.required'     => 'กรุณาระบุวันที่เบิก',
                'mat_wd_person.required'   => 'กรุณากรอกชื่อผู้เบิกวัสดุ',
                'items_json.required'      => 'กรุณาเพิ่มรายการวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ',
                'withdraw_action.required' => 'กรุณาเลือกประเภทการเบิก',
            ]);

            $rawItems = json_decode($validated['items_json'], true);
            if (! is_array($rawItems) || count($rawItems) === 0) {
                return back()->withInput()
                    ->with('error', 'กรุณาเพิ่มรายการวัสดุที่ต้องการเบิกอย่างน้อย 1 รายการ');
            }

            DB::connection('oracle')->transaction(function () use ($record, $validated, $rawItems) {
                $userId = (int) Auth::id();

                // Lock to prevent double-finalize
                $locked = MaterialWithdrawn::query()
                    ->where('id', $record->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || (int) $locked->status !== MaterialWithdrawn::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'withdraw_action' => 'รายการนี้ได้รับการบันทึกประเภทการเบิกแล้ว',
                    ]);
                }

                // Update header fields
                $locked->update([
                    'mat_wd_date'   => $validated['mat_wd_date'],
                    'mat_wd_person' => trim($validated['mat_wd_person']),
                    'updated_by'    => $userId,
                ]);

                // Batch-fetch balances (only needed for INTERNAL_USE auto-approve)
                $matIdsToCheck = collect($rawItems)
                    ->pluck('mat_id')
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)
                    ->unique()->values()->all();

                $balances = $validated['withdraw_action'] === 'INTERNAL_USE' && $locked->org_id !== null
                    ? $this->inventory->available((int) $locked->org_id, $matIdsToCheck)
                    : [];

                // Rebuild details
                $existingIds = MaterialWithdrawnList::query()
                    ->where('mat_wd_id', $locked->id)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $submittedDetailIds = [];
                $seenMatIds = [];

                foreach ($rawItems as $item) {
                    $detailId = (int) ($item['detail_id'] ?? 0);
                    $matId    = (int) ($item['mat_id']    ?? 0);
                    $amount   = (int) ($item['amount']    ?? 0);

                    if ($matId <= 0 || $amount <= 0 || isset($seenMatIds[$matId])) {
                        continue;
                    }
                    if (! Material::query()->where('id', $matId)->exists()) {
                        continue;
                    }

                    if ($validated['withdraw_action'] === 'INTERNAL_USE') {
                        $currentBalance = $balances[$matId] ?? 0;
                        if ($amount > $currentBalance) {
                            $codeStr = $item['code'] ?? 'รหัสไม่ระบุ';
                            throw ValidationException::withMessages([
                                'items_json' => "วัสดุ {$codeStr}: จำนวนที่เบิก ({$amount}) เกินจำนวนคงเหลือ ({$currentBalance})",
                            ]);
                        }
                    }

                    $seenMatIds[$matId] = true;

                    if ($detailId > 0 && in_array($detailId, $existingIds, true)) {
                        MaterialWithdrawnList::query()
                            ->where('id', $detailId)
                            ->where('mat_wd_id', $locked->id)
                            ->update(['wd_amount' => $amount, 'updated_by' => $userId]);
                        $submittedDetailIds[] = $detailId;
                    } else {
                        MaterialWithdrawnList::create([
                            'id'         => $this->nextWithdrawnListId(),
                            'mat_wd_id'  => $locked->id,
                            'mat_id'     => $matId,
                            'wd_amount'  => $amount,
                            'created_by' => $userId,
                            'updated_by' => $userId,
                        ]);
                    }
                }

                $toDelete = array_diff($existingIds, $submittedDetailIds);
                if (! empty($toDelete)) {
                    MaterialWithdrawnList::query()
                        ->where('mat_wd_id', $locked->id)
                        ->whereIn('id', $toDelete)
                        ->delete();
                }

                $newStatus = $validated['withdraw_action'] === 'INTERNAL_USE'
                    ? MaterialWithdrawn::STATUS_APPROVED
                    : MaterialWithdrawn::STATUS_PENDING;

                $locked->update([
                    'withdraw_type' => $validated['withdraw_action'],
                    'status'        => $newStatus,
                    'updated_by'    => $userId,
                ]);
            });

            return redirect()
                ->route('material.withdraw.index')
                ->with('success', 'บันทึกข้อมูลใบเบิกวัสดุเรียบร้อยแล้ว');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('MAT-003 finalizeWithdraw failed', ['code' => $code, 'error' => $e->getMessage()]);

            return back()->withInput()
                ->with('error', 'ไม่สามารถบันทึกการเบิกวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    public function destroyItem(string $code, int $itemId): RedirectResponse
    {
        try {
            DB::connection('oracle')->transaction(function () use ($code, $itemId) {
                $record = MaterialWithdrawn::query()
                    ->where('mat_wd_code', $code)
                    ->firstOrFail();

                MaterialWithdrawnList::query()
                    ->where('id', $itemId)
                    ->where('mat_wd_id', $record->id)
                    ->firstOrFail()
                    ->delete();
            });

            return back()->with('success', 'ลบรายการวัสดุเรียบร้อยแล้ว');
        } catch (Throwable $e) {
            Log::error('MAT-003 destroyItem failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'ไม่สามารถลบรายการวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    /**
     * AJAX: returns the current available balance for a material.
     * Available stock (MATERIAL_INVENTORY minus approved, not yet received withdrawals) in the store the
     * chosen withdrawal type draws on.
     * Returns {"available": N}. Returns 0 when the material exists but has no
     * transaction history yet.
     */
    public function stockCheck(Request $request): JsonResponse
    {
        $matId = (int) $request->input('material_id', 0);

        if ($matId <= 0) {
            return response()->json(['available' => null]);
        }

        // From the store the withdrawal draws on: the parent organization (LARGE_LOT) or the own one (INTERNAL_USE).
        $mode = $request->input('withdraw_mode') === 'INTERNAL_USE' ? 'INTERNAL_USE' : 'LARGE_LOT';
        $ownOrgId = Auth::user()?->org_id !== null ? (int) Auth::user()->org_id : null;
        $sourceOrgId = $this->inventory->sourceOrgId($ownOrgId, $mode) ?? $ownOrgId;
        if ($sourceOrgId === null) {
            return response()->json(['available' => null]);
        }

        $available = (int) ($this->inventory->available($sourceOrgId, [$matId])[$matId] ?? 0);

        return response()->json(['available' => $available]);
    }

    /** Materials this organization usually withdraws together with the ones on the new withdrawal. */
    public function recommendations(Request $request, MaterialWithdrawRecommendationService $recommender): JsonResponse
    {
        $ownOrgId = Auth::user()?->org_id !== null ? (int) Auth::user()->org_id : null;
        $matIds = array_filter(array_map('intval', explode(',', (string) $request->input('mat_ids', ''))));
        if ($ownOrgId === null || $matIds === []) {
            return response()->json(['data' => []]);
        }

        $recommendations = $recommender->recommend($ownOrgId, $matIds);

        // Stock in the store this withdrawal draws on, as in stockCheck()
        $mode = $request->input('withdraw_mode') === 'INTERNAL_USE' ? 'INTERNAL_USE' : 'LARGE_LOT';
        $sourceOrgId = $this->inventory->sourceOrgId($ownOrgId, $mode) ?? $ownOrgId;
        $available = $recommendations === [] ? [] : $this->inventory->available($sourceOrgId, array_column($recommendations, 'mat_id'));

        return response()->json(['data' => array_map(
            fn (array $rec) => $rec + ['available' => (int) ($available[$rec['mat_id']] ?? 0)],
            $recommendations
        )]);
    }

    // =========================================================
    //  Private helpers
    // =========================================================

    private function findByCode(string $code): MaterialWithdrawn
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        return MaterialWithdrawn::query()
            ->with(['organization', 'details.material', 'updatedByUser.organization'])
            ->where('mat_wd_code', $code)
            ->whereIn('org_id', $visibleOrgIds)
            ->firstOrFail();
    }

    private function currentUserOrg(): ?GlbOrganization
    {
        $orgId = Auth::user()?->org_id;
        if (! $orgId) {
            return null;
        }

        return GlbOrganization::query()
            ->where('org_id', $orgId)
            ->first(['org_id', 'org_code', 'org_name']);
    }

    private function nextWithdrawnId(): int
    {
        return (int) MaterialWithdrawn::query()->max('id') + 1;
    }

    private function nextWithdrawnListId(): int
    {
        return (int) MaterialWithdrawnList::query()->max('id') + 1;
    }

    /**
     * Returns the Thai fiscal year (พ.ศ.) for the given date.
     * Fiscal year runs Oct 1 – Sep 30.
     */
    private function fiscalYear(Carbon $date): int
    {
        $thaiYear = $date->year + 543;

        return $date->month >= 10 ? $thaiYear + 1 : $thaiYear;
    }

    /**
     * Builds the next withdrawal code for the given date's fiscal year.
     * Format: MWD-YYNNNNN (10 chars, MWD prefix) e.g. MWD-6900001
     */
    private function buildWithdrawCode(Carbon $date, bool $withLock): string
    {
        $yearCode = substr((string) $this->fiscalYear($date), -2);
        $pattern  = 'MWD-' . $yearCode;

        if ($withLock) {
            // Lock the most recent document as a serialisation mutex.
            // This ensures mutual exclusion even when no documents exist
            // for the current fiscal year (avoiding empty-result lock gap).
            MaterialWithdrawn::query()
                ->orderBy('id', 'desc')
                ->limit(1)
                ->lockForUpdate()
                ->value('id');
        }

        $maxRunning = MaterialWithdrawn::query()
            ->whereRaw("TRIM(mat_wd_code) LIKE ? AND LENGTH(TRIM(mat_wd_code)) = 11", [$pattern . '%'])
            ->get(['mat_wd_code'])
            ->reduce(function (int $carry, MaterialWithdrawn $record): int {
                $code = trim((string) $record->mat_wd_code);
                if (preg_match('/^MWD-\d{2}(\d{5})$/', $code, $m) === 1) {
                    return max($carry, (int) $m[1]);
                }

                return $carry;
            }, 0);

        return $pattern . str_pad((string) ($maxRunning + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Use inside a DB transaction: locks rows to prevent concurrent duplicates. */
    private function generateWithdrawCode(Carbon $date): string
    {
        return $this->buildWithdrawCode($date, true);
    }

    /** Preview-only (no lock): may be slightly stale under very high concurrency. */
    private function peekWithdrawCode(Carbon $date): string
    {
        return $this->buildWithdrawCode($date, false);
    }
}
