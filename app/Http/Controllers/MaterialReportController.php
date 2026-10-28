<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use App\Services\XlsxReportService;

class MaterialReportController extends Controller
{
    public function __construct(private readonly XlsxReportService $xlsx) {}

    public function form(Request $request): View
    {
        return view('material.MAT-007-print-material-report.index', [
            'pageTitle' => 'จัดพิมพ์รายงานวัสดุ',
        ]);
    }

    public function searchOrganizations(Request $request): JsonResponse
    {
        $visible = $this->allowedOrgIds();
        $q = trim($request->string('q')->value());
        if ($q === '' || empty($visible)) return response()->json(['data' => []]);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $rows = DB::connection('oracle')->table('GLB_ORGANIZATION')->whereIn('org_id', $visible)
            ->whereRaw('UPPER(org_name) LIKE UPPER(?)', ["%{$escaped}%"])
            ->orderBy('org_name')->limit(20)->get(['org_id','org_name']);
        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->org_id, 'name' => $r->org_name, 'label' => $r->org_name])]);
    }

    public function index(Request $request): View|RedirectResponse
    {
        $data = $this->reportData($request);

        return view('material.MAT-007-print-material-report.report', [
            'pageTitle' => 'รายงานวัสดุคงเหลือ',
            ...$data,
        ]);
    }

    public function downloadPdf(Request $request)
    {
        $data = $this->reportData($request);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('material.MAT-007-print-material-report.report-pdf', $data)
            ->setPaper('a4', 'portrait');

        return (config('app.pdf_test_mode') || $this->pdfTestRequested($request))
            ? $pdf->stream('material-balance-report.pdf')
            : $pdf->download('material-balance-report.pdf');
    }

    private function reportData(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'org_id' => ['required', 'integer', 'min:1'],
        ], [
            'org_id.required' => 'กรุณากรอกข้อมูล',
        ])->validate();

        $orgId = (int) $validated['org_id'];
        abort_unless(in_array($orgId, $this->allowedOrgIds(), true), 403);

        return [
            // A parent organization reports its own stock plus its child organizations'; a child only its own.
            'materials' => $this->materialRows($this->branchIds($orgId)),
            'reportOrgLine' => $this->orgLine($orgId),
            'asOfDate' => now('Asia/Bangkok')->addYears(543)->locale('th')->translatedFormat('j F Y'),
        ];
    }

    private function pdfTestRequested(Request $request): bool
    {
        return in_array(app()->environment(), ['local', 'testing'], true)
            && $request->boolean('pdf_test');
    }

    public function export(Request $request)
    {
        $rows = $this->reportData($request)['materials'];
        $data = $rows->map(fn ($row, $i) => [$i + 1, $row->mat_code, $row->mat_name, $row->unit, number_format((float)$row->unit_price, 2), number_format((float) $row->balance)])->all();
        return $this->xlsx->download('material-balance-report.xlsx', ['ลำดับที่','รหัสวัสดุ','ชื่อวัสดุ','หน่วยนับ','ราคาต่อหน่วย','ปริมาณคงเหลือ'], $data, 'รายงานวัสดุคงเหลือ');
    }

    /**
     * Organizations the signed-in user may report on: their own organization and every organization
     * under it. A parent organization therefore sees itself and its children; a child only itself.
     *
     * @return array<int, int>
     */
    private function allowedOrgIds(): array
    {
        return $this->branchIds((int) auth()->user()->org_id);
    }

    /**
     * Stock on hand from MATERIAL_INVENTORY (rebuilt by `php artisan material:rebuild-inventory`). A parent's
     * report adds up its own and its children's rows; only positive stock counts, so an organization that
     * handed out more than it received never cancels stock held elsewhere.
     *
     * @param array<int, int> $orgIds
     */
    private function materialRows(array $orgIds)
    {
        if (empty($orgIds)) {
            return collect();
        }

        $inClause = implode(',', array_fill(0, count($orgIds), '?'));

        return collect(DB::connection('oracle')->select("
            SELECT m.id, m.mat_code, m.mat_name, m.unit,
                   SUM(inv.inv_amt) AS balance,
                   SUM(inv.inv_amt * NVL(inv.inv_price, 0)) / SUM(inv.inv_amt) AS unit_price
            FROM MATERIAL_INVENTORY inv
            JOIN MATERIALS m ON m.id = inv.mat_id
            WHERE inv.org_id IN ({$inClause}) AND inv.inv_amt > 0
            GROUP BY m.id, m.mat_code, m.mat_name, m.unit
            ORDER BY m.mat_code
        ", $orgIds));
    }

    private function branchIds(int $orgId): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT org_id FROM GLB_ORGANIZATION START WITH org_id = ? CONNECT BY PRIOR org_id = org_org_id',
            [$orgId]
        );
        return array_map(static fn ($row) => (int) $row->org_id, $rows) ?: [$orgId];
    }

    private function orgLine(int $orgId): string
    {
        return (string) DB::connection('oracle')->table('GLB_ORGANIZATION')->where('org_id', $orgId)->value('org_name');
    }
}
