<?php

namespace App\Http\Controllers;

use App\Models\AssetCategory;
use App\Models\Dealer;
use App\Models\GlbOrganization;
use App\Models\Material;
use App\Models\MaterialInspection;
use App\Models\MaterialProcurement;
use App\Models\MaterialWithdrawn;
use App\Services\OrganizationVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SearchSuggestionsController extends Controller
{
    public function __construct(private readonly OrganizationVisibilityService $orgVisibility) {}

    /**
     * Unified autocomplete suggestions endpoint for all list-page search bars.
     *
     * Query Parameters:
     *   entity  — material | receipt | withdrawal | transfer | dealer | asset_category
     *   type    — field type matching the search_by options in the form
     *   q       — search keyword (partial match, case-insensitive)
     *   limit   — max results (default 15, max 30)
     */
    public function index(Request $request): JsonResponse
    {
        $entity = $request->string('entity')->value();
        $type   = $request->string('type')->value();
        $q      = trim($request->string('q')->value());
        $limit  = min((int) $request->input('limit', 15), 30);

        if ($q === '' && !in_array($entity, ['assign_org', 'assign_user'], true)) {
            return response()->json(['data' => []]);
        }

        if ($q !== '' && mb_strlen($q) < 1) {
            return response()->json(['data' => []]);
        }

        // Truncate to prevent oversized queries
        $keyword = mb_substr($q, 0, 100);

        return match ($entity) {
            'material'       => $this->searchMaterial($type, $keyword, $limit),
            'receipt'        => $this->searchReceipt($type, $keyword, $limit),
            'withdrawal'     => $this->searchWithdrawal($type, $keyword, $limit),
            'transfer'       => $this->searchTransfer($type, $keyword, $limit),
            'dealer'         => $this->searchDealer($type, $keyword, $limit),
            'asset_category' => $this->searchAssetCategory($type, $keyword, $limit),
            'asset'          => $this->searchAsset($type, $keyword, $limit),
            'create_asscat'  => $this->searchCreateAsscat($keyword, $limit),
            'forecast_cat'   => $this->searchForecastCategory($keyword, $limit),
            'forecast_org'   => $this->searchForecastOrg($keyword, $limit),
            'assign_org'     => $this->searchAssignOrg($keyword, $limit),
            'assign_user'    => $this->searchAssignUser($keyword, $limit),
            'dealer_search'  => $this->searchDealerForAsset($keyword, $limit),
            'org_search'     => $this->searchOrgForAsset($keyword, $limit),
            default          => response()->json(['data' => []]),
        };
    }

    // ──────────────────────────────────────────────────────────
    //  Material (MAT-001 index: code | name) — global master
    // ──────────────────────────────────────────────────────────

    private function searchMaterial(string $type, string $keyword, int $limit): JsonResponse
    {
        $escaped = $this->escapeLike($keyword);

        $query = Material::query()->orderBy('mat_code');

        if ($type === 'name') {
            $query->whereRaw('UPPER(mat_name) LIKE UPPER(?)', ['%' . $escaped . '%']);
        } else {
            $query->whereRaw('UPPER(mat_code) LIKE UPPER(?)', ['%' . $escaped . '%']);
        }

        $data = $query->limit($limit)
            ->get(['mat_code', 'mat_name'])
            ->map(fn ($m) => [
                'code' => $m->mat_code,
                'name' => $m->mat_name ?? '',
            ]);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Receipt (MAT-002 index: receipt_no | received_date | organization)
    //  Transaction docs are scoped to user's visible org IDs.
    //  Organization name search uses the global GLB_ORGANIZATION.
    // ──────────────────────────────────────────────────────────

    private function searchReceipt(string $type, string $keyword, int $limit): JsonResponse
    {
        $escaped       = $this->escapeLike($keyword);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if ($type === 'organization') {
            // Org names come from global master — suggest within visible scope
            $data = GlbOrganization::query()
                ->whereIn('org_id', $visibleOrgIds)
                ->whereRaw('UPPER(org_name) LIKE UPPER(?)', ['%' . $escaped . '%'])
                ->orderBy('org_name')
                ->limit($limit)
                ->get(['org_name'])
                ->map(fn ($o) => ['code' => $o->org_name, 'name' => '']);

            return response()->json(['data' => $data]);
        }

        if ($type === 'received_date') {
            return response()->json(['data' => []]);
        }

        // receipt_no (default) — scoped to visible orgs
        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $data = MaterialProcurement::query()
            ->whereIn('org_id', $visibleOrgIds)
            ->whereRaw('UPPER(mat_pro_code) LIKE UPPER(?)', ['%' . $escaped . '%'])
            ->orderBy('mat_pro_code')
            ->limit($limit)
            ->get(['mat_pro_code'])
            ->map(fn ($r) => ['code' => $r->mat_pro_code, 'name' => '']);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Withdrawal (MAT-003 index + approval)
    //  Transaction docs are scoped to user's visible org IDs.
    // ──────────────────────────────────────────────────────────

    private function searchWithdrawal(string $type, string $keyword, int $limit): JsonResponse
    {
        $escaped       = $this->escapeLike($keyword);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if ($type === 'department') {
            $data = GlbOrganization::query()
                ->whereIn('org_id', $visibleOrgIds)
                ->whereRaw('UPPER(org_name) LIKE UPPER(?)', ['%' . $escaped . '%'])
                ->orderBy('org_name')
                ->limit($limit)
                ->get(['org_name'])
                ->map(fn ($o) => ['code' => $o->org_name, 'name' => '']);

            return response()->json(['data' => $data]);
        }

        if ($type === 'withdraw_date') {
            return response()->json(['data' => []]);
        }

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        if ($type === 'requester') {
            $data = MaterialWithdrawn::query()
                ->whereIn('org_id', $visibleOrgIds)
                ->whereRaw('UPPER(mat_wd_person) LIKE UPPER(?)', ['%' . $escaped . '%'])
                ->distinct()
                ->orderBy('mat_wd_person')
                ->limit($limit)
                ->get(['mat_wd_person'])
                ->map(fn ($w) => ['code' => $w->mat_wd_person, 'name' => '']);

            return response()->json(['data' => $data]);
        }

        // withdraw_no (default)
        $data = MaterialWithdrawn::query()
            ->whereIn('org_id', $visibleOrgIds)
            ->whereRaw('UPPER(mat_wd_code) LIKE UPPER(?)', ['%' . $escaped . '%'])
            ->orderBy('mat_wd_code')
            ->limit($limit)
            ->get(['mat_wd_code'])
            ->map(fn ($w) => ['code' => $w->mat_wd_code, 'name' => '']);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Transfer (MAT-004 index: wd_code — approved withdrawals)
    //  Scoped to user's visible org IDs.
    // ──────────────────────────────────────────────────────────

    private function searchTransfer(string $type, string $keyword, int $limit): JsonResponse
    {
        $escaped       = $this->escapeLike($keyword);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $data = MaterialWithdrawn::query()
            ->where('status', MaterialWithdrawn::STATUS_APPROVED)
            ->whereIn('org_id', $visibleOrgIds)
            ->whereRaw('UPPER(mat_wd_code) LIKE UPPER(?)', ['%' . $escaped . '%'])
            ->orderBy('mat_wd_code')
            ->limit($limit)
            ->get(['mat_wd_code'])
            ->map(fn ($w) => ['code' => $w->mat_wd_code, 'name' => '']);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Asset Category (ASS-001 index)
    //  Whitelist search_type → column mapping.
    // ──────────────────────────────────────────────────────────

    private function searchAssetCategory(string $type, string $keyword, int $limit): JsonResponse
    {
        $escaped = $this->escapeLike($keyword);

        /** Whitelist: blade value → DB column */
        $columnMap = [
            'code'  => 'asscat_code',
            'name'  => 'asscat_name',
            'type'  => 'asscat_type',
            'group' => 'asscat_group',
            'unit'  => 'asscat_unit',
        ];

        if ($type === 'all') {
            // Search all text columns; return code + name as the suggestion label
            $data = AssetCategory::query()
                ->where(function ($q) use ($escaped): void {
                    $q->whereRaw('UPPER(asscat_code)  LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('UPPER(asscat_name)  LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('UPPER(asscat_type)  LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('UPPER(asscat_group) LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('UPPER(asscat_unit)  LIKE UPPER(?)', ['%' . $escaped . '%']);
                })
                ->orderBy('asscat_code')
                ->limit($limit)
                ->get(['asscat_code', 'asscat_name'])
                ->map(fn ($c) => [
                    'code' => $c->asscat_code,
                    'name' => $c->asscat_name ?? '',
                ]);

            return response()->json(['data' => $data]);
        }

        if (!array_key_exists($type, $columnMap)) {
            // Unknown search type — return empty (fail-safe, no arbitrary column query)
            return response()->json(['data' => []]);
        }

        $col  = $columnMap[$type];
        $data = AssetCategory::query()
            ->whereRaw("UPPER({$col}) LIKE UPPER(?)", ['%' . $escaped . '%'])
            ->orderByRaw("UPPER({$col})")
            ->limit($limit)
            ->get([$col])
            ->map(fn ($c) => ['code' => $c->{$col} ?? '', 'name' => ''])
            ->unique('code')
            ->values();

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Dealer / Supplier (ASS-002 index)
    //  Blade dropdown values: all | name | type | tax_id | contact | phone
    // ──────────────────────────────────────────────────────────

    private function searchDealer(string $type, string $keyword, int $limit): JsonResponse
    {
        $escaped = $this->escapeLike($keyword);

        /** Whitelist: blade value → DB column */
        $columnMap = [
            'name'    => ['col' => 'dealer_name',    'upper' => true],
            'type'    => ['col' => 'dealer_type',    'upper' => true],
            'tax_id'  => ['col' => 'dealer_tax_id',  'upper' => true],
            'contact' => ['col' => 'dealer_contact', 'upper' => true],
            'phone'   => ['col' => 'dealer_phone',   'upper' => false],
        ];

        if ($type === 'type') {
            // DEALER_TYPES stores codes; suggest by label text (no DB query needed)
            $matching = array_values(array_filter(
                Dealer::DEALER_TYPES,
                fn(string $label) => mb_stripos($label, $keyword) !== false
            ));
            $data = collect($matching)->map(fn ($label) => ['code' => $label, 'name' => '']);
            return response()->json(['data' => $data]);
        }

        if ($type === 'all') {
            // Also include dealers whose type label matches the keyword
            $typeCodes = array_keys(array_filter(
                Dealer::DEALER_TYPES,
                fn(string $label) => mb_stripos($label, $keyword) !== false
            ));

            $data = Dealer::query()
                ->where(function ($q) use ($escaped, $typeCodes): void {
                    $q->whereRaw('UPPER(dealer_name)    LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('UPPER(dealer_tax_id)  LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('UPPER(dealer_contact) LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('dealer_phone LIKE ?',                 ['%' . $escaped . '%']);
                    if (!empty($typeCodes)) {
                        $q->orWhereIn('dealer_type', $typeCodes);
                    }
                })
                ->orderBy('dealer_name')
                ->limit($limit)
                ->get(['dealer_name'])
                ->map(fn ($d) => ['code' => $d->dealer_name ?? '', 'name' => '']);

            return response()->json(['data' => $data]);
        }

        if (!array_key_exists($type, $columnMap)) {
            // Unknown type → fall back to dealer_name
            $type = 'name';
        }

        $meta = $columnMap[$type];
        $col  = $meta['col'];

        $query = Dealer::query();

        if ($meta['upper']) {
            $query->whereRaw("UPPER({$col}) LIKE UPPER(?)", ['%' . $escaped . '%']);
        } else {
            $query->whereRaw("{$col} LIKE ?", ['%' . $escaped . '%']);
        }

        $data = $query->distinct()
            ->orderByRaw("UPPER({$col})")
            ->limit($limit)
            ->get([$col])
            ->map(fn ($d) => ['code' => $d->{$col} ?? '', 'name' => '']);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Forecast: Category filter (หมวดครุภัณฑ์ = ASSET_CATEGORY.asscat_group)
    // ──────────────────────────────────────────────────────────

    private function searchForecastCategory(string $keyword, int $limit): JsonResponse
    {
        $data = collect(AssetCategory::groupNames())
            ->filter(fn (string $group) => $keyword === '' || mb_stripos($group, $keyword) !== false)
            ->take($limit)
            ->map(fn (string $group) => [
                'id'   => $group,
                'code' => $group,
                'name' => '',
            ])
            ->values();

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Forecast: Organization filter (scoped, returns org_id + name)
    // ──────────────────────────────────────────────────────────

    private function searchForecastOrg(string $keyword, int $limit): JsonResponse
    {
        $escaped       = $this->escapeLike($keyword);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $data = GlbOrganization::query()
            ->whereIn('org_id', $visibleOrgIds)
            ->whereRaw('UPPER(org_name) LIKE UPPER(?)', ['%' . $escaped . '%'])
            ->orderBy('org_name')
            ->limit($limit)
            ->get(['org_id', 'org_name'])
            ->map(fn ($o) => [
                'id'   => $o->org_id,
                'code' => $o->org_name,
                'name' => '',
            ]);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Asset Category full-detail (Create/Edit form panel)
    //  Returns id + all display fields for category selection.
    // ──────────────────────────────────────────────────────────

    private function searchCreateAsscat(string $keyword, int $limit): JsonResponse
    {
        $escaped = $this->escapeLike($keyword);

        $data = AssetCategory::query()
            ->where(function ($q) use ($escaped): void {
                $q->whereRaw('UPPER(asscat_code) LIKE UPPER(?)', ['%' . $escaped . '%'])
                  ->orWhereRaw('UPPER(asscat_name) LIKE UPPER(?)', ['%' . $escaped . '%']);
            })
            ->orderBy('asscat_code')
            ->limit($limit)
            ->get(['id', 'asscat_code', 'asscat_name', 'asscat_type', 'asscat_group', 'asscat_unit', 'depreciation_rate'])
            ->map(fn ($c) => [
                'id'               => $c->id,
                'code'             => $c->asscat_code . ' - ' . $c->asscat_name,
                'name'             => '',
                'asscat_code'      => $c->asscat_code ?? '',
                'asscat_name'      => $c->asscat_name ?? '',
                'asscat_type'      => $c->asscat_type ?? '',
                'asscat_group'     => $c->asscat_group ?? '',
                'asscat_unit'      => $c->asscat_unit ?? '',
                'depreciation_rate'=> $c->depreciation_rate ?? '',
            ]);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Asset (ASS-003 index): code | name | org
    //  Scoped to user's visible org IDs.
    // ──────────────────────────────────────────────────────────

    private function searchAsset(string $type, string $keyword, int $limit): JsonResponse
    {
        $escaped       = $this->escapeLike($keyword);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        /** Whitelist: blade value → qualified column */
        $columnMap = [
            'all'  => null,
            'code' => 'a.ass_code',
            'name' => 'c.asscat_name',
            'org'  => 'org.org_name',
        ];

        if (!array_key_exists($type, $columnMap)) {
            $type = 'all';
        }

        $baseQuery = \Illuminate\Support\Facades\DB::connection('oracle')
            ->table('ASSET AS a')
            ->join('ASSET_CATEGORY AS c', 'a.asscat_id', '=', 'c.id')
            ->leftJoin('GLB_ORGANIZATION AS org', 'a.org_id', '=', 'org.org_id')
            ->whereIn('a.org_id', $visibleOrgIds)
            ->orderByRaw('UPPER(a.ass_code)')
            ->limit($limit);

        if ($type === 'all') {
            $data = $baseQuery
                ->whereRaw(
                    'UPPER(a.ass_code) LIKE UPPER(?) OR UPPER(c.asscat_name) LIKE UPPER(?) OR UPPER(org.org_name) LIKE UPPER(?)',
                    ['%' . $escaped . '%', '%' . $escaped . '%', '%' . $escaped . '%']
                )
                ->selectRaw('DISTINCT a.ass_code')
                ->get()
                ->map(fn ($r) => ['code' => $r->ass_code ?? '', 'name' => '']);

            return response()->json(['data' => $data]);
        }

        $col = $columnMap[$type];

        $retAlias = match ($type) {
            'name'  => 'asscat_name',
            'org'   => 'org_name',
            default => 'ass_code',
        };

        $data = $baseQuery
            ->whereRaw("UPPER({$col}) LIKE UPPER(?)", ['%' . $escaped . '%'])
            ->selectRaw("DISTINCT {$col} AS {$retAlias}")
            ->get()
            ->map(fn ($r) => ['code' => $r->{$retAlias} ?? '', 'name' => '']);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────
    //  ASS-004: Organization within visible scope (returns value/label)
    // ──────────────────────────────────────────────────────────

    /**
     * หน่วยงานผู้รับจัดสรร (ASS-004)
     *
     * ชื่อหน่วยงานซ้ำกันเยอะมากในฐานข้อมูลจริง เช่น "กลุ่มจัดตั้งและส่งเสริมสหกรณ์"
     * มีอยู่ในแทบทุกจังหวัด เดิมรายการจึงขึ้นชื่อเดียวกันติดกันสิบกว่าบรรทัด เลือกไม่ถูก
     * จึงต่อท้ายด้วยชื่อหน่วยงานแม่ และถ้ายังซ้ำกันอีก (ข้อมูลต้นทางมีแถวซ้ำจริง ๆ)
     * ค่อยต่อท้ายด้วยรหัสหน่วยงานเพื่อให้แยกออกจากกันได้
     *
     * กรองสิทธิ์ใน PHP ไม่ใช้ whereIn เพราะผู้ใช้ระดับกรมมองเห็นหน่วยงานเกิน 1000 รายการ
     * ซึ่งเกินเพดาน IN ของ Oracle (ORA-01795)
     */
    private function searchAssignOrg(string $keyword, int $limit): JsonResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $visible = array_flip($visibleOrgIds);
        $needle = mb_strtoupper(trim($keyword));

        $rows = DB::connection('oracle')
            ->table('GLB_ORGANIZATION AS o')
            ->leftJoin('GLB_ORGANIZATION AS p', 'p.org_id', '=', 'o.org_org_id')
            ->orderBy('o.org_name')
            ->orderBy('o.org_id')
            ->get(['o.org_id', 'o.org_name', 'o.org_code', 'p.org_name AS parent_name'])
            ->filter(function ($org) use ($visible, $needle) {
                return isset($visible[(int) $org->org_id])
                    && ($needle === '' || mb_strpos(mb_strtoupper((string) $org->org_name), $needle) !== false);
            })
            ->take($limit)
            ->values();

        // ชื่อ + หน่วยงานแม่ ที่ยังชนกันอยู่ ต้องมีรหัสกำกับถึงจะเลือกได้ถูกแถว
        $seen = [];
        foreach ($rows as $org) {
            $key = $org->org_name . '|' . ($org->parent_name ?? '');
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }

        $data = $rows->map(function ($org) use ($seen) {
            $label = (string) $org->org_name;

            if (! empty($org->parent_name)) {
                $label .= ' — ' . $org->parent_name;
            }

            if (($seen[$org->org_name . '|' . ($org->parent_name ?? '')] ?? 0) > 1 && ! empty($org->org_code)) {
                $label .= ' [' . $org->org_code . ']';
            }

            return ['value' => (string) $org->org_id, 'label' => $label];
        });

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  ASS-004: Users within visible org scope
    // ──────────────────────────────────────────────────────────

    private function searchAssignUser(string $keyword, int $limit): JsonResponse
    {
        $escaped       = $this->escapeLike($keyword);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $data = \Illuminate\Support\Facades\DB::connection('oracle')
            ->table('SYS_USER')
            ->whereIn('org_id', $visibleOrgIds)
            ->whereRaw('UPPER(user_name) LIKE UPPER(?)', ['%' . $escaped . '%'])
            ->whereNotNull('user_name')
            ->orderBy('user_name')
            ->limit($limit)
            ->get(['id', 'user_name'])
            ->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->user_name ?? '']);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  ASS-003 Asset Create/Edit: Dealer/Supplier search
    // ──────────────────────────────────────────────────────────

    private function searchDealerForAsset(string $keyword, int $limit): JsonResponse
    {
        $escaped = $this->escapeLike($keyword);

        $data = Dealer::query()
            ->whereRaw('UPPER(dealer_name) LIKE UPPER(?)', ['%' . $escaped . '%'])
            ->orderBy('dealer_name')
            ->limit($limit)
            ->get(['id', 'dealer_name'])
            ->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->dealer_name ?? '']);

        return response()->json(['data' => $data]);
    }

    // ──────────────────────────────────────────────────────────
    //  ASS-003 Asset Create/Edit: Organization search (scoped)
    // ──────────────────────────────────────────────────────────

    private function searchOrgForAsset(string $keyword, int $limit): JsonResponse
    {
        $escaped       = $this->escapeLike($keyword);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $data = GlbOrganization::query()
            ->whereIn('org_id', $visibleOrgIds)
            ->whereRaw('UPPER(org_name) LIKE UPPER(?)', ['%' . $escaped . '%'])
            ->orderBy('org_name')
            ->limit($limit)
            ->get(['org_id', 'org_name'])
            ->map(fn ($o) => ['value' => (string) $o->org_id, 'label' => $o->org_name]);

        return response()->json(['data' => $data]);
    }

    /** Escape special LIKE characters in Oracle to prevent injection. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

