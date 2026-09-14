<?php

namespace App\Http\Controllers;

use App\Services\OrganizationVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use App\Services\XlsxReportService;

class MaterialReportController extends Controller
{
    public function __construct(private readonly OrganizationVisibilityService $orgVisibility, private readonly XlsxReportService $xlsx) {}

    public function form(Request $request): View
    {
        return view('material.MAT-007-print-material-report.index', [
            'pageTitle' => 'จัดพิมพ์รายงานวัสดุ',
        ]);
    }

    public function searchOrganizations(Request $request): JsonResponse
    {
        $visible = $this->orgVisibility->visibleOrgIds((int) auth()->user()->org_id);
        $q = trim($request->string('q')->value());
        if ($q === '' || empty($visible)) return response()->json(['data' => []]);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $rows = DB::connection('oracle')->table('GLB_ORGANIZATION')->whereIn('org_id', $visible)
            ->whereRaw('UPPER(org_name) LIKE UPPER(?)', ["%{$escaped}%"])
            ->orderBy('org_name')->limit(20)->get(['org_id','org_name']);
        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->org_id, 'name' => $r->org_name, 'label' => $r->org_name])]);
    }

    public function searchSubOrganizations(Request $request): JsonResponse
    {
        $visible = $this->orgVisibility->visibleOrgIds((int) auth()->user()->org_id);
        $parentId = (int) $request->input('parent_org_id', 0);
        $q = trim($request->string('q')->value());
        if ($parentId <= 0 || $q === '' || empty($visible)) return response()->json(['data' => []]);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $rows = DB::connection('oracle')->table('GLB_ORGANIZATION')->whereIn('org_id', $visible)
            ->where('org_org_id', $parentId)
            ->whereRaw('UPPER(org_name) LIKE UPPER(?)', ["%{$escaped}%"])
            ->orderBy('org_name')->limit(20)->get(['org_id','org_name']);
        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->org_id, 'name' => $r->org_name, 'label' => $r->org_name])]);
    }

    public function index(Request $request): View|RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'org_id' => ['required', 'integer', 'min:1'],
            'sub_org_id' => ['required', 'integer', 'min:1'],
        ], [
            'org_id.required' => 'กรุณากรอกข้อมูล',
            'sub_org_id.required' => 'กรุณากรอกข้อมูล',
        ])->validate();

        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) auth()->user()->org_id);
        $orgId = (int) $validated['org_id'];
        $subOrgId = (int) $validated['sub_org_id'];
        abort_unless(in_array($orgId, $visibleOrgIds, true) && in_array($subOrgId, $visibleOrgIds, true), 403);
        abort_unless(in_array($subOrgId, $this->branchIds($orgId), true), 422);
        $orgIds = $this->scopedOrgIds($visibleOrgIds, $orgId, $subOrgId);

        $materials = $this->materialRows($orgIds);

        return view('material.MAT-007-print-material-report.report', [
            'pageTitle' => 'รายงานวัสดุคงเหลือ',
            'materials' => $materials,
            'reportOrgLine' => $this->orgLine($orgId, $subOrgId),
            'asOfDate' => now('Asia/Bangkok')->addYears(543)->locale('th')->translatedFormat('j F Y'),
        ]);
    }

    public function export(Request $request)
    {
        Validator::make($request->all(), ['org_id' => ['required','integer','min:1'], 'sub_org_id' => ['required','integer','min:1']], ['org_id.required'=>'กรุณากรอกข้อมูล','sub_org_id.required'=>'กรุณากรอกข้อมูล'])->validate();
        $visible = $this->orgVisibility->visibleOrgIds((int) auth()->user()->org_id);
        $orgId = (int) $request->input('org_id'); $subOrgId = (int) $request->input('sub_org_id');
        $visible = $this->orgVisibility->visibleOrgIds((int) auth()->user()->org_id);
        abort_unless(in_array($orgId, $visible, true) && in_array($subOrgId, $visible, true) && in_array($subOrgId, $this->branchIds($orgId), true), 422);
        abort_unless(in_array($orgId, $visible, true) && in_array($subOrgId, $visible, true), 403);
        $rows = $this->materialRows($this->scopedOrgIds($visible, $orgId, $subOrgId));
        $data = $rows->map(fn ($row, $i) => [$i + 1, $row->mat_code, $row->mat_name, $row->unit, number_format((float)$row->unit_price, 2), number_format((float)$row->balance, 2)])->all();
        return $this->xlsx->download('material-balance-report.xlsx', ['ลำดับที่','รหัสวัสดุ','ชื่อวัสดุ','หน่วยนับ','ราคาต่อหน่วย','ปริมาณคงเหลือ'], $data, 'รายงานวัสดุคงเหลือ');
    }

    /** @param array<int, int> $visibleOrgIds */
    private function scopedOrgIds(array $visibleOrgIds, int $orgId, int $subOrgId): array
    {
        if ($subOrgId > 0) {
            $ids = $this->branchIds($subOrgId);
        } elseif ($orgId > 0) {
            $ids = $this->branchIds($orgId);
        } else {
            $ids = $visibleOrgIds;
        }

        return array_values(array_intersect($visibleOrgIds, $ids));
    }

    /** @param array<int, int> $orgIds */
    private function materialRows(array $orgIds)
    {
        if (empty($orgIds)) {
            return collect();
        }

        $inClause = implode(',', array_fill(0, count($orgIds), '?'));
        $approved = 2;

        $sql = "
                 SELECT t.id, t.mat_code, t.mat_name, t.unit,
                   t.balance,
                   CASE WHEN t.balance <= 0 THEN 0 ELSE t.unit_price END AS unit_price
            FROM (
                SELECT m.id,
                       m.mat_code,
                       m.mat_name,
                       m.unit,
                       (
                           NVL((SELECT SUM(p.mat_amt) FROM MATERIAL_PROCUREMENT_LIST p JOIN MATERIAL_PROCUREMENT mp ON mp.id = p.mat_pro_id WHERE p.mat_id = m.id AND mp.org_id IN ({$inClause})), 0)
                           - NVL((SELECT SUM(wdl.wd_amount) FROM MATERIAL_WITHDRAWN_LIST wdl JOIN MATERIAL_WITHDRAWN w ON w.id = wdl.mat_wd_id WHERE wdl.mat_id = m.id AND w.org_id IN ({$inClause}) AND w.status = ?), 0)
                           + NVL((SELECT SUM(i.isp_amount) FROM MATERIAL_INSPECTION_LIST i JOIN MATERIAL_INSPECTION mi ON mi.id = i.mat_isp_id JOIN MATERIAL_WITHDRAWN mw ON mw.id = mi.mat_wd_id WHERE i.mat_id = m.id AND mw.org_id IN ({$inClause}) AND mw.status = ?), 0)
                       ) AS balance,
                       NVL((
                           SELECT SUM(p.mat_amt * p.mat_price) / NULLIF(SUM(p.mat_amt), 0)
                           FROM MATERIAL_PROCUREMENT_LIST p
                           JOIN MATERIAL_PROCUREMENT mp ON mp.id = p.mat_pro_id
                           WHERE p.mat_id = m.id AND mp.org_id IN ({$inClause})
                       ), 0) AS unit_price
                FROM MATERIALS m
                WHERE EXISTS (SELECT 1 FROM MATERIAL_PROCUREMENT_LIST p JOIN MATERIAL_PROCUREMENT mp ON mp.id = p.mat_pro_id WHERE p.mat_id = m.id AND mp.org_id IN ({$inClause}))
                   OR EXISTS (SELECT 1 FROM MATERIAL_WITHDRAWN_LIST wdl JOIN MATERIAL_WITHDRAWN w ON w.id = wdl.mat_wd_id WHERE wdl.mat_id = m.id AND w.org_id IN ({$inClause}) AND w.status = ?)
                   OR EXISTS (SELECT 1 FROM MATERIAL_INSPECTION_LIST i JOIN MATERIAL_INSPECTION mi ON mi.id = i.mat_isp_id JOIN MATERIAL_WITHDRAWN mw ON mw.id = mi.mat_wd_id WHERE i.mat_id = m.id AND mw.org_id IN ({$inClause}) AND mw.status = ?)
            ) t
            WHERE t.balance > 0
            ORDER BY t.mat_code
        ";

        $params = array_merge(
            $orgIds,
            $orgIds,
            [$approved],
            $orgIds,
            [$approved],
            $orgIds,
            $orgIds,
            $orgIds,
            [$approved],
            $orgIds,
            [$approved]
        );

        return collect(DB::connection('oracle')->select($sql, $params));
    }

    private function branchIds(int $orgId): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT org_id FROM GLB_ORGANIZATION START WITH org_id = ? CONNECT BY PRIOR org_id = org_org_id',
            [$orgId]
        );
        return array_map(static fn ($row) => (int) $row->org_id, $rows) ?: [$orgId];
    }

    private function orgLine(int $orgId, int $subOrgId): string
    {
        $ids = array_values(array_filter([$orgId, $subOrgId]));
        if (!$ids) return '';
        $organizations = DB::connection('oracle')->table('GLB_ORGANIZATION')->whereIn('org_id', $ids)->pluck('org_name', 'org_id');
        return collect($ids)->map(fn (int $id) => $organizations[$id] ?? null)->filter()->implode(' ');
    }
}
