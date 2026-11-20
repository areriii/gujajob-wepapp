<?php

namespace App\Http\Controllers;

use App\Models\GlbOrganization;
use App\Services\OrganizationVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AssetDisposalController extends Controller
{
    private const PER_PAGE = 10;

    // selling_approval_status: NULL/0 = รอการอนุมัติ, 1 = อนุมัติแล้ว, 2 = ไม่อนุมัติ
    private const STATUS_MAP = [
        0 => ['label' => 'รอการอนุมัติ', 'type' => 'pending'],
        1 => ['label' => 'อนุมัติแล้ว',   'type' => 'approved'],
        2 => ['label' => 'ไม่อนุมัติ',    'type' => 'rejected'],
    ];

    // REASON column is VARCHAR2(1); codes '1','2','3' stored in Oracle.
    private const REASON_OPTIONS = [
        '1' => 'หมดอายุการใช้งาน',
        '2' => 'ชำรุดจนซ่อมแซมไม่ได้',
        '3' => 'สูญหาย',
    ];

    private const REASON_TO_ASSET_STATUS = [
        '1' => '4',
        '2' => '5',
        '3' => '6',
    ];

    public static function getReasonLabel(string $code): string
    {
        return self::REASON_OPTIONS[$code] ?? ($code ?: '-');
    }

    public function __construct(private readonly OrganizationVisibilityService $orgVisibility) {}

    // ──────────────────────────────────────────────────────────────────────
    //  LIST
    // ──────────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $searchBy  = $request->input('search_by', 'all');
        $keyword   = trim($request->string('keyword')->value());
        $status    = $request->input('status', '');
        $sort      = $request->input('sort', '');
        $direction = strtolower($request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $query = DB::connection('oracle')
            ->table('ASSET_SELLING AS s')
            ->leftJoin('GLB_ORGANIZATION AS o', 'o.org_id', '=', 's.req_org_id')
            ->selectRaw("
                s.id,
                s.selling_code,
                s.selling_req_date,
                CASE WHEN s.selling_req_date IS NOT NULL THEN
                    TO_CHAR(s.selling_req_date,'DD-MM-')||TO_CHAR(s.selling_req_date+INTERVAL '543' YEAR(3),'YYYY')
                END AS req_date_th,
                o.org_name AS req_org_name,
                s.reason,
                s.remarks,
                s.selling_approval_status
            ");

        if (empty($visibleOrgIds)) {
            $query->whereRaw('1=0');
        } else {
            $query->whereIn('s.req_org_id', $visibleOrgIds);
        }

        // Keyword search – whitelist-guarded
        if ($keyword !== '') {
            $escaped = $this->escapeLike($keyword);
            $searchFieldMap = [
                'request_no' => 's.selling_code',
                'org_name'   => 'o.org_name',
            ];
            if (isset($searchFieldMap[$searchBy])) {
                $col = $searchFieldMap[$searchBy];
                $query->whereRaw("UPPER($col) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
            } else {
                // 'all' – partial match across doc number and org name
                $query->where(function ($q) use ($escaped) {
                    $q->whereRaw("UPPER(s.selling_code) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"])
                      ->orWhereRaw("UPPER(o.org_name)    LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
                });
            }
        }

        // Status filter
        if ($status === 'pending') {
            $query->where(function ($q) {
                $q->whereNull('s.selling_approval_status')
                  ->orWhere('s.selling_approval_status', 0);
            });
        } elseif ($status === 'approved') {
            $query->where('s.selling_approval_status', 1);
        } elseif ($status === 'rejected') {
            $query->where('s.selling_approval_status', 2);
        }

        // Sorting – whitelist-guarded, with deterministic secondary key
        $sortMap = [
            'request_no'   => 's.selling_code',
            'request_date' => 's.selling_req_date',
            'org_name'     => 'o.org_name',
            'status'       => 's.selling_approval_status',
        ];
        if (isset($sortMap[$sort])) {
            $query->orderByRaw("{$sortMap[$sort]} {$direction}, s.id ASC");
        } else {
            $query->orderByRaw('CASE WHEN s.selling_approval_status IS NULL OR s.selling_approval_status = 0 THEN 0 ELSE 1 END ASC, s.selling_req_date DESC NULLS LAST, s.id ASC');
        }

        $disposals = $query->paginate(self::PER_PAGE)->withQueryString();

        // Attach resolved status label/type to each row
        $disposals->getCollection()->transform(function ($item) {
            $key  = (int) ($item->selling_approval_status ?? 0);
            $info = self::STATUS_MAP[$key] ?? self::STATUS_MAP[0];
            $item->status_label = $info['label'];
            $item->status_type  = $info['type'];
            return $item;
        });

        return view('asset.ASS-006-request-asset-disposal.index', [
            'pageTitle' => 'แจ้งขอจำหน่ายครุภัณฑ์',
            'disposals' => $disposals,
            'searchBy'  => $searchBy,
            'keyword'   => $keyword,
            'status'    => $status,
            'sort'      => $sort,
            'direction' => $direction,
        ]);
    }

    public function approvalIndex(Request $request): View
    {
        $searchBy  = $request->input('search_by', 'all');
        $keyword   = trim($request->string('keyword')->value());
        $status    = $request->input('status', '');
        $sort      = $request->input('sort', '');
        $direction = strtolower($request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $query = DB::connection('oracle')->table('ASSET_SELLING AS s')
            ->leftJoin('GLB_ORGANIZATION AS o', 'o.org_id', '=', 's.req_org_id')
            ->selectRaw("s.id, s.selling_code, s.selling_req_date,
                CASE WHEN s.selling_req_date IS NOT NULL THEN TO_CHAR(s.selling_req_date, 'DD-MM-') || TO_CHAR(s.selling_req_date + INTERVAL '543' YEAR(3), 'YYYY') END AS req_date_th,
                o.org_name AS req_org_name, s.reason, s.remarks, s.selling_approval_status")
            ->where(function ($q) use ($visibleOrgIds): void {
                $q->whereIn('s.app_org_id', $visibleOrgIds)
                    ->orWhere(function ($sq) use ($visibleOrgIds): void {
                        $sq->whereNull('s.app_org_id')
                            ->whereIn('s.req_org_id', $visibleOrgIds);
                    });
            });

        if ($keyword !== '') {
            $escaped = $this->escapeLike($keyword);
            $columns = ['request_no' => 's.selling_code', 'org_name' => 'o.org_name'];
            $query->where(function ($q) use ($searchBy, $columns, $escaped): void {
                if (isset($columns[$searchBy])) {
                    $q->whereRaw("UPPER({$columns[$searchBy]}) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
                } else {
                    $q->whereRaw("UPPER(s.selling_code) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"])
                        ->orWhereRaw("UPPER(o.org_name) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
                }
            });
        }

        if ($status === 'pending') {
            $query->where(function ($q): void {
                $q->whereNull('s.selling_approval_status')->orWhere('s.selling_approval_status', 0);
            });
        } elseif ($status === 'approved') {
            $query->where('s.selling_approval_status', 1);
        } elseif ($status === 'rejected') {
            $query->where('s.selling_approval_status', 2);
        }

        $sortMap = [
            'request_no' => 's.selling_code',
            'request_date' => 's.selling_req_date',
            'org_name' => 'o.org_name',
            'status' => 's.selling_approval_status',
        ];
        if (isset($sortMap[$sort])) {
            $query->orderByRaw("{$sortMap[$sort]} {$direction}, s.id ASC");
        } else {
            $query->orderByRaw("CASE WHEN s.selling_approval_status IS NULL OR s.selling_approval_status = 0 THEN 1 WHEN s.selling_approval_status = 2 THEN 2 WHEN s.selling_approval_status = 1 THEN 3 ELSE 4 END ASC, s.selling_code ASC, s.id ASC");
        }

        $records = $query->paginate(self::PER_PAGE)->withQueryString();
        $records->getCollection()->transform(function ($item) {
            $info = self::STATUS_MAP[(int) ($item->selling_approval_status ?? 0)] ?? self::STATUS_MAP[0];
            $item->status_label = $info['label'];
            $item->status_type = $info['type'];
            $item->reason_label = self::REASON_OPTIONS[$item->reason ?? ''] ?? ($item->reason ?? '-');
            return $item;
        });

        return view('asset.ASS-007-approve-asset-disposal.index', compact(
            'records', 'searchBy', 'keyword', 'status', 'sort', 'direction'
        ) + ['pageTitle' => 'อนุมัติแจ้งจำหน่ายครุภัณฑ์']);
    }

    public function approvalShow(int $id): View
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);
        $record = $this->fetchApprovalRecord($id, $visibleOrgIds);
        abort_if(! $record, 404);

        $items = $this->fetchApprovalItems($id);
        $statusInfo = self::STATUS_MAP[(int) ($record->selling_approval_status ?? 0)] ?? self::STATUS_MAP[0];
        $reasonLabel = self::REASON_OPTIONS[$record->reason ?? ''] ?? ($record->reason ?? '-');

        return view('asset.ASS-007-approve-asset-disposal.detail', compact(
            'record', 'items', 'statusInfo', 'reasonLabel'
        ) + ['pageTitle' => 'รายละเอียดการแจ้งขอจำหน่ายครุภัณฑ์']);
    }

    public function approvalConsider(Request $request, int $id): View
    {
        $itemSearchBy  = $request->input('item_search_by', 'all');
        $itemKeyword   = trim($request->string('item_keyword')->value());
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $record = $this->fetchApprovalRecord($id, $visibleOrgIds);
        abort_if(! $record, 404);
        abort_if(! $this->isApprovalPending($record), 403);

        $statusInfo = self::STATUS_MAP[(int) ($record->selling_approval_status ?? 0)] ?? self::STATUS_MAP[0];
        $reasonLabel = self::REASON_OPTIONS[$record->reason ?? ''] ?? ($record->reason ?? '-');
        $isLostReason = $this->isLostReason($record);
        $items = $isLostReason
            ? $this->fetchApprovalItems($id)
            : $this->buildApprovalItemsQuery($id, $itemSearchBy, $itemKeyword)->get();

        return view('asset.ASS-007-approve-asset-disposal.show', compact(
            'record', 'items', 'statusInfo', 'reasonLabel', 'itemSearchBy', 'itemKeyword', 'isLostReason'
        ) + ['pageTitle' => 'พิจารณาใบแจ้งขอจำหน่าย']);
    }

    public function approvalEdit(Request $request, int $id): View
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);
        $record = $this->fetchApprovalRecord($id, $visibleOrgIds);
        abort_if(! $record, 404);
        abort_if($this->isApprovalPending($record), 403);

        $items = $this->fetchApprovalItems($id);
        $statusInfo = self::STATUS_MAP[(int) ($record->selling_approval_status ?? 0)] ?? self::STATUS_MAP[0];
        $reasonLabel = self::REASON_OPTIONS[$record->reason ?? ''] ?? ($record->reason ?? '-');
        $isLostReason = $this->isLostReason($record);

        return view('asset.ASS-007-approve-asset-disposal.edit', compact(
            'record', 'items', 'statusInfo', 'reasonLabel', 'isLostReason'
        ) + ['pageTitle' => 'แก้ไขผลการอนุมัติ']);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $record = $this->fetchApprovalRecord($id, $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id));
        abort_if(! $record, 404);
        abort_if(! $this->isApprovalPending($record), 403);

        $isLostReason = $this->isLostReason($record);
        $validated = $request->validate($isLostReason ? [] : [
            'approval_date' => ['required', 'date'],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.selling_min_price' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $approvalDate = $isLostReason ? $record->created_at_input : $validated['approval_date'];
        $items = $isLostReason ? [] : $validated['items'];

        if (! $isLostReason && ($missing = $this->unpricedItemCodes($id, $items)) !== []) {
            return redirect()->route('asset.disposals.approval.consider', $id)
                ->withInput()
                ->withErrors(['_error' => $this->unpricedItemsMessage($missing)]);
        }

        return $this->finalizeApproval($id, 1, $approvalDate, $items, null);
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $record = $this->fetchApprovalRecord($id, $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id));
        abort_if(! $record, 404);
        abort_if(! $this->isApprovalPending($record), 403);

        $isLostReason = $this->isLostReason($record);
        $validated = $request->validate([
            'approval_date' => [$isLostReason ? 'nullable' : 'required', 'date'],
            'reject_reason' => ['required', 'string', 'max:500'],
        ], [
            'reject_reason.required' => 'กรุณากรอกหมายเหตุหากไม่อนุมัติ',
        ]);

        $approvalDate = $isLostReason ? $record->created_at_input : $validated['approval_date'];

        return $this->finalizeApproval($id, 2, $approvalDate, [], trim($validated['reject_reason']));
    }

    public function approvalUpdate(Request $request, int $id): RedirectResponse
    {
        $record = $this->fetchApprovalRecord($id, $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id));
        abort_if(! $record, 404);
        abort_if($this->isApprovalPending($record), 403);

        $isLostReason = $this->isLostReason($record);
        $validated = $request->validate([
            'decision'      => ['required', Rule::in(['1', '2'])],
            'approval_date' => [$isLostReason ? 'nullable' : 'required', 'date'],
            'reject_reason' => ['nullable', 'string', 'max:500', 'required_if:decision,2'],
            'items'         => [$isLostReason ? 'nullable' : 'required_if:decision,1', 'array'],
            'items.*.selling_min_price' => [$isLostReason ? 'nullable' : 'required_if:decision,1', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'reject_reason.required_if' => 'กรุณากรอกหมายเหตุหากไม่อนุมัติ',
            'items.*.selling_min_price.required_if' => 'กรุณากรอกราคาขายขั้นต่ำของทุกรายการ',
        ]);

        $decision = (int) $validated['decision'];
        $items = $decision === 1 && ! $isLostReason ? ($validated['items'] ?? []) : [];
        $rejectReason = $decision === 2 ? trim((string) $validated['reject_reason']) : null;
        $approvalDate = $isLostReason ? $record->created_at_input : $validated['approval_date'];

        if ($decision === 1 && ! $isLostReason && ($missing = $this->unpricedItemCodes($id, $items)) !== []) {
            return redirect()->route('asset.disposals.approval.edit', $id)
                ->withInput()
                ->withErrors(['_error' => $this->unpricedItemsMessage($missing)]);
        }

        return $this->finalizeApproval($id, $decision, $approvalDate, $items, $rejectReason, false);
    }

    private function fetchApprovalRecord(int $id, array $visibleOrgIds): ?object
    {
        if (empty($visibleOrgIds)) {
            return null;
        }

        return DB::connection('oracle')->table('ASSET_SELLING AS s')
            ->leftJoin('GLB_ORGANIZATION AS o', 'o.org_id', '=', 's.req_org_id')
            ->leftJoin('GLB_ORGANIZATION AS aorg', 'aorg.org_id', '=', 's.app_org_id')
            ->leftJoin('SYS_USER AS approver', 'approver.id', '=', 's.updated_by')
            ->selectRaw("s.*, o.org_name AS req_org_name, aorg.org_name AS app_org_name, approver.user_name AS approval_user_name,
                CASE WHEN s.selling_req_date IS NOT NULL THEN TO_CHAR(s.selling_req_date, 'DD-MM-') || TO_CHAR(s.selling_req_date + INTERVAL '543' YEAR(3), 'YYYY') END AS req_date_th,
                CASE WHEN s.selling_approval_date IS NOT NULL THEN TO_CHAR(s.selling_approval_date, 'DD-MM-') || TO_CHAR(s.selling_approval_date + INTERVAL '543' YEAR(3), 'YYYY') END AS approval_date_th,
                CASE WHEN s.created_at IS NOT NULL THEN TO_CHAR(CAST(s.created_at AS DATE), 'DD-MM-') || TO_CHAR(CAST(s.created_at AS DATE) + INTERVAL '543' YEAR(3), 'YYYY') END AS created_at_th,
                TO_CHAR(CAST(s.created_at AS DATE), 'YYYY-MM-DD') AS created_at_input,
                TO_CHAR(s.selling_approval_date, 'YYYY-MM-DD') AS approval_date_input")
            ->where('s.id', $id)
            ->where(function ($q) use ($visibleOrgIds): void {
                $q->whereIn('s.app_org_id', $visibleOrgIds)
                    ->orWhere(function ($sq) use ($visibleOrgIds): void {
                        $sq->whereNull('s.app_org_id')
                            ->whereIn('s.req_org_id', $visibleOrgIds);
                    });
            })
            ->first();
    }

    private function buildApprovalItemsQuery(int $id, string $itemSearchBy = 'all', string $itemKeyword = '')
    {
        $itemsQuery = DB::connection('oracle')->table('ASSET_SELLING_LIST AS sl')
            ->join('ASSET AS a', 'a.id', '=', 'sl.ass_id')
            ->leftJoin('ASSET_CATEGORY AS c', 'c.id', '=', 'a.asscat_id')
            ->selectRaw("
                sl.id,
                a.ass_code,
                c.asscat_code,
                c.asscat_name,
                a.ass_price,
                a.remain_price,
                sl.selling_min_price,
                sl.selling_real_price,
                (SELECT MAX(aa2.status) KEEP (DENSE_RANK LAST ORDER BY aa2.id)
                 FROM ASSET_ASSIGNMENT_LIST aal2
                 JOIN ASSET_ASSIGNMENT aa2 ON aa2.id = aal2.ass_assign_id
                 WHERE aal2.asset_id = a.id) AS aa_status
            ")
            ->where('sl.selling_id', $id);

        if ($itemKeyword !== '') {
            $escaped = $this->escapeLike($itemKeyword);
            $itemColumnMap = ['code' => 'a.ass_code', 'name' => 'c.asscat_name'];
            if (isset($itemColumnMap[$itemSearchBy])) {
                $col = $itemColumnMap[$itemSearchBy];
                $itemsQuery->whereRaw("UPPER({$col}) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
            } else {
                $itemsQuery->where(function ($q) use ($escaped): void {
                    $q->whereRaw("UPPER(a.ass_code) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"])
                        ->orWhereRaw("UPPER(c.asscat_name) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
                });
            }
        }

        return $itemsQuery->orderBy('sl.id');
    }

    private function fetchApprovalItems(int $id)
    {
        return $this->buildApprovalItemsQuery($id)->get();
    }

    private function isApprovalPending(object $record): bool
    {
        return $record->selling_approval_status === null || (int) $record->selling_approval_status === 0;
    }

    /**
     * คืนรายการครุภัณฑ์ในใบแจ้งที่ฟอร์มไม่ได้ส่งราคาขายขั้นต่ำมา
     *
     * หน้าพิจารณามีช่องค้นหารายการครุภัณฑ์ ถ้าผู้อนุมัติกรองไว้ ตารางจะเหลือเฉพาะรายการที่ตรงคำค้น
     * ฟอร์มจึงส่งมาแค่รายการเหล่านั้น รายการที่ถูกกรองออกไม่ได้ราคา แต่ใบแจ้งถูกอนุมัติไปทั้งใบ
     * แล้วรายการที่ตกหล่นจะไปโผล่เป็นช่องว่างในหน้าแก้ไขผลการอนุมัติ ทั้งที่กดอนุมัติไปแล้ว
     * finalizeApproval() วนอัปเดตเฉพาะรายการที่ส่งมา จึงต้องกันไว้ตั้งแต่ตรงนี้
     *
     * @param  array<int|string,mixed>  $items  คีย์คือ ASSET_SELLING_LIST.ID ที่ฟอร์มส่งมา
     * @return list<string>
     */
    private function unpricedItemCodes(int $id, array $items): array
    {
        $postedIds = array_map('intval', array_keys($items));

        return DB::connection('oracle')->table('ASSET_SELLING_LIST AS sl')
            ->join('ASSET AS a', 'a.id', '=', 'sl.ass_id')
            ->leftJoin('ASSET_CATEGORY AS c', 'c.id', '=', 'a.asscat_id')
            ->where('sl.selling_id', $id)
            ->when($postedIds !== [], fn ($q) => $q->whereNotIn('sl.id', $postedIds))
            ->orderBy('sl.id')
            ->get(['sl.id', 'a.ass_code', 'c.asscat_name'])
            // ครุภัณฑ์ที่ยังไม่ได้รับลงทะเบียนหน่วยงานจะยังไม่มีรหัส จึงถอยไปใช้ชื่อครุภัณฑ์แทน
            ->map(fn ($r) => (string) ($r->ass_code ?: ($r->asscat_name ?: 'รายการลำดับที่ ' . $r->id)))
            ->all();
    }

    private function unpricedItemsMessage(array $codes): string
    {
        return 'ยังไม่ได้กรอกราคาขายขั้นต่ำของ ' . implode(', ', $codes)
            . ' กรุณากรอกให้ครบทุกรายการก่อนบันทึก (ระบบล้างคำค้นหารายการให้แล้ว)';
    }

    private function isLostReason(object|string|null $recordOrReason): bool
    {
        $reason = is_object($recordOrReason) ? ($recordOrReason->reason ?? null) : $recordOrReason;

        return (string) $reason === '3';
    }

    // ──────────────────────────────────────────────────────────────────────
    //  RESULT LIST / RECORD / DETAIL (ASS-008)
    // ──────────────────────────────────────────────────────────────────────

    public function resultIndex(Request $request): View
    {
        $searchBy      = $request->string('search_by', 'all')->value();
        $keyword       = trim($request->string('keyword')->value());
        $statusFilter  = $request->string('status', '')->value();
        $buyerKeyword  = trim($request->string('buyer')->value());
        $sort          = $request->string('sort', '')->value();
        $direction     = strtolower($request->string('direction', 'desc')->value()) === 'asc' ? 'asc' : 'desc';
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $summarySub = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST AS sl')
            ->selectRaw('sl.selling_id, COUNT(*) AS total_items, SUM(CASE WHEN sl.selling_real_price IS NOT NULL THEN 1 ELSE 0 END) AS priced_items')
            ->groupBy('sl.selling_id');

        $query = DB::connection('oracle')
            ->table('ASSET_SELLING AS s')
            ->leftJoinSub($summarySub, 'rs', 'rs.selling_id', '=', 's.id')
            ->leftJoin('GLB_ORGANIZATION AS rorg', 'rorg.org_id', '=', 's.req_org_id')
            ->leftJoin('SYS_USER AS approver', 'approver.id', '=', 's.updated_by')
            ->selectRaw("
                s.id,
                s.selling_code,
                s.selling_req_date,
                s.selling_approval_date,
                CASE WHEN s.selling_req_date IS NOT NULL THEN
                    TO_CHAR(s.selling_req_date,'DD-MM-')||TO_CHAR(s.selling_req_date+INTERVAL '543' YEAR(3),'YYYY')
                END AS req_date_th,
                CASE WHEN s.selling_approval_date IS NOT NULL THEN
                    TO_CHAR(s.selling_approval_date,'DD-MM-')||TO_CHAR(s.selling_approval_date+INTERVAL '543' YEAR(3),'YYYY')
                END AS approval_date_th,
                s.reason,
                s.buyer,
                s.selling_approval_status,
                rorg.org_name AS req_org_name,
                approver.user_name AS approval_user_name,
                NVL(rs.total_items, 0) AS total_items,
                NVL(rs.priced_items, 0) AS priced_items
            ")
            ->where('s.selling_approval_status', 1);

        if (empty($visibleOrgIds)) {
            $query->whereRaw('1=0');
        } else {
            $query->whereIn('s.req_org_id', $visibleOrgIds);
        }

        if ($keyword !== '') {
            $escaped = $this->escapeLike($keyword);
            $searchFieldMap = [
                'request_no' => 's.selling_code',
                'org_name'   => 'rorg.org_name',
            ];
            if (isset($searchFieldMap[$searchBy])) {
                $col = $searchFieldMap[$searchBy];
                $query->whereRaw("UPPER({$col}) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
            } else {
                $query->where(function ($q) use ($escaped): void {
                    $q->whereRaw("UPPER(s.selling_code) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"])
                        ->orWhereRaw("UPPER(rorg.org_name) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
                });
            }
        }

        if ($buyerKeyword !== '') {
            $escapedBuyer = $this->escapeLike($buyerKeyword);
            $query->whereRaw("UPPER(s.buyer) LIKE UPPER(?) ESCAPE '\\'", ["%{$escapedBuyer}%"]);
        }

        $completedExpr = "(NVL(rs.total_items,0) > 0 AND NVL(rs.priced_items,0) = NVL(rs.total_items,0) AND (s.buyer IS NOT NULL OR s.reason = '3'))";

        if ($statusFilter === 'approved') {
            $query->whereRaw("NOT {$completedExpr}");
        } elseif ($statusFilter === '4') {
            $query->whereRaw($completedExpr)->whereIn('s.reason', ['1', '2']);
        } elseif ($statusFilter === '6') {
            $query->whereRaw($completedExpr)->where('s.reason', '3');
        }

        $sortMap = [
            'request_no'   => 's.selling_code',
            'request_date' => 's.selling_req_date',
            'org_name'     => 'rorg.org_name',
            'approval_date'=> 's.selling_approval_date',
            'buyer'        => 's.buyer',
        ];

        if ($sort === 'status') {
            $query->orderByRaw("CASE WHEN {$completedExpr} THEN (10 + TO_NUMBER(NVL(s.reason,'9'))) ELSE 1 END {$direction}, s.id ASC");
        } elseif (isset($sortMap[$sort])) {
            $query->orderByRaw("{$sortMap[$sort]} {$direction} NULLS LAST, s.id ASC");
        } else {
            $query->orderByRaw('s.selling_approval_date DESC NULLS LAST, s.id DESC');
        }

        $records = $query->paginate(self::PER_PAGE)->withQueryString();
        $records->getCollection()->transform(function ($row) {
            $completed = $this->isResultCompleted($row);
            $statusInfo = $this->resolveResultStatusInfo((string) ($row->reason ?? ''), $completed);
            $row->result_status_label = $statusInfo['label'];
            $row->result_status_type  = $statusInfo['type'];
            $row->can_record_result   = ! $completed;
            return $row;
        });

        return view('asset.ASS-008-record-asset-disposal-result.index', [
            'pageTitle'     => 'บันทึกผลการจำหน่ายครุภัณฑ์',
            'records'       => $records,
            'searchBy'      => $searchBy,
            'keyword'       => $keyword,
            'statusFilter'  => $statusFilter,
            'buyerKeyword'  => $buyerKeyword,
            'sort'          => $sort,
            'direction'     => $direction,
        ]);
    }

    public function resultCreate(Request $request, int $id): View|RedirectResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $record = $this->fetchResultHeader($id, $visibleOrgIds);
        abort_if(! $record, 404);
        abort_if((int) ($record->selling_approval_status ?? 0) !== 1, 403);

        // บันทึกผลไปแล้วก็ยังเข้ามาแก้ได้ เช่น กรอกราคาผิดหรือพิมพ์ชื่อผู้รับซื้อผิด
        // เดิมหน้านี้เด้งไปหน้ารายละเอียดทันที ทำให้แก้อะไรไม่ได้เลยหลังกดบันทึกครั้งแรก
        $isCompleted = $this->isResultCompleted($record);

        // Create page must always submit all lines, so do not filter item rows.
        $items = $this->buildResultItemsQuery($id, 'all', '')->get();
        $statusInfo = $this->resolveResultStatusInfo((string) ($record->reason ?? ''), $isCompleted);
        $isLostReason = $this->isLostReason($record);

        return view('asset.ASS-008-record-asset-disposal-result.create', [
            'pageTitle'      => $isCompleted ? 'แก้ไขผลการจำหน่ายครุภัณฑ์' : 'บันทึกผลการจำหน่ายครุภัณฑ์',
            'record'         => $record,
            'items'          => $items,
            'statusInfo'     => $statusInfo,
            'reasonLabel'    => self::REASON_OPTIONS[$record->reason ?? ''] ?? ($record->reason ?? '-'),
            'isLostReason'   => $isLostReason,
            'isCompleted'    => $isCompleted,
        ]);
    }

    public function resultStore(Request $request, int $id): RedirectResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);
        $record = $this->fetchResultHeader($id, $visibleOrgIds);
        abort_if(! $record, 404);
        abort_if((int) ($record->selling_approval_status ?? 0) !== 1, 403);

        // เขียนทับผลเดิมได้ เพราะเป็นการแก้ไขข้อมูลที่กรอกผิด ไม่ใช่การจำหน่ายซ้ำ
        // สถานะครุภัณฑ์ที่เขียนลงไปเป็นค่าเดิมตามเหตุผลการจำหน่าย จึงบันทึกซ้ำได้ไม่มีผลข้างเคียง
        $wasCompleted = $this->isResultCompleted($record);

        $isLostReason = $this->isLostReason($record);
        $validated = $request->validate($isLostReason ? [] : [
            'buyer'                     => ['required', 'string', 'max:150'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.selling_real_price'=> ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'buyer.required'                     => 'กรุณากรอกผู้รับซื้อ',
            'items.required'                     => 'กรุณากรอกราคาที่ขายได้จริง',
            'items.*.selling_real_price.required'=> 'กรุณากรอกราคาที่ขายได้จริง',
            'items.*.selling_real_price.numeric' => 'ราคาที่ขายได้จริงต้องเป็นตัวเลข',
            'items.*.selling_real_price.min'     => 'ราคาที่ขายได้จริงต้องไม่น้อยกว่า 0',
        ]);

        $sellingItems = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST')
            ->where('selling_id', $id)
            ->get(['id', 'ass_id']);

        $validLineIds = $sellingItems->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        if (! $isLostReason) {
            $submittedLineIds = collect(array_keys((array) $validated['items']))
                ->map(fn ($v) => (int) $v)
                ->sort()
                ->values()
                ->all();

            if ($validLineIds !== $submittedLineIds) {
                return back()->withErrors(['_error' => 'รายการครุภัณฑ์ไม่ตรงกับใบแจ้งขอจำหน่าย'])->withInput();
            }
        }

        $assetIds = $sellingItems->pluck('ass_id')->map(fn ($v) => (int) $v)->all();
        $buyer = $isLostReason ? null : trim((string) $validated['buyer']);
        $userId = (int) Auth::id();
        $finalAssetStatus = self::REASON_TO_ASSET_STATUS[(string) ($record->reason ?? '')] ?? null;

        try {
            DB::connection('oracle')->transaction(function () use ($id, $validated, $buyer, $assetIds, $userId, $finalAssetStatus, $isLostReason): void {
                $header = DB::connection('oracle')
                    ->table('ASSET_SELLING')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (! $header || (int) ($header->selling_approval_status ?? 0) !== 1) {
                    throw new \RuntimeException('invalid_status');
                }

                DB::connection('oracle')
                    ->table('ASSET_SELLING')
                    ->where('id', $id)
                    ->update([
                        'buyer'      => $isLostReason ? $header->buyer : $buyer,
                        'updated_by' => $userId,
                        'updated_at' => DB::raw('SYSTIMESTAMP'),
                    ]);

                if ($isLostReason) {
                    DB::connection('oracle')
                        ->table('ASSET_SELLING_LIST')
                        ->where('selling_id', $id)
                        ->update([
                            'selling_real_price' => 0,
                            'updated_by'         => $userId,
                            'updated_at'         => DB::raw('SYSTIMESTAMP'),
                        ]);
                } else {
                    foreach ($validated['items'] as $lineId => $lineData) {
                        DB::connection('oracle')
                            ->table('ASSET_SELLING_LIST')
                            ->where('id', (int) $lineId)
                            ->where('selling_id', $id)
                            ->update([
                                'selling_real_price' => (float) $lineData['selling_real_price'],
                                'updated_by'         => $userId,
                                'updated_at'         => DB::raw('SYSTIMESTAMP'),
                            ]);
                    }
                }

                if ($finalAssetStatus !== null && ! empty($assetIds)) {
                    DB::connection('oracle')
                        ->table('ASSET')
                        ->whereIn('id', $assetIds)
                        ->update([
                            'ass_status' => $finalAssetStatus,
                            'updated_by' => $userId,
                            'updated_at' => DB::raw('SYSTIMESTAMP'),
                        ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('ASS-008 result store failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->withErrors(['_error' => 'เกิดข้อผิดพลาดขณะบันทึกผลการจำหน่าย กรุณาลองใหม่'])->withInput();
        }

        return redirect()->route('asset.disposals.results.index')
            ->with('success', $wasCompleted
                ? 'แก้ไขผลการจำหน่ายเรียบร้อยแล้ว'
                : 'บันทึกผลการจำหน่ายเรียบร้อยแล้ว');
    }

    public function resultShow(Request $request, int $id): View
    {
        $itemSearchBy  = $request->string('item_search_by', 'all')->value();
        $itemKeyword   = trim($request->string('item_keyword')->value());
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $record = $this->fetchResultHeader($id, $visibleOrgIds);
        abort_if(! $record, 404);
        abort_if((int) ($record->selling_approval_status ?? 0) !== 1, 403);

        $items = $this->buildResultItemsQuery($id, $itemSearchBy, $itemKeyword)->get();
        $statusInfo = $this->resolveResultStatusInfo((string) ($record->reason ?? ''), $this->isResultCompleted($record));

        return view('asset.ASS-008-record-asset-disposal-result.show', [
            'pageTitle'    => 'รายละเอียดผลการจำหน่าย',
            'record'       => $record,
            'items'        => $items,
            'statusInfo'   => $statusInfo,
            'reasonLabel'  => self::REASON_OPTIONS[$record->reason ?? ''] ?? ($record->reason ?? '-'),
            'itemSearchBy' => $itemSearchBy,
            'itemKeyword'  => $itemKeyword,
        ]);
    }

    private function buildResultItemsQuery(int $id, string $itemSearchBy, string $itemKeyword)
    {
        $itemsQuery = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST AS sl')
            ->join('ASSET AS a', 'a.id', '=', 'sl.ass_id')
            ->leftJoin('ASSET_CATEGORY AS c', 'c.id', '=', 'a.asscat_id')
            ->selectRaw("
                sl.id,
                sl.selling_min_price,
                sl.selling_real_price,
                a.id AS asset_id,
                a.ass_code,
                a.ass_price,
                c.asscat_code,
                c.asscat_name,
                (SELECT MAX(aa2.status) KEEP (DENSE_RANK LAST ORDER BY aa2.id)
                 FROM ASSET_ASSIGNMENT_LIST aal2
                 JOIN ASSET_ASSIGNMENT aa2 ON aa2.id = aal2.ass_assign_id
                 WHERE aal2.asset_id = a.id) AS aa_status
            ")
            ->where('sl.selling_id', $id);

        if ($itemKeyword !== '') {
            $escaped = $this->escapeLike($itemKeyword);
            $itemColumnMap = ['code' => 'a.ass_code', 'name' => 'c.asscat_name'];
            if (isset($itemColumnMap[$itemSearchBy])) {
                $col = $itemColumnMap[$itemSearchBy];
                $itemsQuery->whereRaw("UPPER({$col}) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
            } else {
                $itemsQuery->where(function ($q) use ($escaped): void {
                    $q->whereRaw("UPPER(a.ass_code) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"])
                        ->orWhereRaw("UPPER(c.asscat_name) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
                });
            }
        }

        return $itemsQuery->orderByRaw('sl.id ASC');
    }

    private function fetchResultHeader(int $id, array $visibleOrgIds): ?object
    {
        $summarySub = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST AS sl')
            ->selectRaw('sl.selling_id, COUNT(*) AS total_items, SUM(CASE WHEN sl.selling_real_price IS NOT NULL THEN 1 ELSE 0 END) AS priced_items')
            ->groupBy('sl.selling_id');

        return DB::connection('oracle')
            ->table('ASSET_SELLING AS s')
            ->leftJoinSub($summarySub, 'rs', 'rs.selling_id', '=', 's.id')
            ->leftJoin('GLB_ORGANIZATION AS rorg', 'rorg.org_id', '=', 's.req_org_id')
            ->leftJoin('GLB_ORGANIZATION AS aorg', 'aorg.org_id', '=', 's.app_org_id')
            ->leftJoin('SYS_USER AS approver', 'approver.id', '=', 's.updated_by')
            ->selectRaw("
                s.id,
                s.selling_code,
                s.selling_req_date,
                s.selling_approval_date,
                CASE WHEN s.selling_req_date IS NOT NULL THEN
                    TO_CHAR(s.selling_req_date,'DD-MM-')||TO_CHAR(s.selling_req_date+INTERVAL '543' YEAR(3),'YYYY')
                END AS req_date_th,
                CASE WHEN s.selling_approval_date IS NOT NULL THEN
                    TO_CHAR(s.selling_approval_date,'DD-MM-')||TO_CHAR(s.selling_approval_date+INTERVAL '543' YEAR(3),'YYYY')
                END AS approval_date_th,
                s.selling_approval_status,
                s.reason,
                s.buyer,
                s.remarks,
                rorg.org_name AS req_org_name,
                aorg.org_name AS app_org_name,
                approver.user_name AS approval_user_name,
                NVL(rs.total_items, 0) AS total_items,
                NVL(rs.priced_items, 0) AS priced_items
            ")
            ->where('s.id', $id)
            ->whereIn('s.req_org_id', $visibleOrgIds)
            ->first();
    }

    private function isResultCompleted(object $record): bool
    {
        $totalItems = (int) ($record->total_items ?? 0);
        $pricedItems = (int) ($record->priced_items ?? 0);

        if ($this->isLostReason($record)) {
            return $totalItems > 0 && $totalItems === $pricedItems;
        }

        $hasBuyer = trim((string) ($record->buyer ?? '')) !== '';

        return $hasBuyer && $totalItems > 0 && $totalItems === $pricedItems;
    }

    private function resolveResultStatusInfo(string $reasonCode, bool $completed): array
    {
        if (! $completed) {
            return ['label' => 'อนุมัติแล้ว', 'type' => 'approved'];
        }

        if ($reasonCode === '3') {
            return ['label' => 'สูญหาย', 'type' => 'result-lost'];
        }

        return ['label' => 'จำหน่ายแล้ว', 'type' => 'result-sold'];
    }

    /**
     * Applies the approve/reject decision inside a single Oracle transaction:
     * re-checks the pending state under lock, writes per-item selling prices
     * (approve only), then updates the ASSET_SELLING header.
     *
     * @param  array<int, array{selling_min_price?: mixed}>  $items
     */
    private function finalizeApproval(int $id, int $status, string $approvalDate, array $items, ?string $rejectReason, bool $pendingOnly = true): RedirectResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);
        $userId        = (int) Auth::user()->id;
        $approverOrgId = (int) Auth::user()->org_id;

        try {
            $updated = DB::connection('oracle')->transaction(function () use ($id, $status, $approvalDate, $items, $rejectReason, $userId, $visibleOrgIds, $approverOrgId, $pendingOnly) {
                $header = DB::connection('oracle')->table('ASSET_SELLING')
                    ->where('id', $id)
                    ->where(function ($q) use ($visibleOrgIds): void {
                        $q->whereIn('app_org_id', $visibleOrgIds)
                            ->orWhere(function ($sq) use ($visibleOrgIds): void {
                                $sq->whereNull('app_org_id')
                                    ->whereIn('req_org_id', $visibleOrgIds);
                            });
                    })
                    ->lockForUpdate()
                    ->first();

                $isEditable = $header && (! $pendingOnly || $this->isApprovalPending($header));
                if (! $isEditable) {
                    return 0;
                }

                if ($status === 1 && $this->isLostReason($header)) {
                    DB::connection('oracle')->table('ASSET_SELLING_LIST')
                        ->where('selling_id', $id)
                        ->update([
                            'selling_min_price' => 0,
                            'updated_by'         => $userId,
                            'updated_at'         => now(),
                        ]);
                } elseif ($status === 1) {
                    $validItemIds = DB::connection('oracle')->table('ASSET_SELLING_LIST')
                        ->where('selling_id', $id)->pluck('id')->map(fn ($v) => (int) $v)->all();

                    foreach ($items as $itemId => $row) {
                        $itemId = (int) $itemId;
                        if (! in_array($itemId, $validItemIds, true)) {
                            continue;
                        }
                        DB::connection('oracle')->table('ASSET_SELLING_LIST')
                            ->where('id', $itemId)->where('selling_id', $id)
                            ->update([
                                'selling_min_price' => (float) $row['selling_min_price'],
                                'updated_by'         => $userId,
                                'updated_at'         => now(),
                            ]);
                    }
                }

                return DB::connection('oracle')->table('ASSET_SELLING')
                    ->where('id', $id)
                    ->update([
                        'app_org_id'              => $header->app_org_id ?: $approverOrgId,
                        'selling_approval_status' => $status,
                        'selling_approval_date'   => $approvalDate,
                        'reject_reason'           => $rejectReason,
                        'updated_by'              => $userId,
                        'updated_at'              => now(),
                    ]);
            });
        } catch (Throwable $e) {
            Log::error('ASS-007 approval failed', ['id' => $id, 'error' => $e->getMessage()]);

            return back()->withErrors(['_error' => 'เกิดข้อผิดพลาดขณะบันทึกผลการอนุมัติ กรุณาลองใหม่'])->withInput();
        }

        abort_if($updated !== 1, 404);

        return redirect()->route('asset.disposals.approval.index')->with('success', 'บันทึกผลการอนุมัติเรียบร้อย');
    }


    // ──────────────────────────────────────────────────────────────────────
    //  DETAIL
    // ──────────────────────────────────────────────────────────────────────

    public function show(int $id): View
    {
        $disposal = DB::connection('oracle')
            ->table('ASSET_SELLING AS s')
            ->leftJoin('GLB_ORGANIZATION AS rorg', 'rorg.org_id', '=', 's.req_org_id')
            ->leftJoin('GLB_ORGANIZATION AS aorg', 'aorg.org_id', '=', 's.app_org_id')
            ->selectRaw("
                s.id,
                s.selling_code,
                s.selling_req_date,
                CASE WHEN s.selling_req_date IS NOT NULL THEN
                    TO_CHAR(s.selling_req_date,'DD-MM-')||TO_CHAR(s.selling_req_date+INTERVAL '543' YEAR(3),'YYYY')
                END AS req_date_th,
                rorg.org_name AS req_org_name,
                aorg.org_name AS app_org_name,
                s.reason,
                s.remarks,
                s.selling_approval_status,
                s.selling_approval_date,
                CASE WHEN s.selling_approval_date IS NOT NULL THEN
                    TO_CHAR(s.selling_approval_date,'DD-MM-')||TO_CHAR(s.selling_approval_date+INTERVAL '543' YEAR(3),'YYYY')
                END AS approval_date_th,
                s.reject_reason,
                s.buyer
            ")
            ->where('s.id', $id)
            ->first();

        abort_if(! $disposal, 404);

        $items = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST AS sl')
            ->join('ASSET AS a', 'a.id', '=', 'sl.ass_id')
            ->leftJoin('ASSET_CATEGORY AS c', 'c.id', '=', 'a.asscat_id')
            ->selectRaw("
                sl.id,
                sl.selling_min_price,
                sl.selling_real_price,
                a.ass_code,
                a.ass_price,
                a.remain_price,
                c.asscat_code,
                c.asscat_name,
                (SELECT MAX(aa2.status) KEEP (DENSE_RANK LAST ORDER BY aa2.id)
                 FROM ASSET_ASSIGNMENT_LIST aal2
                 JOIN ASSET_ASSIGNMENT aa2 ON aa2.id = aal2.ass_assign_id
                 WHERE aal2.asset_id = a.id) AS aa_status
            ")
            ->where('sl.selling_id', $id)
            ->orderByRaw('sl.id ASC')
            ->get();

        $statusKey  = (int) ($disposal->selling_approval_status ?? 0);
        $statusInfo = self::STATUS_MAP[$statusKey] ?? self::STATUS_MAP[0];

        return view('asset.ASS-006-request-asset-disposal.show', [
            'pageTitle'   => 'รายละเอียดการแจ้งขอจำหน่ายครุภัณฑ์',
            'disposal'    => $disposal,
            'items'       => $items,
            'statusInfo'  => $statusInfo,
            'reasonLabel' => self::REASON_OPTIONS[$disposal->reason ?? ''] ?? ($disposal->reason ?? '-'),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  DESTROY (only pending records)
    // ──────────────────────────────────────────────────────────────────────

    public function destroy(int $id): RedirectResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $record = DB::connection('oracle')
            ->table('ASSET_SELLING')
            ->where('id', $id)
            ->whereIn('req_org_id', $visibleOrgIds)
            ->first();

        if (! $record) {
            return redirect()->route('asset.disposals.index')
                ->withErrors(['_error' => 'ไม่พบข้อมูลหรือไม่มีสิทธิ์']);
        }

        $isPending = $record->selling_approval_status === null || (int) $record->selling_approval_status === 0;
        if (! $isPending) {
            return redirect()->route('asset.disposals.index')
                ->withErrors(['_error' => 'ไม่สามารถลบรายการที่ผ่านการอนุมัติแล้ว']);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($id) {
                DB::connection('oracle')->table('ASSET_SELLING_LIST')->where('selling_id', $id)->delete();
                DB::connection('oracle')->table('ASSET_SELLING')->where('id', $id)->delete();
            });
        } catch (Throwable $e) {
            Log::error('AssetDisposalController::destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return redirect()->route('asset.disposals.index')
                ->withErrors(['_error' => 'เกิดข้อผิดพลาดขณะลบข้อมูล']);
        }

        return redirect()->route('asset.disposals.index')
            ->with('success', 'ลบรายการแจ้งขอจำหน่ายเรียบร้อยแล้ว');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE FORM
    // ──────────────────────────────────────────────────────────────────────

    public function create(): View
    {
        $user    = Auth::user();
        $userOrg = GlbOrganization::where('org_id', (int) $user->org_id)
                       ->first(['org_id', 'org_name']);

        return view('asset.ASS-006-request-asset-disposal.create', [
            'pageTitle'     => 'แจ้งขอจำหน่ายครุภัณฑ์',
            // ไม่ส่งเลขที่ไปแสดงแล้ว ระบบจะออกเลขให้ตอนกดยืนยันบันทึก
            // ถ้าโชว์ไว้ก่อน สองคนที่เปิดหน้าพร้อมกันจะเห็นเลขเดียวกันแล้วเข้าใจผิดว่าเป็นของตัวเอง
            'userOrg'       => $userOrg,
            'reasonOptions' => self::REASON_OPTIONS,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDIT FORM
    // ──────────────────────────────────────────────────────────────────────

    public function edit(string $requestNo): View
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $record = DB::connection('oracle')
            ->table('ASSET_SELLING AS s')
            ->leftJoin('GLB_ORGANIZATION AS rorg', 'rorg.org_id', '=', 's.req_org_id')
            ->selectRaw("
                s.id,
                s.selling_code,
                TO_CHAR(s.selling_req_date, 'YYYY-MM-DD') AS req_date_input,
                rorg.org_name AS req_org_name,
                s.reason,
                s.remarks,
                s.selling_approval_status,
                s.reject_reason
            ")
            ->where('s.selling_code', $requestNo)
            ->whereIn('s.req_org_id', $visibleOrgIds)
            ->first();

        abort_if(! $record, 404);
        // ใบที่ "ไม่อนุมัติ" (สถานะ 2) ต้องแก้แล้วส่งใหม่ได้ เดิมหน้ารายการโชว์ปุ่มแก้ไขให้
        // แต่คลิกแล้วเจอ 403 เพราะตรงนี้ยอมเฉพาะสถานะ 0 ส่วนใบที่อนุมัติแล้วยังห้ามแก้เหมือนเดิม
        abort_unless(in_array((int) ($record->selling_approval_status ?? 0), [0, 2], true), 403);

        $items = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST AS sl')
            ->join('ASSET AS a', 'a.id', '=', 'sl.ass_id')
            ->leftJoin('ASSET_CATEGORY AS c', 'c.id', '=', 'a.asscat_id')
            ->selectRaw("
                sl.id,
                a.id AS asset_id,
                a.ass_code,
                c.asscat_name AS asset_name,
                a.ass_price,
                sl.selling_min_price,
                sl.selling_real_price
            ")
            ->where('sl.selling_id', $record->id)
            ->orderByRaw('sl.id ASC')
            ->get();

        return view('asset.ASS-006-request-asset-disposal.edit', [
            'pageTitle'     => 'แก้ไขการแจ้งขอจำหน่ายครุภัณฑ์',
            'record'        => $record,
            'items'         => $items,
            'reasonOptions' => self::REASON_OPTIONS,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  UPDATE (PUT)
    // ──────────────────────────────────────────────────────────────────────

    public function update(Request $request, int $id): RedirectResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $record = DB::connection('oracle')
            ->table('ASSET_SELLING')
            ->where('id', $id)
            ->whereIn('req_org_id', $visibleOrgIds)
            ->first();

        abort_if(! $record, 404);
        $previousStatus = (int) ($record->selling_approval_status ?? 0);
        abort_unless(in_array($previousStatus, [0, 2], true), 403);

        $validated = $request->validate([
            'selling_req_date' => ['required', 'date'],
            'reason'           => ['required', 'string', 'size:1', Rule::in(array_keys(self::REASON_OPTIONS))],
            'remarks'          => ['nullable', 'string', 'max:500'],
            'asset_ids'        => ['required', 'array', 'min:1'],
            'asset_ids.*'      => ['integer', 'min:1'],
        ]);

        $newAssetIds = array_unique(array_map('intval', (array) $validated['asset_ids']));
        $user        = Auth::user();

        // Current items in this disposal
        $existingItems = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST')
            ->where('selling_id', $id)
            ->get(['id', 'ass_id']);
        $existingAssetIds = $existingItems->pluck('ass_id')->map(fn ($v) => (int) $v)->toArray();
        $existingMap      = $existingItems->keyBy(fn ($r) => (int) $r->ass_id);

        $toAdd    = array_values(array_diff($newAssetIds, $existingAssetIds));
        $toRemove = array_values(array_diff($existingAssetIds, $newAssetIds));

        // Validate new assets are eligible (existing items are grandfathered)
        if (! empty($toAdd)) {
            $reason = $validated['reason'];

            $newQuery = DB::connection('oracle')
                ->table('ASSET')
                ->whereIn('id', $toAdd)
                ->whereIn('org_id', $visibleOrgIds);

            if ($reason === '1') {
                $newQuery->where('remain_price', 1);
            }

            $validNew = $newQuery->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->toArray();

            if (count($validNew) !== count($toAdd)) {
                return back()->withErrors(['_error' => 'ครุภัณฑ์บางรายการไม่ถูกต้อง ไม่อยู่ใน Scope หรือไม่ผ่านเงื่อนไขเหตุผลที่เลือก'])->withInput();
            }

            $alreadyInOtherDisposal = DB::connection('oracle')
                ->table('ASSET_SELLING_LIST AS sl')
                ->join('ASSET_SELLING AS s', 'sl.selling_id', '=', 's.id')
                ->whereIn('sl.ass_id', $toAdd)
                ->where('s.id', '!=', $id)
                ->where(function ($q) {
                    $q->whereNull('s.selling_approval_status')
                      ->orWhere('s.selling_approval_status', 0)
                      ->orWhere('s.selling_approval_status', 1);
                })
                ->exists();

            if ($alreadyInOtherDisposal) {
                return back()->withErrors(['_error' => 'ครุภัณฑ์บางรายการอยู่ในรายการแจ้งจำหน่ายอื่นอยู่แล้ว'])->withInput();
            }
        }

        try {
            DB::connection('oracle')->transaction(function () use ($id, $validated, $toAdd, $toRemove, $existingMap, $user, $previousStatus) {
                $headerUpdate = [
                    'selling_req_date' => $validated['selling_req_date'],
                    'reason'           => $validated['reason'],
                    'remarks'          => $validated['remarks'] ?? null,
                    'updated_by'       => $user->id,
                    'updated_at'       => DB::raw('SYSTIMESTAMP'),
                ];

                // แก้ใบที่ถูกตีกลับ = ส่งเข้าคิวอนุมัติใหม่ ต้องล้างผลพิจารณาเดิมออกด้วย
                // ไม่งั้นใบจะยังค้างสถานะ "ไม่อนุมัติ" พร้อมเหตุผลเก่าที่ไม่ตรงกับข้อมูลใหม่แล้ว
                if ($previousStatus === 2) {
                    $headerUpdate['selling_approval_status'] = 0;
                    $headerUpdate['selling_approval_date']   = null;
                    $headerUpdate['reject_reason']           = null;
                }

                DB::connection('oracle')->table('ASSET_SELLING')
                    ->where('id', $id)
                    ->update($headerUpdate);

                foreach ($toRemove as $assetId) {
                    $listId = (int) $existingMap[(int) $assetId]->id;
                    DB::connection('oracle')->table('ASSET_SELLING_LIST')->where('id', $listId)->delete();
                }

                $now = now();
                foreach ($toAdd as $assetId) {
                    $listId = (int) DB::connection('oracle')->selectOne('SELECT ASSET_SELLING_LIST_SEQ.NEXTVAL AS id FROM DUAL')->id;
                    DB::connection('oracle')->table('ASSET_SELLING_LIST')->insert([
                        'id'         => $listId,
                        'selling_id' => $id,
                        'ass_id'     => $assetId,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('AssetDisposalController::update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return back()->withErrors(['_error' => 'เกิดข้อผิดพลาดขณะบันทึก กรุณาลองใหม่'])->withInput();
        }

        return redirect()->route('asset.disposals.index')
            ->with('success', $previousStatus === 2
                ? 'บันทึกการแก้ไขและส่งใบแจ้งขอจำหน่ายเข้าพิจารณาใหม่เรียบร้อย'
                : 'บันทึกการแก้ไขแจ้งขอจำหน่ายเรียบร้อย');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  STORE (Header + Lines in a single transaction)
    // ──────────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'selling_req_date' => ['required', 'date'],
            'reason'           => ['required', 'string', 'size:1', Rule::in(array_keys(self::REASON_OPTIONS))],
            'remarks'          => ['nullable', 'string', 'max:500'],
            'asset_ids'        => ['required', 'array', 'min:1'],
            'asset_ids.*'      => ['integer', 'min:1'],
        ]);

        $user          = Auth::user();
        $userId        = (int) $user->id;
        $orgId         = (int) $user->org_id;
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds($orgId);
        $assetIds      = array_unique(array_map('intval', (array) $request->input('asset_ids')));
        $reqDate       = $request->input('selling_req_date'); // Y-m-d from js-date-picker
        $reason        = $request->input('reason');
        $remarks       = $request->filled('remarks') ? trim($request->input('remarks')) : null;

        $validQuery = DB::connection('oracle')
            ->table('ASSET')
            ->whereIn('id', $assetIds)
            ->whereIn('org_id', $visibleOrgIds);

        if ($reason === '1') {
            $validQuery->where('remain_price', 1);
        }

        $validAssets = $validQuery->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (count($validAssets) !== count($assetIds)) {
            return back()->withErrors(['_error' => 'ครุภัณฑ์บางรายการไม่ถูกต้อง ไม่อยู่ใน Scope หรือไม่ผ่านเงื่อนไขเหตุผลที่เลือก'])->withInput();
        }

        // Guard: assets must not be in an active/pending disposal
        $alreadyInDisposal = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST AS sl')
            ->join('ASSET_SELLING AS s', 'sl.selling_id', '=', 's.id')
            ->whereIn('sl.ass_id', $validAssets)
            ->where(function ($q) {
                $q->whereNull('s.selling_approval_status')
                  ->orWhere('s.selling_approval_status', 0)
                  ->orWhere('s.selling_approval_status', 1);
            })
            ->exists();

        if ($alreadyInDisposal) {
            return back()->withErrors(['_error' => 'ครุภัณฑ์บางรายการอยู่ในรายการแจ้งจำหน่ายอยู่แล้ว'])->withInput();
        }

        try {
            DB::connection('oracle')->transaction(function () use (
                $userId, $orgId, $reqDate, $reason, $remarks, $validAssets
            ) {
                $now      = now();
                $newId    = (int) DB::connection('oracle')->selectOne('SELECT ASSET_SELLING_SEQ.NEXTVAL AS id FROM DUAL')->id;
                $sellCode = $this->generateSellingCode();

                DB::connection('oracle')->table('ASSET_SELLING')->insert([
                    'id'                      => $newId,
                    'selling_code'            => $sellCode,
                    'selling_req_date'        => $reqDate,
                    'reason'                  => $reason,
                    'remarks'                 => $remarks,
                    'req_org_id'              => $orgId,
                    'app_org_id'              => $orgId,
                    'selling_approval_status' => 0,
                    'created_by'              => $userId,
                    'updated_by'              => $userId,
                    'created_at'              => $now,
                    'updated_at'              => $now,
                ]);

                foreach ($validAssets as $assetId) {
                    $listId = (int) DB::connection('oracle')->selectOne('SELECT ASSET_SELLING_LIST_SEQ.NEXTVAL AS id FROM DUAL')->id;
                    DB::connection('oracle')->table('ASSET_SELLING_LIST')->insert([
                        'id'         => $listId,
                        'selling_id' => $newId,
                        'ass_id'     => $assetId,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('AssetDisposalController::store failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['_error' => 'เกิดข้อผิดพลาดขณะบันทึก กรุณาลองใหม่'])->withInput();
        }

        return redirect()->route('asset.disposals.index')
            ->with('success', 'บันทึกการแจ้งขอจำหน่ายเรียบร้อย');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  ASSET SEARCH (for create form draft list)
    // ──────────────────────────────────────────────────────────────────────

    public function searchAssets(Request $request): JsonResponse
    {
        $field         = $request->string('field', '')->value();
        $keyword       = trim($request->string('q', '')->value());
        $limit         = min((int) $request->input('limit', 20), 50);
        $disposalId    = (int) $request->input('disposal_id', 0);
        $reason        = $request->string('reason', '')->value();
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        // Exclude assets in active/pending disposals; when editing, skip the current disposal
        $inDisposalQuery = DB::connection('oracle')
            ->table('ASSET_SELLING_LIST AS sl')
            ->join('ASSET_SELLING AS s', 'sl.selling_id', '=', 's.id')
            ->where(function ($q) {
                $q->whereNull('s.selling_approval_status')
                  ->orWhere('s.selling_approval_status', 0)
                  ->orWhere('s.selling_approval_status', 1);
            });
        if ($disposalId > 0) {
            $inDisposalQuery->where('s.id', '!=', $disposalId);
        }
        $inDisposalIds = $inDisposalQuery->pluck('sl.ass_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $query = DB::connection('oracle')
            ->table('ASSET AS a')
            ->join('ASSET_CATEGORY AS c', 'a.asscat_id', '=', 'c.id')
            ->selectRaw("a.id, a.ass_code, c.asscat_name AS asset_name, a.ass_price")
            ->whereIn('a.org_id', $visibleOrgIds)
            ->orderBy('a.ass_code')
            ->limit($limit);

        // reason='1' (หมดอายุการใช้งาน) requires remaining value = 1 exactly
        if ($reason === '1') {
            $query->where('a.remain_price', 1);
        }

        if (!empty($inDisposalIds)) {
            $query->whereNotIn('a.id', $inDisposalIds);
        }

        if ($keyword !== '') {
            $escaped = $this->escapeLike($keyword);
            $colMap  = ['code' => 'a.ass_code', 'name' => 'c.asscat_name'];
            if (isset($colMap[$field])) {
                $col = $colMap[$field];
                $query->whereRaw("UPPER({$col}) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
            } else {
                $query->where(function ($q) use ($escaped) {
                    $q->whereRaw("UPPER(a.ass_code)    LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"])
                      ->orWhereRaw("UPPER(c.asscat_name) LIKE UPPER(?) ESCAPE '\\'", ["%{$escaped}%"]);
                });
            }
        }

        $rows = $query->get()->map(fn ($r) => [
            'id'         => (int) $r->id,
            'ass_code'   => $r->ass_code ?? '',
            'asset_name' => $r->asset_name ?? '',
            'ass_price'  => $r->ass_price !== null ? number_format((float) $r->ass_price, 2) : '-',
        ]);

        return response()->json(['data' => $rows]);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────────────────────

    /**
     * เลขที่ใบขอจำหน่ายรูปแบบ SL{BB}{NNN} เช่น SL69001 (ปี พ.ศ. 2 หลักท้าย)
     *
     * ของเดิมใช้ปี 4 หลัก (SL2569001) ใบเก่ายังคงเลขเดิมไว้ ไม่แก้ย้อนหลัง
     * แต่เลขรันต้องนับต่อจากใบเก่าของปีเดียวกันด้วย ไม่งั้นจะวนกลับไปชนเลขที่มีอยู่แล้ว
     *
     * เรียกจากภายใน transaction ของ store() เท่านั้น ไม่ได้โชว์ให้ผู้ใช้เห็นล่วงหน้าแล้ว
     * เพื่อกันสองคนเปิดหน้าสร้างพร้อมกันแล้วเห็นเลขเดียวกัน
     */
    private function generateSellingCode(): string
    {
        $thaiYear  = (int) date('Y') + 543;        // 2026 → 2569
        $shortYear = str_pad((string) ($thaiYear % 100), 2, '0', STR_PAD_LEFT);

        $maxRunning = DB::connection('oracle')
            ->table('ASSET_SELLING')
            ->where(function ($q) use ($thaiYear, $shortYear): void {
                $q->where('selling_code', 'like', 'SL' . $thaiYear . '%')
                    ->orWhere('selling_code', 'like', 'SL' . $shortYear . '%');
            })
            ->get(['selling_code'])
            ->reduce(function (int $carry, object $r) use ($thaiYear, $shortYear): int {
                $code = (string) $r->selling_code;

                if (preg_match('/^SL' . $thaiYear . '(\d{3})$/', $code, $m) === 1
                    || preg_match('/^SL' . $shortYear . '(\d{3})$/', $code, $m) === 1) {
                    return max($carry, (int) $m[1]);
                }

                return $carry;
            }, 0);

        return 'SL' . $shortYear . str_pad((string) ($maxRunning + 1), 3, '0', STR_PAD_LEFT);
    }
}
