<?php

namespace App\Http\Controllers;

use App\Models\GlbOrganization;
use App\Models\Material;
use App\Services\FiscalYearService;
use App\Services\MaterialInventoryService;
use App\Services\MaterialStockRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class MaterialBalanceSettingController extends Controller
{
    public function __construct(
        private MaterialInventoryService $inventory,
        private MaterialStockRecommendationService $recommendations,
    ) {}

    public function index(Request $request): View
    {
        $listing    = $this->balanceListing($request);
        $orgId      = $listing['orgId'];
        $fiscalYear = $listing['fiscalYear'];
        $currentFY  = FiscalYearService::current();
        $isEditable = FiscalYearService::isCurrent($fiscalYear);

        $materials = $listing['query']->paginate(10)->withQueryString();
        $matIds    = $materials->pluck('id')->map(fn ($id) => (int) $id)->all(); // not map('intval'): the key would become the number base
        $avgPrices = $this->computeAvgPrices($matIds);
        $recommendation = $this->recommendations->recommend($orgId, $matIds);

        // Organizations that have received or hold material
        $organizations = GlbOrganization::query()
            ->whereRaw('(EXISTS (SELECT 1 FROM MATERIAL_PROCUREMENT mp WHERE mp.org_id = GLB_ORGANIZATION.org_id) OR EXISTS (SELECT 1 FROM MATERIAL_INVENTORY inv WHERE inv.org_id = GLB_ORGANIZATION.org_id))')
            ->orderBy('org_name')
            ->get(['org_id', 'org_name']);

        $availableFiscalYears = FiscalYearService::availableYears(5);

        return view('material.MAT-006-record-balance-setting.index', [
            'pageTitle'            => 'บันทึกการตั้งยอดคงเหลือ',
            'materials'            => $materials,
            'avgPrices'            => $avgPrices,
            'recommended'          => $recommendation['values'],
            'recommendationMethod' => $recommendation['method'],
            'recommendationNote'   => $recommendation['note'],
            'fiscalYear'           => $fiscalYear,
            'currentFY'            => $currentFY,
            'isEditable'           => $isEditable,
            // Balances are kept per organization, so adjusting needs one selected.
            'canAdjust'            => $isEditable && $orgId !== null,
            'searchBy'             => $listing['searchBy'],
            'keyword'              => $listing['keyword'],
            'sort'                 => $listing['sort'],
            'direction'            => $listing['direction'],
            'orgId'                => $orgId,
            'organizations'        => $organizations,
            'availableFiscalYears' => $availableFiscalYears,
            'bulkUpdateUrl'        => route('material.balance.bulk-update'),
        ]);
    }

    /** "จัดพิมพ์รายงาน": the list as filtered on screen (every page of it) as an A4 PDF, like the MAT-007 print button. */
    public function downloadPdf(Request $request)
    {
        $listing = $this->balanceListing($request);
        $orgId   = $listing['orgId'];

        $materials = $listing['query']->get();
        $matIds    = $materials->pluck('id')->map(fn ($id) => (int) $id)->all();
        $recommendation = $this->recommendations->recommend($orgId, $matIds);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('material.MAT-006-record-balance-setting.report-pdf', [
            'materials'          => $materials,
            'avgPrices'          => $this->computeAvgPrices($matIds),
            'recommended'        => $recommendation['values'],
            'fiscalYear'         => $listing['fiscalYear'],
            'orgName'            => $orgId !== null ? (GlbOrganization::query()->where('org_id', $orgId)->value('org_name') ?? '-') : 'ทุกหน่วยงาน',
            'searchLine'         => $listing['keyword'] !== ''
                ? ($listing['searchBy'] === 'code' ? 'รหัสวัสดุ' : 'ชื่อวัสดุ') . 'ที่มีคำว่า "' . $listing['keyword'] . '"'
                : '',
            'asOfDate'           => now('Asia/Bangkok')->addYears(543)->locale('th')->translatedFormat('j F Y'),
        ])->setPaper('a4', 'portrait');

        // "หน้า x / y" bottom right: Dompdf only knows the page count once everything is laid out.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 46, 'หน้า {PAGE_NUM} / {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('ReportThai'), 12);

        $filename = "material-balance-setting-{$listing['fiscalYear']}.pdf";

        return (config('app.pdf_test_mode') || (in_array(app()->environment(), ['local', 'testing'], true) && $request->boolean('pdf_test')))
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    /**
     * The material list behind index() and downloadPdf(): the filters from the request and the query they give.
     *
     * @return array{query: \Illuminate\Database\Eloquent\Builder, orgId: ?int, fiscalYear: int, searchBy: string, keyword: string, sort: string, direction: string}
     */
    private function balanceListing(Request $request): array
    {
        $searchBy    = $request->string('search_by', 'name')->value();
        $keyword     = trim($request->string('keyword')->value());
        $sort        = $request->string('sort', '')->value();
        $direction   = strtolower($request->string('direction', 'asc')->value()) === 'desc' ? 'desc' : 'asc';
        // null = every organization; 0 is a real organization (กรมส่งเสริมสหกรณ์), not "all"
        $orgId       = $request->filled('org_id') && is_numeric($request->input('org_id')) ? $request->integer('org_id') : null;
        $currentFY   = FiscalYearService::current();
        $fiscalYear  = $request->integer('fiscal_year', $currentFY);
        // Clamp to available years
        if ($fiscalYear < $currentFY - 4 || $fiscalYear > $currentFY) {
            $fiscalYear = $currentFY;
        }

        $allowedSorts = [
            'fiscal_year' => null,
            'code'        => 'mat_code',
            'name'        => 'mat_name',
            'balance'     => null,
            'avg_price'   => null,
        ];

        // Balance from MATERIAL_INVENTORY: the selected organization's row, or the stock held across all of them.
        $balanceSubSql = $orgId !== null
            ? "(SELECT NVL(SUM(inv.inv_amt), 0) FROM MATERIAL_INVENTORY inv WHERE inv.mat_id = MATERIALS.id AND inv.org_id = {$orgId})"
            : "(SELECT NVL(SUM(inv.inv_amt), 0) FROM MATERIAL_INVENTORY inv WHERE inv.mat_id = MATERIALS.id AND inv.inv_amt > 0)";

        $query = Material::query()
            ->select('MATERIALS.*')
            ->addSelect(DB::raw("{$balanceSubSql} AS current_balance"));

        // Materials the organization received or holds stock of (stock can also arrive by transfer)
        if ($orgId !== null) {
            $query->whereRaw(
                '(EXISTS (SELECT 1 FROM MATERIAL_PROCUREMENT_LIST mpl JOIN MATERIAL_PROCUREMENT mp ON mp.id = mpl.mat_pro_id WHERE mpl.mat_id = MATERIALS.id AND mp.org_id = ?) OR EXISTS (SELECT 1 FROM MATERIAL_INVENTORY inv WHERE inv.mat_id = MATERIALS.id AND inv.org_id = ?))',
                [$orgId, $orgId]
            );
        } else {
            $query->whereRaw('(EXISTS (SELECT 1 FROM MATERIAL_PROCUREMENT_LIST mpl WHERE mpl.mat_id = MATERIALS.id) OR EXISTS (SELECT 1 FROM MATERIAL_INVENTORY inv WHERE inv.mat_id = MATERIALS.id))');
        }

        if ($keyword !== '') {
            $escaped = $this->escapeLike($keyword);
            if ($searchBy === 'code') {
                $query->whereRaw('UPPER(mat_code) LIKE UPPER(?)', ['%' . mb_strtoupper($escaped) . '%']);
            } else {
                $query->whereRaw('UPPER(mat_name) LIKE UPPER(?)', ['%' . mb_strtoupper($escaped) . '%']);
            }
        }

        if ($sort === 'balance') {
            $query->orderByRaw("{$balanceSubSql} {$direction} NULLS LAST");
        } elseif ($sort === 'avg_price') {
            $avgPriceSql = "(SELECT SUM(mpl2.mat_amt * mpl2.mat_price) / NULLIF(SUM(mpl2.mat_amt), 0) FROM MATERIAL_PROCUREMENT_LIST mpl2 WHERE mpl2.mat_id = MATERIALS.id)";
            $query->orderByRaw("{$avgPriceSql} {$direction} NULLS LAST");
        } elseif ($sort === 'fiscal_year') {
            // fiscal_year is the same for all rows (from filter), so secondary sort by code
            $query->orderBy('mat_code', $direction);
        } else {
            $sortColumn = (array_key_exists($sort, $allowedSorts) && $allowedSorts[$sort])
                ? $allowedSorts[$sort]
                : 'mat_code';
            $query->orderBy($sortColumn, $direction);
        }

        return [
            'query'      => $query,
            'orgId'      => $orgId,
            'fiscalYear' => $fiscalYear,
            'searchBy'   => $searchBy,
            'keyword'    => $keyword,
            'sort'       => $sort,
            'direction'  => $direction,
        ];
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $currentFY = FiscalYearService::current();

        $fiscalYear = (int) $request->input('fiscal_year', 0);
        if ($fiscalYear !== $currentFY) {
            return response()->json([
                'success' => false,
                'error'   => 'ไม่สามารถแก้ไขข้อมูลของปีงบประมาณที่ผ่านมาได้',
            ], 422);
        }

        $orgId = $this->organizationFrom($request->input('organization_id'));
        $items = $request->input('items', []);

        if ($orgId === null) {
            return response()->json(['success' => false, 'error' => 'กรุณาเลือกหน่วยงานก่อนปรับปรุงยอดคงเหลือ'], 422);
        }

        if (! is_array($items) || count($items) === 0) {
            return response()->json(['success' => false, 'error' => 'ไม่มีรายการที่จะบันทึก'], 422);
        }

        // Validate no duplicate material_id
        $matIds = array_column($items, 'material_id');
        if (count($matIds) !== count(array_unique($matIds))) {
            return response()->json(['success' => false, 'error' => 'มีรายการวัสดุซ้ำกันใน Payload'], 422);
        }

        // Validate each item
        foreach ($items as $item) {
            if (! isset($item['material_id']) || ! is_numeric($item['material_id'])) {
                return response()->json(['success' => false, 'error' => 'ข้อมูล material_id ไม่ถูกต้อง'], 422);
            }
            if (! isset($item['balance']) || ! is_numeric($item['balance']) || (float) $item['balance'] < 0) {
                return response()->json(['success' => false, 'error' => 'ยอดคงเหลือต้องเป็นตัวเลขที่ไม่ติดลบ'], 422);
            }
        }

        try {
            DB::connection('oracle')->transaction(function () use ($items, $orgId): void {
                foreach ($items as $item) {
                    $matId = (int) $item['material_id'];
                    if (! Material::query()->find($matId)) {
                        throw new \RuntimeException("ไม่พบวัสดุ ID: {$matId}");
                    }

                    $this->inventory->setBalance($orgId, $matId, (float) $item['balance'], (int) auth()->id());
                }
            });
        } catch (Throwable $e) {
            Log::error('MAT-006 bulkUpdate failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'count' => count($items)]);
    }

    public function updateBalance(Request $request, string $materialCode): JsonResponse
    {
        $material = Material::query()->where('mat_code', $materialCode)->first();
        if (! $material) {
            return response()->json(['success' => false, 'error' => 'ไม่พบวัสดุ'], 404);
        }

        $balance = $request->input('balance');
        if (! is_numeric($balance) || (float) $balance < 0) {
            return response()->json(['success' => false, 'error' => 'ยอดคงเหลือต้องเป็นตัวเลขที่ไม่ติดลบ']);
        }

        $orgId = $this->organizationFrom($request->input('organization_id'));
        if ($orgId === null) {
            return response()->json(['success' => false, 'error' => 'กรุณาเลือกหน่วยงานก่อนปรับปรุงยอดคงเหลือ'], 422);
        }

        $matId   = (int) $material->id;
        $invAmt  = (float) $balance;

        try {
            DB::connection('oracle')->transaction(fn () => $this->inventory->setBalance($orgId, $matId, $invAmt, (int) auth()->id()));
        } catch (Throwable $e) {
            Log::error('MAT-006 updateBalance failed', ['mat_id' => $matId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่']);
        }

        return response()->json(['success' => true, 'balance' => $invAmt]);
    }

    public function edit(Request $request, string $materialCode): View
    {
        $material = Material::query()->where('mat_code', $materialCode)->first();
        if (! $material) {
            abort(404);
        }

        $matId = (int) $material->id;

        try {
            $rows = DB::connection('oracle')->select("
                SELECT
                    TO_CHAR(mp.mat_pro_date, 'YYYY-MM-DD') AS receive_date,
                    mp.mat_pro_code AS receive_no,
                    mpl.mat_amt AS receive_quantity,
                    mpl.mat_price AS unit_price
                FROM MATERIAL_PROCUREMENT_LIST mpl
                JOIN MATERIAL_PROCUREMENT mp ON mp.id = mpl.mat_pro_id
                WHERE mpl.mat_id = :mat_id
                ORDER BY mp.mat_pro_date ASC, mpl.id ASC
            ", ['mat_id' => $matId]);
        } catch (Throwable $e) {
            Log::error('MAT-007 lots query failed', ['mat_id' => $matId, 'error' => $e->getMessage()]);
            $rows = [];
        }

        $avgPrices = $this->computeAvgPrices([$matId]);

        $lots = array_map(function ($row) {
            return [
                'receive_date'     => $this->formatThaiDate((string) ($row->receive_date ?? '')),
                'receive_no'       => (string) ($row->receive_no ?? ''),
                'receive_quantity' => (float) ($row->receive_quantity ?? 0),
                'unit_price'       => number_format((float) ($row->unit_price ?? 0), 2),
                'balance_quantity' => 0,
            ];
        }, $rows);

        $record = [
            'material_code' => $material->mat_code,
            'material_name' => $material->mat_name,
            'department'    => '-',
            'unit'          => $material->unit ?? '-',
            'average_price' => number_format($avgPrices[$matId] ?? 0, 2),
        ];

        return view('material.MAT-006-record-balance-setting.edit', [
            'pageTitle' => 'แก้ไขข้อมูลการตั้งยอดคงเหลือ',
            'record'    => $record,
            'lots'      => $lots,
        ]);
    }

    private function formatThaiDate(string $date): string
    {
        if ($date === '') {
            return '-';
        }

        $parts = explode('-', $date);

        if (count($parts) !== 3) {
            return $date;
        }

        [$y, $m, $d] = $parts;

        return sprintf('%s-%s-%d', $d, $m, (int) $y + 543);
    }

    private function computeAvgPrices(array $matIds): array
    {
        if (empty($matIds)) {
            return [];
        }

        $ph  = implode(',', array_fill(0, count($matIds), '?'));
        $sql = "
            SELECT mat_id,
                   SUM(mat_amt * mat_price) / NULLIF(SUM(mat_amt), 0) AS avg_price
            FROM MATERIAL_PROCUREMENT_LIST
            WHERE mat_id IN ({$ph})
            GROUP BY mat_id
        ";

        try {
            $rows = DB::connection('oracle')->select($sql, $matIds);
        } catch (Throwable $e) {
            Log::error('MAT-006 avg price query failed', ['error' => $e->getMessage()]);
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->mat_id] = round((float) ($row->avg_price ?? 0), 2);
        }

        return $result;
    }

    /** The organization balances are set for; it must exist, and 0 (กรมส่งเสริมสหกรณ์) is a valid one. */
    private function organizationFrom(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return GlbOrganization::query()->where('org_id', (int) $value)->exists() ? (int) $value : null;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }
}
