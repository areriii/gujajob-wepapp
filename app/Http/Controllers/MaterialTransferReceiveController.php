<?php

namespace App\Http\Controllers;

use App\Models\MaterialInspection;
use App\Models\MaterialInspectionList;
use App\Models\MaterialProcurementList;
use App\Models\MaterialWithdrawn;
use App\Services\MaterialInventoryService;
use App\Services\OrganizationVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class MaterialTransferReceiveController extends Controller
{
    public function __construct(
        private readonly OrganizationVisibilityService $orgVisibility,
        private readonly MaterialInventoryService $inventory,
    ) {}
    // =========================================================
    //  1. Index
    // =========================================================

    public function index(Request $request): View
    {
        $searchBy  = $request->string('search_by', 'wd_code')->value();
        $keyword   = trim($request->string('keyword')->value());
        $sort      = $request->string('sort', '')->value();
        $direction = strtolower($request->string('direction', 'asc')->value()) === 'desc' ? 'desc' : 'asc';

        $sortableColumns = [
            'wd_code'   => 'mat_wd_code',
            'wd_date'   => 'mat_wd_date',
            'requester' => 'mat_wd_person',
            'insp_code' => null, // handled via correlated subquery below
        ];

        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        // Correlated subquery for inspection code (needed for display + sort)
        $inspSubSql = '(SELECT mat_insp_code FROM MATERIAL_INSPECTION WHERE mat_wd_id = MATERIAL_WITHDRAWN.id AND ROWNUM = 1)';

        $query = MaterialWithdrawn::query()
            ->where('status', MaterialWithdrawn::STATUS_APPROVED)
            ->whereIn('org_id', $visibleOrgIds)
            ->has('details')
            ->with(['organization'])
            ->select('MATERIAL_WITHDRAWN.*')
            ->addSelect(\Illuminate\Support\Facades\DB::raw("{$inspSubSql} AS insp_code_sort"));

        if ($sort === 'insp_code') {
            $query->orderByRaw("{$inspSubSql} {$direction} NULLS LAST")->orderBy('id', $direction);
        } elseif (array_key_exists($sort, $sortableColumns) && $sortableColumns[$sort] !== null) {
            $query->orderBy($sortableColumns[$sort], $direction)->orderBy('id', $direction);
        } else {
            $query->orderByRaw('UPPER(mat_wd_code) ASC')->orderBy('id', 'ASC');
        }

        if ($keyword !== '') {
            $upper = mb_strtoupper($keyword);
            $query->whereRaw('UPPER(mat_wd_code) LIKE ?', ['%' . $upper . '%']);
        }

        $withdrawals = $query->paginate(10)->withQueryString();

        $wdIds   = $withdrawals->pluck('id')->all();
        $inspMap = [];

        if (!empty($wdIds)) {
            $ph   = implode(',', array_fill(0, count($wdIds), '?'));
            $rows = DB::connection('oracle')->select(
                "SELECT mi.id, mi.mat_wd_id, mi.mat_insp_code,
                        COUNT(mil.id) AS list_count
                 FROM MATERIAL_INSPECTION mi
                 LEFT JOIN MATERIAL_INSPECTION_LIST mil ON mil.mat_isp_id = mi.id
                 WHERE mi.mat_wd_id IN ($ph)
                 GROUP BY mi.id, mi.mat_wd_id, mi.mat_insp_code",
                $wdIds
            );

            foreach ($rows as $row) {
                $inspMap[(int) $row->mat_wd_id] = [
                    'mat_insp_code' => $row->mat_insp_code,
                    'list_count'    => (int) $row->list_count,
                ];
            }
        }

        return view('material.MAT-004-receive-material-transfer.index', [
            'pageTitle'   => 'รับโอนวัสดุ',
            'withdrawals' => $withdrawals,
            'inspMap'     => $inspMap,
            'searchBy'    => $searchBy,
            'keyword'     => $keyword,
            'sort'        => $sort,
            'direction'   => $direction,
        ]);
    }

    // =========================================================
    //  2. Show  (route key = mat_wd_code)
    // =========================================================

    public function show(string $code): View
    {
        $withdrawal = $this->findWithdrawalByCode($code);
        $inspection = $this->findInspectionByWdId((int) $withdrawal->id);

        $isConfirmed = $inspection !== null && $inspection->details->isNotEmpty();

        $detailItems  = $isConfirmed ? $inspection->details : $withdrawal->details;
        $matIds       = $detailItems->pluck('mat_id')->map(fn ($id) => (int) $id)->all();
        $inventoryMap  = $this->requesterStock($withdrawal, $matIds);
        if ($isConfirmed) {
            // Stock before this transfer: it was added to (LARGE_LOT) or taken from (INTERNAL_USE) the requester on receipt.
            $sign = $withdrawal->withdraw_type === 'INTERNAL_USE' ? 1 : -1;
            foreach ($inspection->details as $line) {
                $inventoryMap[(int) $line->mat_id] = ($inventoryMap[(int) $line->mat_id] ?? 0) + $sign * (float) $line->isp_amount;
            }
        }
        $stockHistory  = $this->buildStockHistory($matIds);

        $defaultPerson = $inspection?->mat_insp_person ?? old('mat_insp_person', Auth::user()->user_name ?? '');
        $defaultDate   = $inspection?->mat_insp_date
            ? (is_string($inspection->mat_insp_date)
                ? Carbon::parse($inspection->mat_insp_date)->format('d/m/Y')
                : $inspection->mat_insp_date->format('d/m/Y'))
            : Carbon::now()->format('d/m/Y');
        $defaultRemark = $inspection?->mat_insp_remark ?? old('mat_insp_remark', '');

        return view('material.MAT-004-receive-material-transfer.show', [
            'pageTitle'     => 'รับโอนวัสดุ',
            'withdrawal'    => $withdrawal,
            'inspection'    => $inspection,
            'isConfirmed'   => $isConfirmed,
            'inventoryMap'  => $inventoryMap,
            'stockHistory'  => $stockHistory,
            'defaultPerson' => $defaultPerson,
            'defaultDate'   => $defaultDate,
            'defaultRemark' => $defaultRemark,
            'code'          => $code,
        ]);
    }

    // =========================================================
    //  3. Edit  (route key = mat_wd_code)
    // =========================================================

    public function edit(string $code): View|RedirectResponse
    {
        $withdrawal = $this->findWithdrawalByCode($code);
        $inspection = $this->findInspectionByWdId((int) $withdrawal->id);

        if ($inspection !== null && $inspection->details->isNotEmpty()) {
            return redirect()
                ->route('material.transfer.show', $code)
                ->with('info', 'รายการนี้รับโอนเรียบร้อยแล้ว ไม่สามารถแก้ไขได้');
        }

        $detailItems  = $withdrawal->details;
        $matIds       = $detailItems->pluck('mat_id')->map(fn ($id) => (int) $id)->all();
        $inventoryMap = $this->requesterStock($withdrawal, $matIds);
        $stockHistory = $this->buildStockHistory($matIds);

        return view('material.MAT-004-receive-material-transfer.edit', [
            'pageTitle'    => 'รับโอนวัสดุ',
            'withdrawal'   => $withdrawal,
            'inspection'   => $inspection,
            'isConfirmed'  => false,
            'inventoryMap' => $inventoryMap,
            'stockHistory' => $stockHistory,
            'code'         => $code,
        ]);
    }

    // =========================================================
    //  4. Update  (route key = mat_wd_code)
    // =========================================================

    public function update(Request $request, string $code): RedirectResponse
    {
        $withdrawal = $this->findWithdrawalByCode($code);
        $inspection = $this->findInspectionByWdId((int) $withdrawal->id);

        if ($inspection === null) {
            return redirect()
                ->route('material.transfer.show', $code)
                ->with('error', 'ยังไม่มีข้อมูลการรับโอน กรุณายืนยันการรับโอนก่อน');
        }

        $validated = $request->validate([
            'mat_insp_date'   => ['required', 'string', 'max:20'],
            'mat_insp_person' => ['required', 'string', 'max:150'],
            'mat_insp_remark' => ['nullable', 'string', 'max:500'],
        ], [
            'mat_insp_date.required'   => 'กรุณาระบุวันที่รับโอน',
            'mat_insp_person.required' => 'กรุณากรอกชื่อผู้รับโอนวัสดุ',
        ]);

        try {
            $parsedDate = $this->parseDisplayDate($validated['mat_insp_date']);

            $inspection->update([
                'mat_insp_date'   => $parsedDate,
                'mat_insp_person' => trim($validated['mat_insp_person']),
                'mat_insp_remark' => $validated['mat_insp_remark'] ?? null,
                'updated_by'      => (int) Auth::id(),
            ]);

            return redirect()
                ->route('material.transfer.index')
                ->with('success', 'แก้ไขข้อมูลรับโอนวัสดุเรียบร้อยแล้ว');
        } catch (Throwable $e) {
            Log::error('MAT-004 update failed', ['code' => $code, 'error' => $e->getMessage()]);

            return back()->withInput()
                ->with('error', 'ไม่สามารถแก้ไขข้อมูลการรับโอนได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    // =========================================================
    //  5. Confirm  (route key = mat_wd_code)
    //     ISP code ONLY generated here, inside the transaction
    // =========================================================

    public function confirm(Request $request, string $code): RedirectResponse
    {
        $validated = $request->validate([
            'mat_insp_date'   => ['required', 'string', 'max:20'],
            'mat_insp_person' => ['required', 'string', 'max:150'],
            'mat_insp_remark' => ['nullable', 'string', 'max:500'],
        ], [
            'mat_insp_date.required'   => 'กรุณาระบุวันที่รับโอน',
            'mat_insp_person.required' => 'กรุณากรอกชื่อผู้รับโอนวัสดุ',
        ]);

        try {
            $parsedDate = $this->parseDisplayDate($validated['mat_insp_date']);

            DB::connection('oracle')->transaction(function () use ($code, $validated, $parsedDate): void {

                $userId = (int) Auth::id();

                // 1. Lock withdrawal — scoped to current user's org
                $withdrawal = MaterialWithdrawn::query()
                    ->where('mat_wd_code', $code)
                    ->where('org_id', (int) Auth::user()->org_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // 2. Must be approved
                if ((int) $withdrawal->status !== MaterialWithdrawn::STATUS_APPROVED) {
                    throw new RuntimeException('ใบเบิกนี้ยังไม่ได้รับการอนุมัติ ไม่สามารถรับโอนได้');
                }

                // 3. Duplicate protection
                $existingInsp = MaterialInspection::query()
                    ->where('mat_wd_id', $withdrawal->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingInsp !== null && $existingInsp->details()->count() > 0) {
                    throw new RuntimeException('ใบเบิกวัสดุนี้ได้รับการรับโอนเรียบร้อยแล้ว');
                }

                // 4. Load WD details
                $withdrawal->load('details');
                $wdDetails = $withdrawal->details;

                if ($wdDetails->isEmpty()) {
                    throw new RuntimeException('ไม่มีรายการวัสดุในใบเบิก');
                }

                // 5. Generate ISP code (only here, never before)
                $inspCode = $this->generateInspectionCode(Carbon::now());

                // 6. Create or update MATERIAL_INSPECTION
                if ($existingInsp !== null) {
                    $existingInsp->update([
                        'mat_insp_code'   => $inspCode,
                        'mat_insp_date'   => $parsedDate,
                        'mat_insp_person' => trim($validated['mat_insp_person']),
                        'mat_insp_remark' => $validated['mat_insp_remark'] ?? null,
                        'updated_by'      => $userId,
                    ]);
                    $inspection = $existingInsp;
                } else {
                    $inspection = MaterialInspection::create([
                        'id'              => $this->nextInspectionId(),
                        'mat_insp_code'   => $inspCode,
                        'mat_wd_id'       => $withdrawal->id,
                        'mat_insp_date'   => $parsedDate,
                        'mat_insp_person' => trim($validated['mat_insp_person']),
                        'mat_insp_remark' => $validated['mat_insp_remark'] ?? null,
                        'created_by'      => $userId,
                        'updated_by'      => $userId,
                    ]);
                }

                // 7. Create MATERIAL_INSPECTION_LIST rows
                $receivedLines = [];
                foreach ($wdDetails as $detail) {
                    $ispAmt = (int) $detail->wd_amount;
                    if ($ispAmt <= 0) {
                        continue;
                    }

                    $receivedLines[] = MaterialInspectionList::create([
                        'id'         => $this->nextInspectionListId(),
                        'mat_isp_id' => $inspection->id,
                        'mat_id'     => (int) $detail->mat_id,
                        'isp_amount' => $ispAmt,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                }

                // 8. The withdrawal is complete only now: move the received stock (MATERIAL_INVENTORY)
                $this->inventory->applyReceivedWithdrawal($withdrawal, $receivedLines, $userId);
            });

            return redirect()
                ->route('material.transfer.index')
                ->with('success', 'รับโอนวัสดุเรียบร้อยแล้ว');

        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('MAT-004 confirm failed', ['wd_code' => $code, 'error' => $e->getMessage()]);

            return back()->withInput()
                ->with('error', 'ไม่สามารถบันทึกการรับโอนวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    // =========================================================
    //  Legacy createInspection – kept for route compat
    // =========================================================

    public function createInspection(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'wd_code' => ['required', 'string', 'max:20'],
        ], ['wd_code.required' => 'กรุณาระบุเลขที่ใบเบิก']);

        $wdCode     = trim($validated['wd_code']);
        $withdrawal = MaterialWithdrawn::query()->where('mat_wd_code', $wdCode)->first();

        if ($withdrawal === null) {
            return back()->with('error', 'ไม่พบใบเบิกวัสดุ: ' . $wdCode);
        }

        if ((int) $withdrawal->status !== MaterialWithdrawn::STATUS_APPROVED) {
            return back()->with('error', 'ใบเบิกนี้ยังไม่ได้รับการอนุมัติ');
        }

        return redirect()->route('material.transfer.show', $wdCode);
    }

    // =========================================================
    //  Private helpers
    // =========================================================

    /** The requesting organization's stock on hand (MATERIAL_INVENTORY). @return array<int, float> */
    private function requesterStock(MaterialWithdrawn $withdrawal, array $matIds): array
    {
        return $withdrawal->org_id !== null ? $this->inventory->onHand((int) $withdrawal->org_id, $matIds) : [];
    }

    private function findWithdrawalByCode(string $wdCode): MaterialWithdrawn
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        return MaterialWithdrawn::query()
            ->with(['details.material', 'organization'])
            ->where('mat_wd_code', $wdCode)
            ->whereIn('org_id', $visibleOrgIds)
            ->firstOrFail();
    }

    private function findInspectionByWdId(int $wdId): ?MaterialInspection
    {
        return MaterialInspection::query()
            ->with(['details.material'])
            ->where('mat_wd_id', $wdId)
            ->first();
    }

    /**
     * @param  int[] $matIds
     */
    private function buildStockHistory(array $matIds): \Illuminate\Support\Collection
    {
        if (empty($matIds)) {
            return collect();
        }

        return MaterialProcurementList::query()
            ->with(['procurement', 'material'])
            ->whereIn('mat_id', $matIds)
            ->orderByDesc(function ($q) {
                $q->select('mat_pro_date')
                    ->from('MATERIAL_PROCUREMENT')
                    ->whereColumn('id', 'MATERIAL_PROCUREMENT_LIST.mat_pro_id')
                    ->limit(1);
            })
            ->limit(50)
            ->get();
    }

    private function nextInspectionId(): int
    {
        return (int) MaterialInspection::query()->max('id') + 1;
    }

    private function nextInspectionListId(): int
    {
        return (int) MaterialInspectionList::query()->max('id') + 1;
    }

    private function fiscalYear(Carbon $date): int
    {
        $thaiYear = $date->year + 543;

        return $date->month >= 10 ? $thaiYear + 1 : $thaiYear;
    }

    /** ISP-YYNNNNN – only called inside confirm() transaction */
    private function generateInspectionCode(Carbon $date): string
    {
        $yearCode = substr((string) $this->fiscalYear($date), -2);
        $prefix   = 'ISP-' . $yearCode;

        $maxRunning = MaterialInspection::query()
            ->where('mat_insp_code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->get(['mat_insp_code'])
            ->reduce(function (int $carry, MaterialInspection $r): int {
                if (preg_match('/^ISP-\d{2}(\d{5})$/', (string) $r->mat_insp_code, $m) === 1) {
                    return max($carry, (int) $m[1]);
                }

                return $carry;
            }, 0);

        return $prefix . str_pad((string) ($maxRunning + 1), 5, '0', STR_PAD_LEFT);
    }

    private function parseDisplayDate(string $displayDate): string
    {
        try {
            return Carbon::createFromFormat('d/m/Y', trim($displayDate))->format('Y-m-d');
        } catch (Throwable) {
            return Carbon::parse(trim($displayDate))->format('Y-m-d');
        }
    }
}
