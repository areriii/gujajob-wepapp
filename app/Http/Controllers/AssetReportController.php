<?php

namespace App\Http\Controllers;

use App\Services\OrganizationVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\AssetDepreciationCalculator;
use App\Services\XlsxReportService;

class AssetReportController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly OrganizationVisibilityService $orgVisibility,
        private readonly AssetDepreciationCalculator $depreciationCalculator,
        private readonly XlsxReportService $xlsx,
    ) {}

    /**
     * Display the asset report form
     */
    public function index(): View
    {
        return view('asset.ASS-009-print-asset-report.index', [
            'pageTitle' => 'จัดพิมพ์รายงานครุภัณฑ์',
        ]);
    }

    /**
     * Search for asset categories via autocomplete
     */
    public function searchCategories(Request $request): JsonResponse
    {
        $keyword = trim($request->string('q')->value());
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if ($keyword === '' || empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $escaped = $this->escapeLike($keyword);

        $categories = DB::connection('oracle')
            ->table('ASSET_CATEGORY')
            ->selectRaw("id, asscat_code, asscat_name")
            ->whereRaw("UPPER(asscat_code) LIKE UPPER(?) OR UPPER(asscat_name) LIKE UPPER(?)", 
                       ["%{$escaped}%", "%{$escaped}%"])
            ->orderBy('asscat_code')
            ->limit(20)
            ->get();

        $data = $categories->map(fn ($c) => [
            'id'    => $c->id,
            'code'  => $c->asscat_code,
            'name'  => $c->asscat_name,
            'label' => "{$c->asscat_code} {$c->asscat_name}",
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Search for assets via autocomplete
     */
    public function searchAssets(Request $request): JsonResponse
    {
        $keyword = trim($request->string('q')->value());
        $categoryId = (int) $request->input('category_id', 0);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        // An asset code is only searchable within a chosen category.
        if ($keyword === '' || empty($visibleOrgIds) || $categoryId <= 0) {
            return response()->json(['data' => []]);
        }

        $escaped = $this->escapeLike($keyword);

        $assets = DB::connection('oracle')
            ->table('ASSET')
            ->join('ASSET_CATEGORY', 'ASSET.asscat_id', '=', 'ASSET_CATEGORY.id')
            ->selectRaw("ASSET.id, ASSET.ass_code, ASSET_CATEGORY.asscat_name, ASSET_CATEGORY.asscat_code,
                (SELECT MAX(aa2.status) KEEP (DENSE_RANK LAST ORDER BY aa2.id)
                 FROM ASSET_ASSIGNMENT_LIST aal2
                 JOIN ASSET_ASSIGNMENT aa2 ON aa2.id = aal2.ass_assign_id
                 WHERE aal2.asset_id = ASSET.id) AS aa_status")
            ->whereIn('ASSET.org_id', $visibleOrgIds)
            ->where('ASSET.asscat_id', $categoryId)
            ->whereRaw("UPPER(ASSET.ass_code) LIKE UPPER(?)", ["%{$escaped}%"])
            ->orderBy('ASSET.ass_code')
            ->limit(20)
            ->get();

        $data = $assets->map(fn ($a) => [
            'id'    => $a->id,
            'code'  => ((string) ($a->aa_status ?? '') === '2' && trim((string) $a->ass_code) !== '')
                ? trim($a->asscat_code . ' ' . $a->ass_code)
                : $a->asscat_code,
            'name'  => $a->asscat_name,
            'label' => (((string) ($a->aa_status ?? '') === '2' && trim((string) $a->ass_code) !== '')
                ? trim($a->asscat_code . ' ' . $a->ass_code)
                : $a->asscat_code) . " — {$a->asscat_name}",
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Search for organizations via autocomplete
     */
    public function searchOrganizations(Request $request): JsonResponse
    {
        $keyword = trim($request->string('q')->value());
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if ($keyword === '' || empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $escaped = $this->escapeLike($keyword);

        $orgs = DB::connection('oracle')
            ->table('GLB_ORGANIZATION')
            ->select('org_id', 'org_name')
            ->whereIn('org_id', $visibleOrgIds)
            ->whereRaw("UPPER(org_name) LIKE UPPER(?)", ["%{$escaped}%"])
            ->orderBy('org_name')
            ->limit(20)
            ->get();

        $data = $orgs->map(fn ($o) => [
            'id'    => $o->org_id,
            'name'  => $o->org_name,
            'label' => $o->org_name,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Search for sub-organizations via autocomplete
     */
    public function searchSubOrganizations(Request $request): JsonResponse
    {
        $keyword = trim($request->string('q')->value());
        $parentOrgId = (int) $request->input('parent_org_id', 0);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if ($keyword === '' || empty($visibleOrgIds)) {
            return response()->json(['data' => []]);
        }

        $escaped = $this->escapeLike($keyword);

        $query = DB::connection('oracle')
            ->table('GLB_ORGANIZATION')
            ->select('org_id', 'org_name')
            ->whereIn('org_id', $visibleOrgIds)
            ->whereRaw("UPPER(org_name) LIKE UPPER(?)", ["%{$escaped}%"]);

        // If a parent org is specified, filter by hierarchy
        if ($parentOrgId > 0) {
            $query->where('org_org_id', $parentOrgId);
        }

        $subOrgs = $query->orderBy('org_name')
            ->limit(20)
            ->get();

        $data = $subOrgs->map(fn ($o) => [
            'id'    => $o->org_id,
            'name'  => $o->org_name,
            'label' => $o->org_name,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Generate asset register report (Report Type 1)
     */
    public function reportAssetRegister(Request $request): View|RedirectResponse
    {
        $categoryId = (int) $request->input('category_id', 0);
        $assetId = (int) $request->input('asset_id', 0);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if ($assetId > 0 && ($error = $this->validateAssetBelongsToCategory($assetId, $categoryId, $visibleOrgIds)) !== null) {
            return redirect()
                ->route('asset.reports.index')
                ->withErrors(['report' => $error]);
        }

        $assets = $this->attachDepreciationData(
            $this->assetRegisterQuery($visibleOrgIds, $categoryId, $assetId)->orderBy('ASSET.ass_code')->get(),
            $request
        );

        return view('asset.ASS-009-print-asset-report.report-register', [
            'pageTitle' => 'ทะเบียนคุมครุภัณฑ์',
            'assets'    => $assets,
            'generatedAt' => now('Asia/Bangkok')->addYears(543)->format('d/m/Y H.i') . ' น.',
        ]);
    }

    public function downloadAssetRegister(Request $request)
    {
        $categoryId = (int) $request->input('category_id', 0);
        $assetId = (int) $request->input('asset_id', 0);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if ($assetId > 0 && ($error = $this->validateAssetBelongsToCategory($assetId, $categoryId, $visibleOrgIds)) !== null) {
            return redirect()->route('asset.reports.index')->withErrors(['report' => $error]);
        }

        $assets = $this->attachDepreciationData(
            $this->assetRegisterQuery($visibleOrgIds, $categoryId, $assetId)->orderBy('ASSET.ass_code')->get(),
            $request
        );
        $generatedAt = now('Asia/Bangkok')->addYears(543)->format('d/m/Y H.i') . ' น.';

        $pdf = Pdf::loadView('asset.ASS-009-print-asset-report.report-register-pdf', [
            'assets' => $assets,
            'generatedAt' => $generatedAt,
        ]);

        return config('app.pdf_test_mode')
            ? $pdf->stream('asset-register-report.pdf')
            : $pdf->download('asset-register-report.pdf');
    }

    public function exportAssetRegister(Request $request)
    {
        $categoryId = (int) $request->input('category_id', 0);
        $assetId = (int) $request->input('asset_id', 0);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);
        $error = $this->validateAssetBelongsToCategory($assetId, $categoryId, $visibleOrgIds);

        if ($error !== null) {
            return redirect()->route('asset.reports.index')->withErrors(['report' => $error]);
        }

        $assets = $this->attachDepreciationData(
            $this->assetRegisterQuery($visibleOrgIds, $categoryId, $assetId)->orderBy('ASSET.ass_code')->get(),
            $request
        );

        if ($assets->isEmpty()) {
            return redirect()->route('asset.reports.index')->withErrors(['report' => 'ไม่พบข้อมูลสำหรับสร้างไฟล์รายงาน']);
        }

        $sheetAssets = $assets->map(fn ($asset) => $this->assetRegisterExportRow($asset))->values()->all();

        if ($this->xlsxTestRequested($request)) {
            return response()->view('asset.ASS-009-print-asset-report.excel-layout-test', [
                'assets' => $sheetAssets,
                'layout' => $this->xlsx->assetRegisterLayoutSpec($sheetAssets),
            ]);
        }

        return $this->xlsx->structuredAssetRegister('asset-register-report.xlsx', $sheetAssets);
    }

    private function xlsxTestRequested(Request $request): bool
    {
        return in_array(app()->environment(), ['local', 'testing'], true)
            && $request->boolean('xlsx_test');
    }

    private function assetRegisterExportRow(object $asset): array
    {
        $description = collect([
            $asset->ass_desc,
            $asset->ass_model ? 'รุ่น: ' . $asset->ass_model : null,
            $asset->ass_serail ? 'Serial No.: ' . $asset->ass_serail : null,
        ])->filter()->implode("\n");

        return [
            'government_department' => strtoupper(trim((string) ($asset->org_zone_flg ?? ''))) === 'C'
                ? 'กรมส่งเสริมสหกรณ์' : ($asset->org_name ?? '-'),
            'org_name' => $asset->org_name ?? '-',
            'asscat_name' => $asset->asscat_name ?? '-',
            'asset_code' => trim((string) ($asset->asscat_code ?? '') . ' ' . (string) ($asset->ass_code ?? '')),
            'sub_org_name' => $asset->sub_org_name ?? '-',
            'dealer_name' => $asset->dealer_name ?? '-',
            'inspect_date_th' => $asset->inspect_date_th ?? '-',
            'document' => collect([$asset->ass_contact_no, $asset->ass_contact_date_th])->filter()->implode(' / ') ?: '-',
            'description' => $description !== '' ? $description : '-',
            'ass_price' => $asset->ass_price !== null ? number_format((float) $asset->ass_price, 2, '.', '') : '0.00',
            'ass_lifetime' => $asset->ass_lifetime !== null ? (string) (int) $asset->ass_lifetime : '0',
            'depreciation_rate' => $asset->depreciation_rate !== null ? number_format((float) $asset->depreciation_rate, 2, '.', '') : '0.00',
            'annual_depreciation' => number_format((float) ($asset->annual_depreciation ?? 0), 2, '.', ''),
            'remarks' => $asset->remarks ?? '-',
            'depreciation_periods' => collect($asset->depreciation_periods ?? [])->map(fn ($period) => [
                'label' => $period['label'] ?? '-',
                'depreciation' => number_format((float) ($period['depreciation'] ?? 0), 2, '.', ''),
                'accumulated' => number_format((float) ($period['accumulated'] ?? 0), 2, '.', ''),
                'net_value' => number_format((float) ($period['net_value'] ?? 0), 2, '.', ''),
            ])->all(),
        ];
    }

    private function assetRegisterQuery(array $visibleOrgIds, int $categoryId, int $assetId): \Illuminate\Database\Query\Builder
    {
        $query = DB::connection('oracle')
            ->table('ASSET')
            ->join('ASSET_CATEGORY', 'ASSET.asscat_id', '=', 'ASSET_CATEGORY.id')
            ->leftJoin('GLB_ORGANIZATION AS ORG', 'ASSET.org_id', '=', 'ORG.org_id')
            ->leftJoin('GLB_ORGANIZATION AS SUB_ORG', 'ASSET.sub_org_id', '=', 'SUB_ORG.org_id')
            ->leftJoin('DEALER', 'ASSET.dealer_id', '=', 'DEALER.id')
            ->selectRaw("ASSET.id, ASSET.ass_code, ASSET.ass_desc, ASSET.ass_model, ASSET.ass_serail,
                ASSET.inspect_date, TO_CHAR(ASSET.inspect_date, 'DD/MM/YYYY') AS inspect_date_th,
                ASSET.ass_price, ASSET.ass_lifetime, ASSET.ass_contact_no,
                TO_CHAR(ASSET.ass_contact_date, 'DD/MM/YYYY') AS ass_contact_date_th,
                ASSET.remarks, ASSET.created_at, ASSET_CATEGORY.asscat_code, ASSET_CATEGORY.asscat_name,
                ASSET_CATEGORY.depreciation_rate, ORG.org_name, ORG.zone_flg AS org_zone_flg,
                SUB_ORG.org_name AS sub_org_name, DEALER.dealer_name,
                (SELECT MAX(aa2.status) KEEP (DENSE_RANK LAST ORDER BY aa2.id)
                 FROM ASSET_ASSIGNMENT_LIST aal2
                 JOIN ASSET_ASSIGNMENT aa2 ON aa2.id = aal2.ass_assign_id
                 WHERE aal2.asset_id = ASSET.id) AS aa_status")
            ->whereIn('ASSET.org_id', $visibleOrgIds);

        return $query->when($categoryId > 0, fn ($q) => $q->where('ASSET.asscat_id', $categoryId))
            ->when($assetId > 0, fn ($q) => $q->where('ASSET.id', $assetId));
    }

    private function attachDepreciationData(\Illuminate\Support\Collection $assets, Request $request): \Illuminate\Support\Collection
    {
        return $assets->map(function ($asset) use ($request) {
            $startDate = $asset->created_at ? \Carbon\Carbon::parse($asset->created_at) : null;
            $fiscalYear = (int) $request->input('fiscal_year', 0);
            if ($fiscalYear <= 0 && $startDate !== null) {
                $fiscalYear = $startDate->month >= 10 ? $startDate->year + 544 : $startDate->year + 543;
            }
            $calc = $this->depreciationCalculator->calculate((float) ($asset->ass_price ?? 0), (int) ($asset->ass_lifetime ?? 0), $startDate, $fiscalYear);
            $asset->annual_depreciation = $calc['annual'];
            $asset->depreciation_periods = $calc['periods'];
            return $asset;
        });
    }

    /**
     * Generate asset ledger report (Report Type 2)
     */
    public function reportAssetLedger(Request $request): View|RedirectResponse
    {
        $data = $this->assetLedgerData($request);

        return view('asset.ASS-009-print-asset-report.report-ledger', [
            'pageTitle'   => 'รายงานทะเบียนครุภัณฑ์',
            ...$data,
            'pdfUrl'      => route('asset.reports.ledger.download', $request->only(['fiscal_year', 'org_id', 'sub_org_id'])),
        ]);
    }

    public function downloadAssetLedger(Request $request)
    {
        $data = $this->assetLedgerData($request);

        $pdf = Pdf::loadView('asset.ASS-009-print-asset-report.report-ledger-pdf', $data)
            ->setPaper('a4', 'landscape');

        return config('app.pdf_test_mode')
            ? $pdf->stream('asset-ledger-report.pdf')
            : $pdf->download('asset-ledger-report.pdf');
    }

    public function exportAssetLedger(Request $request)
    {
        \Log::info('exportAssetLedger: START');
        $data = $this->assetLedgerData($request);
        \Log::info('exportAssetLedger: assetLedgerData returned, assets count: ' . count($data['assets']->getCollection()));
        $rows = $data['assets']->getCollection()->values()->map(function ($asset, $index) use ($data) {
            $code = trim((string) ($asset->asscat_code ?? '')) . ' ' . trim((string) ($asset->ass_code ?? ''));

            return [
                $index + 1,
                $data['fiscalYear'],
                $asset->inspect_date_th,
                $asset->asscat_group,
                $asset->asscat_name,
                trim($code),
                $asset->ass_desc,
                $asset->ass_model,
                $asset->ass_serail,
                1,
                $asset->asscat_unit,
                $asset->ass_price,
                $asset->sub_org_name,
                $asset->remarks,
                $asset->status_label,
            ];
        })->all();

        \Log::info('exportAssetLedger: mapped rows, count: ' . count($rows));

        if (in_array(app()->environment(), ['local', 'testing'], true) && $request->boolean('xlsx_test')) {
            \Log::info('exportAssetLedger: TEST MODE requested');
            return response()->view('asset.ASS-009-print-asset-report.excel-ledger-layout-test', [
                'headers' => ['ลำดับ', 'ปีงบประมาณ', 'วันที่ตรวจรับ', 'หมวดครุภัณฑ์', 'ประเภทครุภัณฑ์', 'รหัสครุภัณฑ์', 'รายการ/รายละเอียด', 'ยี่ห้อ/รุ่น', 'Serial No.', 'จำนวน', 'หน่วยนับ', 'ราคาที่ได้มาต่อหน่วย', 'กลุ่ม/ฝ่าย', 'หมายเหตุ', 'สถานะ'],
                'rows' => $rows,
                'reportOrgLine' => $data['reportOrgLine'],
                'fiscalYear' => $data['fiscalYear'],
            ]);
        }

        \Log::info('exportAssetLedger: calling xlsx->download()');
        return $this->xlsx->download(
            'asset-ledger-report.xlsx',
            ['ลำดับ', 'ปีงบประมาณ', 'วันที่ตรวจรับ', 'หมวดครุภัณฑ์', 'ประเภทครุภัณฑ์', 'รหัสครุภัณฑ์', 'รายการ/รายละเอียด', 'ยี่ห้อ/รุ่น', 'Serial No.', 'จำนวน', 'หน่วยนับ', 'ราคาที่ได้มาต่อหน่วย', 'กลุ่ม/ฝ่าย', 'หมายเหตุ', 'สถานะ'],
            $rows,
            'รายงานทะเบียนครุภัณฑ์ ประจำปีงบประมาณ พ.ศ. ' . $data['fiscalYear']
        );
    }

    /** @return array{assets: \Illuminate\Pagination\LengthAwarePaginator, fiscalYear: int, orgId: int, subOrgId: int, reportOrgLine: string} */
    private function assetLedgerData(Request $request): array
    {
        $fiscalYear = (int) $request->input('fiscal_year', 0);
        $orgId = (int) $request->input('org_id', 0);
        $subOrgId = (int) $request->input('sub_org_id', 0);
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (($error = $this->validateOrganizationPair($orgId, $subOrgId, $visibleOrgIds)) !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['report' => $error]);
        }

        $ceYear = $fiscalYear - 543;

        $completedDisposals = DB::connection('oracle')
            ->table('ASSET_SELLING AS s')
            ->join('ASSET_SELLING_LIST AS sl', 'sl.selling_id', '=', 's.id')
            ->selectRaw("sl.ass_id, CASE WHEN s.reason = '3' THEN '6' ELSE '4' END AS final_status")
            ->whereIn('s.reason', ['1', '2'])
            ->where('s.selling_approval_status', 1)
            ->groupBy('s.id', 'sl.ass_id', 's.reason', 's.buyer')
            ->havingRaw("COUNT(*) > 0 AND COUNT(*) = COUNT(sl.selling_real_price) AND TRIM(s.buyer) IS NOT NULL")
            ->unionAll(
                DB::connection('oracle')
                    ->table('ASSET_SELLING AS s')
                    ->join('ASSET_SELLING_LIST AS sl', 'sl.selling_id', '=', 's.id')
                    ->selectRaw("sl.ass_id, '6' AS final_status")
                    ->where('s.reason', '3')
                    ->where('s.selling_approval_status', 1)
                    ->groupBy('s.id', 'sl.ass_id', 's.reason')
                    ->havingRaw('COUNT(*) > 0 AND COUNT(*) = COUNT(sl.selling_real_price)')
            );

        $query = DB::connection('oracle')
            ->table('ASSET')
            ->join('ASSET_CATEGORY', 'ASSET.asscat_id', '=', 'ASSET_CATEGORY.id')
            ->leftJoin('GLB_ORGANIZATION', 'ASSET.org_id', '=', 'GLB_ORGANIZATION.org_id')
            ->leftJoin('GLB_ORGANIZATION AS SUB_ORG', 'ASSET.sub_org_id', '=', 'SUB_ORG.org_id')
            ->leftJoinSub($completedDisposals, 'completed_disposals', 'completed_disposals.ass_id', '=', 'ASSET.id')
            ->selectRaw("ASSET.id, ASSET.ass_code, ASSET_CATEGORY.asscat_code, ASSET_CATEGORY.asscat_name,
                ASSET.ass_desc, ASSET.inspect_date,
                TO_CHAR(ASSET.inspect_date, 'DD-MM-') || TO_CHAR(ASSET.inspect_date + INTERVAL '543' YEAR(3), 'YYYY') AS inspect_date_th,
                ASSET.ass_price, ASSET.remain_price, ASSET.ass_status, ASSET.ass_model, ASSET.ass_serail,
                ASSET_CATEGORY.asscat_group, ASSET_CATEGORY.asscat_unit, ASSET.remarks,
                GLB_ORGANIZATION.org_name, SUB_ORG.org_name AS sub_org_name,
                CASE WHEN completed_disposals.final_status IS NOT NULL THEN completed_disposals.final_status ELSE ASSET.ass_status END AS effective_status,
                CASE WHEN completed_disposals.final_status IS NOT NULL THEN completed_disposals.final_status ELSE ASSET.ass_status END AS status_value,
                CASE WHEN COALESCE(completed_disposals.final_status, ASSET.ass_status) = '1' THEN 'ใช้งานได้'
                     WHEN COALESCE(completed_disposals.final_status, ASSET.ass_status) = '2' THEN 'ปกติ'
                     WHEN COALESCE(completed_disposals.final_status, ASSET.ass_status) = '3' THEN 'พร้อมจำหน่าย'
                     WHEN COALESCE(completed_disposals.final_status, ASSET.ass_status) = '4' THEN 'จำหน่ายแล้ว'
                     WHEN COALESCE(completed_disposals.final_status, ASSET.ass_status) = '5' THEN 'ชำรุด'
                     WHEN COALESCE(completed_disposals.final_status, ASSET.ass_status) = '6' THEN 'สูญหาย'
                     ELSE 'อื่น ๆ' END AS status_label")
            ->whereIn('ASSET.org_id', $visibleOrgIds)
            ->whereRaw('ASSET.inspect_date >= ? AND ASSET.inspect_date <= ?', ["{$ceYear}-10-01", ($ceYear + 1) . '-09-30'])
            ->whereRaw("COALESCE(completed_disposals.final_status, ASSET.ass_status) = ?", ['2'])
            // The selected parent is validated against the child hierarchy above;
            // assets are stored against the selected child organization.
            ->when($subOrgId > 0, fn ($q) => $q->where('ASSET.org_id', $subOrgId));

        return [
            'assets' => $query->orderBy('ASSET.ass_code')->paginate(self::PER_PAGE),
            'fiscalYear' => $ceYear,
            'orgId' => $orgId,
            'subOrgId' => $subOrgId,
            'reportOrgLine' => $this->organizationLine($orgId, $subOrgId),
        ];
    }

    private function organizationLine(int $orgId, int $subOrgId): string
    {
        $ids = array_values(array_filter([$orgId, $subOrgId]));
        if ($ids === []) {
            return '';
        }

        $names = DB::connection('oracle')
            ->table('GLB_ORGANIZATION')
            ->whereIn('org_id', $ids)
            ->pluck('org_name', 'org_id');

        return collect($ids)->map(fn (int $id) => $names[$id] ?? null)->filter()->implode(' ');
    }

    /**
     * Ensure the requested asset really belongs to the requested category and is visible to the user.
     * Returns an error message, or null when the pair is valid.
     *
     * @param  array<int, int>  $visibleOrgIds
     */
    private function validateAssetBelongsToCategory(int $assetId, int $categoryId, array $visibleOrgIds): ?string
    {
        if ($categoryId <= 0) {
            return 'กรุณาเลือกประเภทครุภัณฑ์ก่อนเลือกรหัสครุภัณฑ์';
        }

        if (empty($visibleOrgIds)) {
            return 'ไม่พบครุภัณฑ์ที่เลือกในหน่วยงานที่ท่านมีสิทธิ์เข้าถึง';
        }

        $asset = DB::connection('oracle')
            ->table('ASSET')
            ->select('asscat_id')
            ->where('id', $assetId)
            ->whereIn('org_id', $visibleOrgIds)
            ->first();

        if ($asset === null) {
            return 'ไม่พบครุภัณฑ์ที่เลือกในหน่วยงานที่ท่านมีสิทธิ์เข้าถึง';
        }

        if ((int) $asset->asscat_id !== $categoryId) {
            return 'รหัสครุภัณฑ์ที่เลือกไม่อยู่ในประเภทครุภัณฑ์ที่เลือก';
        }

        return null;
    }

    /** @param array<int, int> $visibleOrgIds */
    private function validateOrganizationPair(int $orgId, int $subOrgId, array $visibleOrgIds): ?string
    {
        if ($orgId <= 0 || $subOrgId <= 0) {
            return 'กรุณาเลือกหน่วยงานและหน่วยงานย่อย';
        }

        if (!in_array($orgId, $visibleOrgIds, true) || !in_array($subOrgId, $visibleOrgIds, true)) {
            return 'หน่วยงานที่เลือกอยู่นอกขอบเขตสิทธิ์ของผู้ใช้';
        }

        $isChild = DB::connection('oracle')
            ->table('GLB_ORGANIZATION')
            ->where('org_id', $subOrgId)
            ->where('org_org_id', $orgId)
            ->exists();

        return $isChild ? null : 'หน่วยงานย่อยไม่อยู่ภายใต้หน่วยงานที่เลือก';
    }

    /**
     * Helper to escape LIKE wildcards
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

