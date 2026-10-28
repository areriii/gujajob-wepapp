<?php

namespace App\Http\Controllers;

use App\Models\GlbOrganization;
use App\Models\Material;
use App\Models\MaterialWithdrawn;
use App\Services\FiscalYearService;
use App\Services\OrganizationVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class MaterialRegisterController extends Controller
{
    public function __construct(
        private readonly OrganizationVisibilityService $orgVisibility,
    ) {}

    public function index(Request $request): View
    {
        $filters     = $this->ledgerFilters($request);
        $orgId       = $filters['orgId'];
        $searchType  = $request->string('search_type', 'code')->value();
        $keyword     = trim($request->string('keyword')->value());
        $materialId  = (int) $request->input('material_id', 0);
        $currentFY   = FiscalYearService::current();

        $searched = $materialId > 0 || $keyword !== '';

        // Organizations the signed-in user may see, for the searchable หน่วยงาน box.
        // Filtered here rather than with whereIn: the list can pass Oracle's 1000-item IN limit.
        $visible = array_flip($this->visibleOrgIds());
        $organizations = GlbOrganization::query()
            ->orderBy('org_name')
            ->get(['org_id', 'org_name'])
            ->filter(fn ($org) => isset($visible[(int) $org->org_id]))
            ->values();

        $availableFiscalYears = FiscalYearService::availableYears(5);

        // Build material list filtered by org if specified
        $matQuery = Material::query()->orderBy('mat_code');
        if ($orgId !== null) {
            $matQuery->whereRaw(
                'EXISTS (SELECT 1 FROM MATERIAL_PROCUREMENT_LIST mpl JOIN MATERIAL_PROCUREMENT mp ON mp.id = mpl.mat_pro_id WHERE mpl.mat_id = MATERIALS.id AND mp.org_id = ?)',
                [$orgId]
            );
        }
        $allMaterials = $matQuery->paginate(10, ['id', 'mat_code', 'mat_name', 'unit'], 'mat_page')
            ->withQueryString();

        $baseProps = [
            'pageTitle'            => 'คุมทะเบียนวัสดุ',
            'searchType'           => $searchType,
            'keyword'              => $keyword,
            'materialId'           => $materialId,
            'dateFrom'             => $filters['dateFrom'],
            'dateTo'               => $filters['dateTo'],
            'dateError'            => $filters['dateError'],
            'allMaterials'         => $allMaterials,
            'sort'                 => $filters['sort'],
            'direction'            => $filters['direction'],
            'orgId'                => $orgId,
            'fiscalYear'           => $filters['fiscalYear'],
            'currentFY'            => $currentFY,
            'organizations'        => $organizations,
            'availableFiscalYears' => $availableFiscalYears,
        ];

        if (! $searched || $filters['dateError']) {
            return view('material.MAT-005-material-register.index', array_merge($baseProps, [
                'searched'      => false,
                'material'      => null,
                'multipleFound' => false,
                'materials'     => collect(),
                'summary'       => null,
                'transactions'  => [],
            ]));
        }

        $material      = null;
        $multipleFound = false;
        $materials     = collect();

        try {
            if ($materialId > 0) {
                $material = Material::query()->find($materialId);
            } else {
                $escaped = $this->escapeLike($keyword);
                $q       = Material::query()->orderBy('mat_code');

                if ($searchType === 'code') {
                    $q->whereRaw('UPPER(mat_code) LIKE UPPER(?)', ['%' . mb_strtoupper($escaped) . '%']);
                } else {
                    $q->whereRaw('UPPER(mat_name) LIKE UPPER(?)', ['%' . mb_strtoupper($escaped) . '%']);
                }

                if ($orgId !== null) {
                    $q->whereRaw(
                        'EXISTS (SELECT 1 FROM MATERIAL_PROCUREMENT_LIST mpl JOIN MATERIAL_PROCUREMENT mp ON mp.id = mpl.mat_pro_id WHERE mpl.mat_id = MATERIALS.id AND mp.org_id = ?)',
                        [$orgId]
                    );
                }

                $materials = $q->paginate(10, ['*'], 'mat_page')->withQueryString();

                if ($materials->total() === 1) {
                    $material  = $materials->first();
                    $materials = collect();
                } elseif ($materials->total() > 1) {
                    $multipleFound = true;
                }
            }
        } catch (Throwable $e) {
            Log::error('MAT-005 material lookup failed', ['error' => $e->getMessage()]);
        }

        $summary      = null;
        $transactions = [];

        if ($material !== null) {
            $ledger  = $this->buildLedger($material, $filters);
            $summary = $ledger['summary'];

            $txnPage = max(1, (int) $request->input('txn_page', 1));
            $perPage = 20;
            $transactions = (new LengthAwarePaginator(
                array_slice($ledger['rows'], ($txnPage - 1) * $perPage, $perPage),
                count($ledger['rows']),
                $perPage,
                $txnPage,
                ['path' => $request->url(), 'pageName' => 'txn_page']
            ))->withQueryString();
        }

        return view('material.MAT-005-material-register.index', array_merge($baseProps, [
            'searched'      => true,
            'material'      => $material,
            'multipleFound' => $multipleFound,
            'materials'     => $materials,
            'summary'       => $summary,
            'transactions'  => $transactions,
        ]));
    }

    /** "พิมพ์คุมทะเบียนวัสดุ (PDF)": the material's summary and every transaction row, as a formal A4 document. */
    public function downloadPdf(Request $request)
    {
        $filters  = $this->ledgerFilters($request);
        abort_if($filters['dateError'] !== null, 422, $filters['dateError'] ?? '');

        $material = Material::query()->findOrFail((int) $request->input('material_id', 0));
        $ledger   = $this->buildLedger($material, $filters);
        $orgId    = $filters['orgId'];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('material.MAT-005-material-register.report-pdf', [
            'material'   => $material,
            'summary'    => $ledger['summary'],
            'rows'       => $ledger['rows'],
            'orgName'    => $orgId !== null ? (GlbOrganization::query()->where('org_id', $orgId)->value('org_name') ?? '-') : 'ทุกหน่วยงาน',
            'fiscalYear' => $filters['fiscalYear'],
            'periodLine' => $filters['effectiveFrom'] !== '' && $filters['effectiveTo'] !== ''
                ? 'ระหว่างวันที่ ' . $this->thaiLongDate($filters['effectiveFrom']) . ' ถึงวันที่ ' . $this->thaiLongDate($filters['effectiveTo'])
                : '',
            'asOfDate'   => now('Asia/Bangkok')->addYears(543)->locale('th')->translatedFormat('j F Y'),
        ])->setPaper('a4', 'portrait');

        // "หน้า x / y" bottom right: Dompdf only knows the page count once everything is laid out.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 46, 'หน้า {PAGE_NUM} / {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('ReportThai'), 12);

        $filename = "material-register-{$material->mat_code}.pdf";

        return (config('app.pdf_test_mode') || (in_array(app()->environment(), ['local', 'testing'], true) && $request->boolean('pdf_test')))
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    /**
     * Filters shared by the page and the PDF. orgId null = every organization; 0 is a real organization
     * (กรมส่งเสริมสหกรณ์), and one the user may not see counts as none chosen.
     *
     * @return array{orgId: ?int, fiscalYear: int, dateFrom: string, dateTo: string, effectiveFrom: string, effectiveTo: string, dateError: ?string, sort: string, direction: string}
     */
    private function ledgerFilters(Request $request): array
    {
        $orgId = $request->filled('org_id') && is_numeric($request->input('org_id')) ? $request->integer('org_id') : null;
        if ($orgId !== null && ! in_array($orgId, $this->visibleOrgIds(), true)) {
            $orgId = null;
        }

        $currentFY  = FiscalYearService::current();
        $fiscalYear = $request->integer('fiscal_year', $currentFY);
        if ($fiscalYear < $currentFY - 4 || $fiscalYear > $currentFY) {
            $fiscalYear = $currentFY;
        }

        $dateFrom = $request->string('date_from')->value();
        $dateTo   = $request->string('date_to')->value();

        // Without an explicit date range, the fiscal year's range applies
        $fyRange       = FiscalYearService::dateRange($fiscalYear);
        $effectiveFrom = $dateFrom !== '' ? $dateFrom : $fyRange['start'];
        $effectiveTo   = $dateTo !== '' ? $dateTo : $fyRange['end'];

        return [
            'orgId'         => $orgId,
            'fiscalYear'    => $fiscalYear,
            'dateFrom'      => $dateFrom,
            'dateTo'        => $dateTo,
            'effectiveFrom' => $effectiveFrom,
            'effectiveTo'   => $effectiveTo,
            'dateError'     => $effectiveFrom > $effectiveTo ? 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด' : null,
            'sort'          => $request->string('sort', '')->value(),
            'direction'     => strtolower($request->string('direction', 'asc')->value()) === 'desc' ? 'desc' : 'asc',
        ];
    }

    /** @return array<int, int> */
    private function visibleOrgIds(): array
    {
        return $this->orgVisibility->visibleOrgIds((int) Auth::user()?->org_id);
    }

    /**
     * Running ledger of one material within the filters: the summary and every row (not paginated).
     *
     * @return array{summary: array{forward: int, in_total: int, out_total: int, balance: int}, rows: array<int, array<string, mixed>>}
     */
    private function buildLedger(Material $material, array $filters): array
    {
        $matId     = (int) $material->id;
        $orgId     = $filters['orgId'];
        $dateFrom  = $filters['effectiveFrom'] ?: null;
        $dateTo    = $filters['effectiveTo'] ?: null;
        $sort      = $filters['sort'];
        $direction = $filters['direction'];

        // Oracle analytic function computes running balance chronologically
        $orgWhereProcurement = $orgId !== null ? ' AND mp.org_id = ?' : '';
        $orgWhereWithdrawn   = $orgId !== null ? ' AND mw.org_id = ?' : '';

        // The operator is the signed-in user who recorded the receipt or the withdrawal.
        $sql = "
            SELECT
                TO_CHAR(t.txn_date, 'YYYY-MM-DD') AS txn_date,
                t.sort_key,
                t.doc_code,
                t.reference_no,
                t.detail,
                t.operator,
                t.operator_role,
                t.in_qty,
                t.out_qty,
                SUM(t.in_qty - t.out_qty) OVER (
                    ORDER BY t.txn_date ASC, t.sort_key ASC
                    ROWS UNBOUNDED PRECEDING
                ) AS running_balance
            FROM (
                SELECT
                    mp.mat_pro_date        AS txn_date,
                    mp.mat_pro_code        AS doc_code,
                    mp.mat_pro_quotation   AS reference_no,
                    'รับวัสดุเข้าคลัง'   AS detail,
                    pu.user_name           AS operator,
                    'ผู้บันทึกรับวัสดุ'   AS operator_role,
                    mpl.mat_amt            AS in_qty,
                    0                      AS out_qty,
                    'A'                    AS sort_key
                FROM MATERIAL_PROCUREMENT_LIST mpl
                JOIN MATERIAL_PROCUREMENT mp ON mp.id = mpl.mat_pro_id
                LEFT JOIN SYS_USER pu ON pu.id = mp.created_by
                WHERE mpl.mat_id = ?{$orgWhereProcurement}

                UNION ALL

                SELECT
                    mw.mat_wd_date         AS txn_date,
                    mw.mat_wd_code         AS doc_code,
                    NULL                   AS reference_no,
                    'เบิกวัสดุ'           AS detail,
                    wu.user_name           AS operator,
                    'ผู้บันทึกเบิกวัสดุ'  AS operator_role,
                    0                      AS in_qty,
                    mwl.wd_amount          AS out_qty,
                    'B'                    AS sort_key
                FROM MATERIAL_WITHDRAWN_LIST mwl
                JOIN MATERIAL_WITHDRAWN mw ON mw.id = mwl.mat_wd_id
                LEFT JOIN SYS_USER wu ON wu.id = mw.created_by
                WHERE mwl.mat_id = ? AND mw.status = ?{$orgWhereWithdrawn}
            ) t
            ORDER BY t.txn_date ASC, t.sort_key ASC
        ";

        $params = [$matId];
        if ($orgId !== null) $params[] = $orgId;
        $params[] = $matId;
        $params[] = MaterialWithdrawn::STATUS_APPROVED;
        if ($orgId !== null) $params[] = $orgId;

        try {
            $rows = DB::connection('oracle')->select($sql, $params);
        } catch (Throwable $e) {
            Log::error('MAT-005 ledger query failed', ['mat_id' => $matId, 'error' => $e->getMessage()]);
            return [
                'summary' => ['forward' => 0, 'in_total' => 0, 'out_total' => 0, 'balance' => 0],
                'rows'    => [],
            ];
        }

        $forwardBalance = 0.0;
        $inTotal        = 0.0;
        $outTotal       = 0.0;
        $displayRows    = [];

        foreach ($rows as $row) {
            $txnDate        = (string) ($row->txn_date       ?? '');
            $inQty          = (float)  ($row->in_qty         ?? 0);
            $outQty         = (float)  ($row->out_qty        ?? 0);
            $runningBalance = (float)  ($row->running_balance ?? 0);

            if ($dateFrom !== null && $txnDate < $dateFrom) {
                $forwardBalance += $inQty - $outQty;
                continue;
            }

            if ($dateTo !== null && $txnDate > $dateTo) {
                continue;
            }

            $inTotal  += $inQty;
            $outTotal += $outQty;

            $displayRows[] = [
                'date'          => $this->formatThaiDate($txnDate),
                'txn_date_raw'  => $txnDate,
                'doc_code'      => (string) ($row->doc_code      ?? ''),
                'reference_no'  => (string) ($row->reference_no  ?? ''),
                'detail'        => (string) ($row->detail        ?? ''),
                'operator'      => (string) ($row->operator      ?? ''),
                'operator_role' => (string) ($row->operator_role ?? ''),
                'in_qty'        => $inQty  > 0 ? number_format($inQty,  0) : '-',
                'in_qty_raw'    => $inQty,
                'out_qty'       => $outQty > 0 ? number_format($outQty, 0) : '-',
                'out_qty_raw'   => $outQty,
                'balance'       => number_format($runningBalance, 0),
                'balance_raw'   => $runningBalance,
            ];
        }

        // Sort display rows by requested column (server-side, before view)
        $sortMap = [
            'txn_date'        => 'txn_date_raw',
            'doc_code'        => 'doc_code',
            'in_qty_raw'      => 'in_qty_raw',
            'out_qty_raw'     => 'out_qty_raw',
            'running_balance' => 'balance_raw',
        ];

        if ($sort !== '' && isset($sortMap[$sort])) {
            $sortKey = $sortMap[$sort];
            $dir     = $direction;
            usort($displayRows, static function (array $a, array $b) use ($sortKey, $dir): int {
                $va = $a[$sortKey];
                $vb = $b[$sortKey];
                if ($va == $vb) {
                    return 0;
                }
                $cmp = ($va < $vb) ? -1 : 1;
                return $dir === 'desc' ? -$cmp : $cmp;
            });
        }

        return [
            'summary' => [
                'forward'   => (int) round($forwardBalance),
                'in_total'  => (int) round($inTotal),
                'out_total' => (int) round($outTotal),
                'balance'   => (int) round($forwardBalance + $inTotal - $outTotal),
            ],
            'rows' => $displayRows,
        ];
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

    /** 2025-10-01 → "1 ตุลาคม 2568" (the year added by hand: adding 543 years to 29 February rolls over to March) */
    private function thaiLongDate(string $date): string
    {
        try {
            $day = Carbon::parse($date)->locale('th');

            return $day->translatedFormat('j F') . ' ' . ($day->year + 543);
        } catch (Throwable) {
            return $date;
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }
}
