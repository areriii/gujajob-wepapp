<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetImage;
use App\Models\Dealer;
use App\Models\GlbOrganization;
use App\Services\OrganizationVisibilityService;
use App\Services\AssetDepreciationCalculator;
use App\Services\AssetDisplayService;
use App\Services\ReplacementBudgetCalculator;
use App\Services\ReplacementForecastDataService;
use App\Services\XlsxReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

class AssetController extends Controller
{
    private const PER_PAGE = 10;
    private const SEARCH_COLUMN_MAP = [
        'all'  => null,
        'code' => 'a.ass_code',
        'name' => 'c.asscat_name',
        'org'  => 'org.org_name',
    ];

    private const STATUS_MAP = ['1'=>'1','2'=>'2','3'=>'3','4'=>'4','5'=>'5','6'=>'6'];

    public const ASSET_STATUS = [
        '1' => ['label' => 'รอจัดสรร',     'class' => 'status-pending'],
        '2' => ['label' => 'ปกติ',          'class' => 'status-normal'],
        '3' => ['label' => 'พร้อมจำหน่าย', 'class' => 'status-dispose'],
        '4' => ['label' => 'จำหน่ายแล้ว',     'class' => 'status-sold'],
        '5' => ['label' => 'ชำรุด',         'class' => 'status-damaged'],
        '6' => ['label' => 'สูญหาย',        'class' => 'status-lost'],
    ];

    /** ASSET.REPLACEMENT_STATUS: 'yes' keeps the asset out of the replacement forecast. */
    public const REPLACEMENT_STATUS = [
        'no'  => 'ยังไม่ได้ซื้อทดแทน',
        'yes' => 'ซื้อทดแทนแล้ว',
    ];

    public static function assetStatusLabel(string $status): array
    {
        return self::ASSET_STATUS[$status] ?? ['label' => ($status ?: '-'), 'class' => ''];
    }

    public function __construct(
        private readonly OrganizationVisibilityService $orgVisibility,
        private readonly AssetDisplayService $assetDisplay,
    ) {}

    public function index(Request $request): View
    {
        $searchBy     = $request->string('search_by', 'all')->value();
        $keyword      = trim($request->string('keyword')->value());
        $statusFilter = $request->string('status_filter', '')->value();
        $sort         = $request->string('sort', '')->value();
        $direction    = strtolower($request->string('direction', 'asc')->value()) === 'desc' ? 'desc' : 'asc';

        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $completedDisposals = DB::connection('oracle')
            ->table('ASSET_SELLING AS s')
            ->join('ASSET_SELLING_LIST AS sl', 'sl.selling_id', '=', 's.id')
            ->selectRaw("sl.ass_id, CASE WHEN s.reason = '3' THEN '6' ELSE '4' END AS final_status")
            ->whereIn('s.reason', ['1', '2'])
            ->where('s.selling_approval_status', 1)
            ->groupBy('s.id', 'sl.ass_id', 's.reason', 's.buyer')
            ->havingRaw("COUNT(*) > 0 AND COUNT(*) = COUNT(sl.selling_real_price) AND TRIM(s.buyer) IS NOT NULL")
            ->unionAll(
                DB::connection('oracle')->table('ASSET_SELLING AS s')
                    ->join('ASSET_SELLING_LIST AS sl', 'sl.selling_id', '=', 's.id')
                    ->selectRaw("sl.ass_id, '6' AS final_status")
                    ->where('s.reason', '3')
                    ->where('s.selling_approval_status', 1)
                    ->groupBy('s.id', 'sl.ass_id', 's.reason')
                    ->havingRaw('COUNT(*) > 0 AND COUNT(*) = COUNT(sl.selling_real_price)')
            );

        $effectiveStatusSql = "CASE WHEN completed_disposals.final_status IS NOT NULL THEN completed_disposals.final_status ELSE a.ass_status END";

        $allowedSorts = [
            'code'         => 'a.ass_code',
            'name'         => 'c.asscat_name',
            'status'       => $effectiveStatusSql,
            'price'        => 'a.ass_price',
            'remain'       => 'a.remain_price',
            'inspect_date' => 'a.inspect_date',
        ];

        $query = DB::connection('oracle')->table('ASSET AS a')
            ->join('ASSET_CATEGORY AS c', 'a.asscat_id', '=', 'c.id')
            ->leftJoin('GLB_ORGANIZATION AS org', 'a.org_id', '=', 'org.org_id')
            ->leftJoinSub($completedDisposals, 'completed_disposals', 'completed_disposals.ass_id', '=', 'a.id')
            ->select([
                'a.id',
                'a.ass_code',
                'a.ass_desc',
                'a.ass_price',
                'a.remain_price',
                DB::raw("{$effectiveStatusSql} AS ass_status"),
                'a.inspect_date',
                DB::raw("TO_CHAR(a.inspect_date, 'DD-MM-') || TO_CHAR(a.inspect_date + INTERVAL '543' YEAR(3), 'YYYY') AS inspect_date_th"),
                'c.asscat_code',
                'c.asscat_name',
                'c.asscat_type',
                'org.org_name',
                DB::raw("(SELECT MAX(aa2.status) KEEP (DENSE_RANK LAST ORDER BY aa2.id)
                          FROM ASSET_ASSIGNMENT_LIST aal2
                          JOIN ASSET_ASSIGNMENT aa2 ON aa2.id = aal2.ass_assign_id
                          WHERE aal2.asset_id = a.id) AS aa_status"),
            ]);

        if (empty($visibleOrgIds)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('a.org_id', $visibleOrgIds);
        }

        if ($statusFilter !== '' && array_key_exists($statusFilter, self::STATUS_MAP)) {
            $query->whereRaw("{$effectiveStatusSql} = ?", [self::STATUS_MAP[$statusFilter]]);
        }

        if ($keyword !== '') {
            $escaped = $this->escapeLike($keyword);
            if ($searchBy === 'all') {
                $query->where(function ($q) use ($escaped): void {
                    $q->whereRaw('UPPER(a.ass_code)    LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('UPPER(c.asscat_name) LIKE UPPER(?)', ['%' . $escaped . '%'])
                      ->orWhereRaw('UPPER(org.org_name)  LIKE UPPER(?)', ['%' . $escaped . '%']);
                });
            } elseif (array_key_exists($searchBy, self::SEARCH_COLUMN_MAP) && self::SEARCH_COLUMN_MAP[$searchBy] !== null) {
                $col = self::SEARCH_COLUMN_MAP[$searchBy];
                $query->whereRaw("UPPER({$col}) LIKE UPPER(?)", ['%' . $escaped . '%']);
            }
        }

        $sortExpr = array_key_exists($sort, $allowedSorts) ? $allowedSorts[$sort] : 'a.ass_code';
        $query->orderByRaw("{$sortExpr} {$direction} NULLS LAST");

        $assets = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('asset.ASS-003-manage-asset-registration.index', [
            'pageTitle'          => 'จัดการทะเบียนครุภัณฑ์',
            'assets'             => $assets,
            'searchBy'           => $searchBy,
            'keyword'            => $keyword,
            'statusFilter'       => $statusFilter,
            'sort'               => $sort,
            'direction'          => $direction,
        ]);
    }

    public function forecastData(Request $request): JsonResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        if (empty($visibleOrgIds)) {
            return response()->json(['data' => [], 'summary' => ['total_count' => 0, 'total_budget' => 0.0]]);
        }

        $yearsAhead  = max(1, min(3, (int) $request->input('years_ahead', 1)));
        $filterCatId = $request->input('filter_cat_id');
        $filterOrgId = $request->input('filter_org_id');
        $filterQ     = trim((string) $request->input('q', ''));
        $currentYear = (int) date('Y');
        $endDateExpr = 'ADD_MONTHS(a.inspect_date, a.ass_lifetime * 12)';
        $endYearExpr = "EXTRACT(YEAR FROM {$endDateExpr})";

        $query = DB::connection('oracle')->table('ASSET AS a')
            ->join('ASSET_CATEGORY AS c', 'a.asscat_id', '=', 'c.id')
            ->leftJoin('GLB_ORGANIZATION AS org', 'a.org_id', '=', 'org.org_id')
            ->whereIn('a.org_id', $visibleOrgIds)
            ->whereNotNull('a.inspect_date')
            ->whereNotNull('a.ass_lifetime')
            ->where('a.ass_lifetime', '>', 0)
            ->whereRaw("{$endYearExpr} >= ?", [$currentYear])
            ->whereRaw("{$endYearExpr} <= ?", [$currentYear + $yearsAhead])
            ->select([
                'a.ass_code',
                'c.asscat_name',
                'org.org_name',
                'a.ass_lifetime',
                DB::raw("TO_CHAR(a.inspect_date, 'DD-MM-') || TO_CHAR(a.inspect_date + INTERVAL '543' YEAR(3), 'YYYY') AS inspect_date_th"),
                DB::raw("TO_CHAR({$endDateExpr}, 'DD-MM-') || TO_CHAR({$endDateExpr} + INTERVAL '543' YEAR(3), 'YYYY') AS end_date_th"),
                DB::raw("ROUND(MONTHS_BETWEEN({$endDateExpr}, SYSDATE) / 12, 1) AS years_remaining"),
                'a.ass_price',
            ])
            ->orderBy(DB::raw($endYearExpr))
            ->orderBy('a.ass_code');

        if ($filterCatId) {
            $query->where('a.asscat_id', (int) $filterCatId);
        }

        if ($filterOrgId && in_array((int) $filterOrgId, $visibleOrgIds, true)) {
            $query->where('a.org_id', (int) $filterOrgId);
        }

        if ($filterQ !== '') {
            $escaped = $this->escapeLike($filterQ);
            $query->where(function ($q) use ($escaped): void {
                $q->whereRaw('UPPER(a.ass_code)    LIKE UPPER(?)', ['%' . $escaped . '%'])
                  ->orWhereRaw('UPPER(c.asscat_name) LIKE UPPER(?)', ['%' . $escaped . '%'])
                  ->orWhereRaw('UPPER(org.org_name)  LIKE UPPER(?)', ['%' . $escaped . '%']);
            });
        }

        $rows        = $query->get();
        $totalCount  = $rows->count();
        $totalBudget = $rows->sum(fn ($r) => (float) $r->ass_price);

        $data = $rows->map(fn ($r) => [
            'code'            => $r->ass_code ?? '-',
            'name'            => $r->asscat_name ?? '-',
            'org'             => $r->org_name ?? '-',
            'lifetime'        => (int) $r->ass_lifetime,
            'inspect_date'    => $r->inspect_date_th ?? '-',
            'end_date'        => $r->end_date_th ?? '-',
            'years_remaining' => (float) $r->years_remaining,
            'price'           => (float) $r->ass_price,
        ])->values()->all();

        return response()->json([
            'data'    => $data,
            'summary' => ['total_count' => $totalCount, 'total_budget' => $totalBudget],
        ]);
    }

    public function create(): View
    {
        $categories = AssetCategory::query()->orderBy('asscat_code')->get(['id', 'asscat_code', 'asscat_name', 'asscat_type', 'asscat_group', 'asscat_unit', 'depreciation_rate']);

        return view('asset.ASS-003-manage-asset-registration.create', [
            'pageTitle'  => 'จัดการทะเบียนครุภัณฑ์',
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ass_code'         => ['required', 'string', 'max:15'],
            'asscat_id'        => ['required', 'integer', 'exists:oracle.ASSET_CATEGORY,id'],
            'ass_desc'         => ['nullable', 'string', 'max:500'],
            'ass_model'        => ['nullable', 'string', 'max:100'],
            'ass_serail'       => ['nullable', 'string', 'max:30'],
            'ass_price'        => ['nullable', 'numeric', 'min:0'],
            'ass_contact_no'   => ['nullable', 'string', 'max:20'],
            'ass_contact_date' => ['nullable', 'date'],
            'dealer_id'        => ['nullable', 'integer'],
            'inspect_date'     => ['nullable', 'date'],
            'warranty'         => ['nullable', 'integer', 'min:0'],
            'ass_lifetime'     => ['nullable', 'integer', 'min:0'],
            'remarks'          => ['nullable', 'string', 'max:500'],
            'ass_status'       => ['nullable', 'string', 'in:1,2,3,4,5,6'],
            'replacement_status' => ['nullable', 'string', 'in:no,yes'],
            'asset_images'     => ['nullable', 'array', 'max:3'],
            'asset_images.*'   => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif', 'max:1024'],
        ], [
            'ass_code.required'  => 'กรุณากรอกรหัสครุภัณฑ์ประจำหน่วยงาน',
            'ass_code.max'       => 'รหัสครุภัณฑ์ประจำหน่วยงานต้องไม่เกิน 15 ตัวอักษร',
            'asscat_id.required' => 'กรุณาเลือกประเภทครุภัณฑ์',
            'asscat_id.exists'   => 'ประเภทครุภัณฑ์ที่เลือกไม่ถูกต้อง',
            'ass_status.in'      => 'สถานะที่เลือกไม่ถูกต้อง',
            'replacement_status.in' => 'สถานะการซื้อทดแทนที่เลือกไม่ถูกต้อง',
            'asset_images.*.max' => 'ไฟล์รูปภาพต้องมีขนาดไม่เกิน 1 MB ต่อไฟล์',
        ]);

        $newCode = trim($validated['ass_code']);
        $duplicate = DB::connection('oracle')->table('ASSET')
            ->whereRaw('UPPER(ass_code) = UPPER(?)', [$newCode])
            ->exists();
        if ($duplicate) {
            return back()->withInput()
                ->withErrors(['ass_code' => 'รหัสทะเบียนครุภัณฑ์นี้มีอยู่แล้ว กรุณาใช้รหัสอื่น']);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($validated, $request, $newCode): void {
                $nextId = (int) DB::connection('oracle')
                    ->selectOne('SELECT ASSET_SEQ.NEXTVAL AS next_id FROM DUAL')
                    ->next_id;
                $userId = (int) Auth::id();

                Asset::create([
                    'id'               => $nextId,
                    'asscat_id'        => (int) $validated['asscat_id'],
                    'ass_code'         => $newCode,
                    'ass_desc'         => $validated['ass_desc'] ?? null,
                    'ass_model'        => $validated['ass_model'] ?? null,
                    'ass_serail'       => $validated['ass_serail'] ?? null,
                    'ass_price'        => isset($validated['ass_price']) ? (float) $validated['ass_price'] : null,
                    'org_id'           => (int) Auth::user()->org_id,
                    'ass_contact_no'   => $validated['ass_contact_no'] ?? null,
                    'ass_contact_date' => $validated['ass_contact_date'] ?? null,
                    'dealer_id'        => isset($validated['dealer_id']) ? (int) $validated['dealer_id'] : null,
                    'inspect_date'     => $validated['inspect_date'] ?? null,
                    'warranty'         => isset($validated['warranty']) ? (int) $validated['warranty'] : null,
                    'ass_lifetime'     => isset($validated['ass_lifetime']) ? (int) $validated['ass_lifetime'] : null,
                    // มูลค่าคงเหลือ as of today from the depreciation rules (equals ass_price until depreciation starts)
                    'remain_price'     => app(AssetDepreciationCalculator::class)->remainingValue(
                        $validated['ass_price'] ?? null,
                        $validated['ass_lifetime'] ?? null,
                        $validated['inspect_date'] ?? null,
                    ),
                    'remarks'          => $validated['remarks'] ?? null,
                    'ass_status'       => $validated['ass_status'] ?? '1',
                    'replacement_status' => $validated['replacement_status'] ?? 'no',
                    'created_by'       => $userId,
                    'updated_by'       => $userId,
                ]);

                if ($request->hasFile('asset_images')) {
                    $dir = 'asset-images/' . $nextId;
                    foreach ($request->file('asset_images') as $img) {
                        if ($img && $img->isValid()) {
                            $path = $img->store($dir, 'public');
                            if ($path) {
                                $imgId = (int) DB::connection('oracle')
                                    ->selectOne('SELECT ASSET_IMAGE_SEQ.NEXTVAL AS nv FROM DUAL')->nv;
                                DB::connection('oracle')->table('ASSET_IMAGE')->insert([
                                    'id'         => $imgId,
                                    'ass_id'     => $nextId,
                                    'ass_image'  => $path,
                                    'created_by' => $userId,
                                    'created_at' => DB::raw('SYSTIMESTAMP'),
                                    'updated_by' => $userId,
                                    'updated_at' => DB::raw('SYSTIMESTAMP'),
                                ]);
                            }
                        }
                    }
                }
            });
        } catch (Throwable $e) {
            Log::error('ASS-003 store failed', ['error' => $e->getMessage()]);
            return back()->withInput()->withErrors(['general' => 'บันทึกข้อมูลไม่สำเร็จ กรุณาลองอีกครั้ง']);
        }

        return redirect()->route('asset.registrations.index')
            ->with('asset_success', 'บันทึกข้อมูลครุภัณฑ์เรียบร้อยแล้ว');
    }

    public function show(int $id): View
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);
        $asset = Asset::with(['category', 'organization', 'dealer', 'subOrganization'])
            ->whereIn('org_id', $visibleOrgIds)
            ->findOrFail($id);

        $asset->aa_status = DB::connection('oracle')
            ->table('ASSET_ASSIGNMENT_LIST AS aal')
            ->join('ASSET_ASSIGNMENT AS aa', 'aa.id', '=', 'aal.ass_assign_id')
            ->where('aal.asset_id', $id)
            ->orderByDesc('aa.id')
            ->value('aa.status');

        $images = DB::connection('oracle')->table('ASSET_IMAGE')
            ->where('ass_id', $id)->orderBy('id')
            ->get(['id', 'ass_image'])
            ->filter(fn ($img) => $img->ass_image && Storage::disk('public')->exists($img->ass_image))
            ->map(fn ($img) => ['id' => $img->id, 'url' => Storage::disk('public')->url($img->ass_image)])
            ->values();

        return view('asset.ASS-003-manage-asset-registration.show', [
            'pageTitle' => 'จัดการทะเบียนครุภัณฑ์',
            'asset'     => $asset,
            'images'    => $images,
            'displayStatus' => $this->assetDisplay->statusInfo(
                $this->assetDisplay->completedDisposalStatuses([$asset])[$asset->id] ?? (string) $asset->ass_status
            ),
        ]);
    }

    public function edit(int $id): View
    {
        $user          = Auth::user();
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) $user->org_id);
        $asset         = Asset::with(['category', 'dealer', 'organization', 'subOrganization'])
            ->whereIn('org_id', $visibleOrgIds)
            ->findOrFail($id);
        $categories = AssetCategory::query()->orderBy('asscat_code')
            ->get(['id', 'asscat_code', 'asscat_name', 'asscat_type', 'asscat_group', 'asscat_unit', 'depreciation_rate']);

        $images = DB::connection('oracle')->table('ASSET_IMAGE')
            ->where('ass_id', $id)->orderBy('id')
            ->get(['id', 'ass_image'])
            ->filter(fn ($img) => $img->ass_image && Storage::disk('public')->exists($img->ass_image))
            ->map(fn ($img) => ['id' => $img->id, 'url' => Storage::disk('public')->url($img->ass_image)])
            ->values();

        return view('asset.ASS-003-manage-asset-registration.edit', [
            'pageTitle'  => 'จัดการทะเบียนครุภัณฑ์',
            'asset'      => $asset,
            'categories' => $categories,
            'images'     => $images,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);
        $asset = Asset::query()->whereIn('org_id', $visibleOrgIds)->findOrFail($id);

        $validated = $request->validate([
            'ass_code'           => ['required', 'string', 'max:15'],
            'asscat_id'          => ['required', 'integer', 'exists:oracle.ASSET_CATEGORY,id'],
            'ass_desc'           => ['nullable', 'string', 'max:500'],
            'ass_model'          => ['nullable', 'string', 'max:100'],
            'ass_serail'         => ['nullable', 'string', 'max:30'],
            'ass_price'          => ['nullable', 'numeric', 'min:0'],
            'ass_contact_no'     => ['nullable', 'string', 'max:20'],
            'ass_contact_date'   => ['nullable', 'date'],
            'dealer_id'          => ['nullable', 'integer'],
            'inspect_date'       => ['nullable', 'date'],
            'warranty'           => ['nullable', 'integer', 'min:0'],
            'ass_lifetime'       => ['nullable', 'integer', 'min:0'],
            'remarks'            => ['nullable', 'string', 'max:500'],
            'ass_status'       => ['required', 'string', 'in:1,2,3,4,5,6'],
            'replacement_status' => ['required', 'string', 'in:no,yes'],
            'asset_images'     => ['nullable', 'array', 'max:3'],
            'asset_images.*'   => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif', 'max:1024'],
            'remove_image_ids'   => ['nullable', 'array'],
            'remove_image_ids.*' => ['integer'],
        ], [
            'ass_code.required'  => 'กรุณากรอกรหัสครุภัณฑ์ประจำหน่วยงาน',
            'ass_code.max'       => 'รหัสครุภัณฑ์ประจำหน่วยงานต้องไม่เกิน 15 ตัวอักษร',
            'asscat_id.required' => 'กรุณาเลือกประเภทครุภัณฑ์',
            'asscat_id.exists'   => 'ประเภทครุภัณฑ์ที่เลือกไม่ถูกต้อง',
            'ass_status.required' => 'กรุณาเลือกสถานะ',
            'ass_status.in'       => 'สถานะที่เลือกไม่ถูกต้อง',
            'replacement_status.required' => 'กรุณาเลือกสถานะการซื้อทดแทน',
            'replacement_status.in'       => 'สถานะการซื้อทดแทนที่เลือกไม่ถูกต้อง',
            'asset_images.*.max' => 'ไฟล์รูปภาพต้องมีขนาดไม่เกิน 1 MB ต่อไฟล์',
        ]);

        // Check duplicate ass_code (exclude self — PART H3)
        $newCode = trim($validated['ass_code']);
        $duplicate = DB::connection('oracle')->table('ASSET')
            ->whereRaw('UPPER(ass_code) = UPPER(?)', [$newCode])
            ->where('id', '!=', $id)
            ->exists();
        if ($duplicate) {
            return back()->withInput()
                ->withErrors(['ass_code' => 'รหัสทะเบียนครุภัณฑ์นี้มีอยู่แล้ว กรุณาใช้รหัสอื่น']);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($asset, $id, $validated, $request, $newCode): void {
                $userId = (int) Auth::id();

                $asset->update([
                    'ass_code'         => $newCode,
                    'asscat_id'        => (int) $validated['asscat_id'],
                    'ass_desc'         => $validated['ass_desc'] ?? null,
                    'ass_model'        => $validated['ass_model'] ?? null,
                    'ass_serail'       => $validated['ass_serail'] ?? null,
                    'ass_price'        => isset($validated['ass_price']) ? (float) $validated['ass_price'] : null,
                    'ass_contact_no'   => $validated['ass_contact_no'] ?? null,
                    'ass_contact_date' => $validated['ass_contact_date'] ?? null,
                    'dealer_id'        => isset($validated['dealer_id']) ? (int) $validated['dealer_id'] : null,
                    'inspect_date'     => $validated['inspect_date'] ?? null,
                    'warranty'         => isset($validated['warranty']) ? (int) $validated['warranty'] : null,
                    'ass_lifetime'     => isset($validated['ass_lifetime']) ? (int) $validated['ass_lifetime'] : null,
                    // Price, inspect date or useful life may have changed: recalculate มูลค่าคงเหลือ as of today.
                    'remain_price'     => app(AssetDepreciationCalculator::class)->remainingValue(
                        $validated['ass_price'] ?? null,
                        $validated['ass_lifetime'] ?? null,
                        $validated['inspect_date'] ?? null,
                    ),
                    'remarks'          => $validated['remarks'] ?? null,
                    'ass_status'       => $validated['ass_status'],
                    'replacement_status' => $validated['replacement_status'],
                    'updated_by'       => $userId,
                ]);

                // Remove images marked for deletion (PART K/L7)
                $removeIds = array_map('intval', (array) ($validated['remove_image_ids'] ?? []));
                if (!empty($removeIds)) {
                    $toRemove = DB::connection('oracle')->table('ASSET_IMAGE')
                        ->where('ass_id', $id)->whereIn('id', $removeIds)
                        ->get(['id', 'ass_image']);
                    foreach ($toRemove as $img) {
                        if ($img->ass_image && Storage::disk('public')->exists($img->ass_image)) {
                            Storage::disk('public')->delete($img->ass_image);
                        }
                    }
                    DB::connection('oracle')->table('ASSET_IMAGE')
                        ->where('ass_id', $id)->whereIn('id', $removeIds)->delete();
                }

                // Add new uploaded images to ASSET_IMAGE (PART K)
                if ($request->hasFile('asset_images')) {
                    $dir = 'asset-images/' . $id;
                    foreach ($request->file('asset_images') as $img) {
                        if ($img && $img->isValid()) {
                            $path = $img->store($dir, 'public');
                            if ($path) {
                                $imgId = (int) DB::connection('oracle')
                                    ->selectOne('SELECT ASSET_IMAGE_SEQ.NEXTVAL AS nv FROM DUAL')->nv;
                                DB::connection('oracle')->table('ASSET_IMAGE')->insert([
                                    'id'         => $imgId,
                                    'ass_id'     => $id,
                                    'ass_image'  => $path,
                                    'created_by' => $userId,
                                    'created_at' => DB::raw('SYSTIMESTAMP'),
                                    'updated_by' => $userId,
                                    'updated_at' => DB::raw('SYSTIMESTAMP'),
                                ]);
                            }
                        }
                    }
                }
            });
        } catch (Throwable $e) {
            Log::error('ASS-003 update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return back()->withInput()->withErrors(['general' => 'แก้ไขข้อมูลไม่สำเร็จ กรุณาลองอีกครั้ง']);
        }

        return redirect()->route('asset.registrations.index')
            ->with('asset_success', 'แก้ไขข้อมูลครุภัณฑ์เรียบร้อยแล้ว');
    }

    public function destroy(int $id): RedirectResponse
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);
        $asset = Asset::query()->whereIn('org_id', $visibleOrgIds)->findOrFail($id);

        try {
            $asset->delete();
        } catch (Throwable $e) {
            Log::error('ASS-003 destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return redirect()->route('asset.registrations.index')
                ->with('asset_error', 'ไม่สามารถลบครุภัณฑ์นี้ได้ เนื่องจากมีข้อมูลที่เกี่ยวข้องอยู่');
        }

        return redirect()->route('asset.registrations.index')
            ->with('asset_success', 'ลบข้อมูลครุภัณฑ์เรียบร้อยแล้ว');
    }

    // =========================================================================
    // Replacement Budget Forecast (ASS-003)
    // =========================================================================

    /**
     * GET: dedicated forecast page, opened from the ASS-003 list.
     * Calculation goes through calculateForecastBudget(); the asset list through forecastAssets() and
     * printing through forecastPrint().
     */
    public function forecastPage(Request $request): View
    {
        // ?restore=1 (ย้อนกลับ on the asset list, or the browser's back button: forecast.js puts it in the
        // address after each calculation) shows the last calculation again, with the conditions it used.
        $latest = session('replacement_forecast_latest');
        $restored = $request->boolean('restore') && is_array($latest) && ($latest['success'] ?? false) === true;
        $params = $restored ? session('replacement_forecast_params', []) : [];
        $percent = (float) ($params['increase_percent'] ?? ReplacementBudgetCalculator::DEFAULT_PERCENT);

        return view('asset.ASS-003-manage-asset-registration.forecast', [
            'pageTitle'          => 'พยากรณ์งบประมาณจัดซื้อครุภัณฑ์ทดแทน',
            // หมวดครุภัณฑ์ = ASSET_CATEGORY.asscat_group (asscat_name is the asset name, not the category)
            'forecastCategories' => AssetCategory::groupOptions(),
            'forecastOrgs'       => $this->getChildOrgs((int) Auth::user()->org_id),
            'restoredForecast'   => $restored ? $latest : null,
            'forecastInputs'     => [
                'years'     => (int) ($params['forecast_years'] ?? 1),
                'cat_group' => (string) ($params['filter_cat_group'] ?? ''),
                'org_id'    => (string) ($params['filter_org_id'] ?? ''),
                'percent'   => rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'),
            ],
        ]);
    }

    /**
     * POST endpoint: replacement budget for the assets due in the forecast period.
     *
     * Which assets are due, and when, comes from the asset registry (inspect_date + ass_lifetime); each is
     * budgeted at its purchase value plus the percentage entered on the page (ReplacementBudgetCalculator).
     * Stores the last successful result in session (per-user) for the asset list and the print view.
     */
    public function calculateForecastBudget(
        Request $request,
        ReplacementForecastDataService $forecastData,
        ReplacementBudgetCalculator $calculator,
    ): JsonResponse {
        // Validated manually: bootstrap/app.php renders exceptions as JSON only for api/*,
        // so $request->validate() would answer this fetch() call with a redirect.
        $validator = Validator::make($request->all(), [
            'forecast_years'   => ['required', 'integer', 'min:1', 'max:3'],
            'increase_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'filter_cat_group' => ['nullable', 'string', 'max:100'],
            'filter_org_id'    => ['nullable', 'integer'],
        ], [
            'forecast_years.required'   => 'กรุณาเลือกระยะเวลาพยากรณ์',
            'forecast_years.integer'    => 'ระยะเวลาพยากรณ์ต้องเป็น 1, 2 หรือ 3 ปี',
            'forecast_years.min'        => 'ระยะเวลาพยากรณ์ต้องเป็น 1, 2 หรือ 3 ปี',
            'forecast_years.max'        => 'ระยะเวลาพยากรณ์ต้องเป็น 1, 2 หรือ 3 ปี',
            'increase_percent.required' => 'กรุณากรอก % ที่เพิ่มจากมูลค่าตอนซื้อ',
            'increase_percent.numeric'  => '% ที่เพิ่มจากมูลค่าตอนซื้อต้องเป็นตัวเลข 0–1000',
            'increase_percent.min'      => '% ที่เพิ่มจากมูลค่าตอนซื้อต้องเป็นตัวเลข 0–1000',
            'increase_percent.max'      => '% ที่เพิ่มจากมูลค่าตอนซื้อต้องเป็นตัวเลข 0–1000',
            'filter_cat_group.string'   => 'หมวดครุภัณฑ์ที่เลือกไม่ถูกต้อง',
            'filter_cat_group.max'      => 'หมวดครุภัณฑ์ที่เลือกไม่ถูกต้อง',
            'filter_org_id.integer'     => 'หน่วยงานที่เลือกไม่ถูกต้อง',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            return response()->json([
                'success' => false,
                'code'    => match (true) {
                    $errors->has('forecast_years')   => 'INVALID_FORECAST_YEARS',
                    $errors->has('increase_percent') => 'INVALID_PERCENT',
                    default                            => 'INVALID_REQUEST',
                },
                'message' => $errors->first(),
                'errors'  => $errors->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        $forecastYears   = (int) $validated['forecast_years'];
        $increasePercent = round((float) $validated['increase_percent'], 2);
        // หมวดครุภัณฑ์ name (ASSET_CATEGORY.asscat_group); empty = ทั้งหมด
        $filterCatGroup = trim((string) ($validated['filter_cat_group'] ?? ''));
        $filterCatGroup = $filterCatGroup === '' ? null : $filterCatGroup;
        $filterOrgId   = isset($validated['filter_org_id']) ? (int) $validated['filter_org_id'] : null;
        $baseYear      = now()->year;

        // A failed calculation must never leave an older result behind for the asset list or printing.
        session()->forget(['replacement_forecast_latest', 'replacement_forecast_params']);

        try {
            $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

            if ($filterOrgId !== null && !in_array($filterOrgId, $visibleOrgIds, true)) {
                return $this->forecastError('INVALID_ORGANIZATION', 'หน่วยงานที่เลือกไม่ถูกต้อง หรือไม่มีสิทธิ์เข้าถึง', 422);
            }

            if ($filterCatGroup !== null && !$forecastData->categoryGroupExists($filterCatGroup)) {
                return $this->forecastError('INVALID_CATEGORY', 'หมวดครุภัณฑ์ที่เลือกไม่ถูกต้อง', 422);
            }
            $categoryName = $filterCatGroup ?? 'ทั้งหมด';

            $orgName = $filterOrgId !== null
                ? ($forecastData->organizationName($filterOrgId) ?? '-')
                : 'ทั้งหมด';

            // Assets whose useful life ends within the forecast period, with their depreciation
            $candidateAssets = empty($visibleOrgIds) ? [] : $forecastData->candidateAssets(
                $forecastYears, $baseYear, $filterCatGroup, $filterOrgId, $visibleOrgIds
            );
        } catch (Throwable $e) {
            Log::error('ASS-003 forecast: database query failed', ['error' => $e->getMessage()]);
            return $this->forecastError(
                'DATABASE_UNAVAILABLE',
                'ไม่สามารถดึงข้อมูลครุภัณฑ์จากฐานข้อมูลได้ในขณะนี้ กรุณาลองใหม่ภายหลัง',
                503,
            );
        }

        $result = $calculator->calculate($forecastYears, $baseYear, $increasePercent, $candidateAssets);
        $result['depreciation'] = [
            'method'         => AssetDepreciationCalculator::METHOD,
            'residual_value' => AssetDepreciationCalculator::RESIDUAL_VALUE,
            'as_of_date'     => now()->toDateString(),
        ];

        // Kept for the asset list and the print view (user-scoped via Laravel session)
        $calculatedAt = now();
        session([
            'replacement_forecast_latest' => $result,
            'replacement_forecast_params' => [
                'forecast_years'   => $forecastYears,
                'increase_percent' => $increasePercent,
                'filter_cat_group' => $filterCatGroup,
                'filter_org_id'    => $filterOrgId,
                'filter_cat_name'  => $categoryName,
                'filter_org_name'  => $orgName,
                'calculated_at'    => $calculatedAt->format('d/m/') . ($calculatedAt->year + 543) . $calculatedAt->format(' H:i'),
            ],
        ]);

        return response()->json($result);
    }

    /**
     * GET: renders the print view using the last forecasted result stored in session.
     */
    public function forecastPrint(): View
    {
        $result = session('replacement_forecast_latest');
        $params = session('replacement_forecast_params', []);

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            abort(404, 'ไม่พบข้อมูลการพยากรณ์ กรุณาคำนวณก่อนจัดพิมพ์');
        }

        return view('asset.ASS-003-manage-asset-registration.forecast-print', [
            'result'    => $result,
            'params'    => $params,
            'printDate' => now()->format('d/m/') . (now()->year + 543),
        ]);
    }

    /** Sortable columns of the forecast asset list (asset fields, as on the forecast print). */
    private const FORECAST_ASSET_SORTS = [
        'sequence', 'asset_code', 'category_name', 'category_group', 'organization_name', 'inspect_date',
        'forecast_year', 'acquisition_value', 'accumulated_depreciation', 'current_value', 'replacement_budget',
    ];

    /**
     * GET: assets due for replacement, from the last calculation (session, like forecastPrint()).
     * ?year= (Gregorian) shows one replacement year, opened from that year on the forecast page;
     * without it every year of the selected forecast period ("แสดงทั้งหมด").
     */
    public function forecastAssets(Request $request): View
    {
        $list = $this->forecastAssetList($request);
        $assets = $list['assets'];

        $perPage = 10;
        $page = min(max(1, $request->integer('page', 1)), max(1, (int) ceil($assets->count() / $perPage)));
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $assets->forPage($page, $perPage)->values(),
            $assets->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('asset.ASS-003-manage-asset-registration.forecast-assets', [
            'pageTitle'   => 'รายการครุภัณฑ์ที่ต้องจัดซื้อทดแทน',
            ...$list,
            'assets'      => $paginator,
            // The print and Excel icons export the whole list, in the order shown.
            'exportQuery' => array_filter($request->only(['year', 'sort', 'direction']), fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    /** GET: the forecast asset list as an A4 landscape PDF (print icon), same rows and order as on screen. */
    public function forecastAssetsPdf(Request $request)
    {
        $list = $this->forecastAssetList($request);
        abort_unless($list['hasResult'] && $list['yearKnown'], 404, 'ไม่พบผลการพยากรณ์ กรุณาคำนวณก่อนจัดพิมพ์');

        $pdf = Pdf::loadView('asset.ASS-003-manage-asset-registration.forecast-assets-pdf', $list)->setPaper('a4', 'landscape');
        $filename = $this->forecastExportName($list['year'], 'pdf');

        return config('app.pdf_test_mode') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /** GET: the forecast asset list as an Excel file (Excel icon), same rows and order as on screen. */
    public function forecastAssetsExcel(Request $request, XlsxReportService $xlsx)
    {
        $list = $this->forecastAssetList($request);
        abort_unless($list['hasResult'] && $list['yearKnown'], 404, 'ไม่พบผลการพยากรณ์ กรุณาคำนวณก่อนดาวน์โหลด');

        return $xlsx->structuredForecastAssets(
            $this->forecastExportName($list['year'], 'xlsx'),
            'รายการครุภัณฑ์ที่ต้องจัดซื้อทดแทน ' . $list['heading'],
            $list['conditions'],
            $list['percent'],
            $list['assets']->all(),
            $list['totalBudget'],
        );
    }

    private function forecastExportName(?int $year, string $extension): string
    {
        return 'replacement-forecast-' . ($year !== null ? $year + 543 : 'all') . '.' . $extension;
    }

    /**
     * The forecast asset list shared by the page, its PDF and its Excel file: the last calculation in the
     * session, narrowed to ?year= and ordered by ?sort= / ?direction=.
     *
     * @return array<string, mixed>
     */
    private function forecastAssetList(Request $request): array
    {
        $result = session('replacement_forecast_latest');
        $params = session('replacement_forecast_params', []);
        $hasResult = is_array($result) && ($result['success'] ?? false) === true;
        $year = $request->filled('year') && is_numeric($request->input('year')) ? $request->integer('year') : null;

        $assets = collect($hasResult ? ($result['assets'] ?? []) : [])
            ->when($year !== null, fn ($assets) => $assets->filter(fn (array $asset) => (int) ($asset['forecast_year'] ?? 0) === $year))
            ->values();

        // รหัสครุภัณฑ์ as the asset list page shows it, from the asset's current data (the session keeps only ass_code)
        $displayCodes = $this->forecastDisplayCodes($assets->pluck('asset_id')->filter()->map(fn ($id) => (int) $id)->all());
        $assets = $assets->map(fn (array $asset, int $index) => $asset + [
            'sequence'     => $index + 1,
            'display_code' => $displayCodes[(int) ($asset['asset_id'] ?? 0)] ?? (($asset['asset_code'] ?? '') !== '' ? $asset['asset_code'] : '-'),
        ]);

        $sort = in_array($request->string('sort')->value(), self::FORECAST_ASSET_SORTS, true) ? $request->string('sort')->value() : '';
        $direction = $request->string('direction')->value() === 'desc' ? 'desc' : 'asc';
        if ($sort !== '') {
            // The code column sorts by the code as shown
            $sortKey = $sort === 'asset_code' ? 'display_code' : $sort;
            $value = fn (array $asset) => $asset[$sortKey] ?? null;
            // Empty values stay last in both directions; equal values keep the calculated order.
            $assets = $assets->sort(function (array $a, array $b) use ($value, $direction) {
                [$x, $y] = [$value($a), $value($b)];
                if ($x === null || $x === '' || $y === null || $y === '') {
                    return ($x === null || $x === '') <=> ($y === null || $y === '') ?: $a['sequence'] <=> $b['sequence'];
                }
                $order = is_numeric($x) && is_numeric($y) ? (float) $x <=> (float) $y : strcmp((string) $x, (string) $y);

                return ($direction === 'desc' ? -$order : $order) ?: $a['sequence'] <=> $b['sequence'];
            })->values();
        }

        $startYear = $hasResult ? ($result['start_year'] ?? null) : null;
        $endYear = $hasResult ? ($result['end_year'] ?? null) : null;
        $period = $startYear && $endYear
            ? ($startYear === $endYear ? 'พ.ศ. ' . ($startYear + 543) : 'พ.ศ. ' . ($startYear + 543) . ' – ' . ($endYear + 543))
            : '';
        $percent = rtrim(rtrim(number_format((float) ($result['increase_percent'] ?? $params['increase_percent'] ?? 0), 2), '0'), '.');
        $forecastYears = $params['forecast_years'] ?? ($result['forecast_years'] ?? '-');

        return [
            'hasResult'   => $hasResult,
            'result'      => $hasResult ? $result : [],
            'params'      => $params,
            'year'        => $year,
            'yearKnown'   => $year === null || collect($hasResult ? ($result['years'] ?? []) : [])->contains(fn ($y) => (int) ($y['year'] ?? 0) === $year),
            'period'      => $period,
            'heading'     => $year !== null ? 'ครบกำหนดทดแทนปี พ.ศ. ' . ($year + 543) : 'ทั้งหมด' . ($period !== '' ? " ({$period})" : ''),
            'percent'     => $percent,
            // One line of the calculation's conditions, for the PDF and Excel headers.
            'conditions'  => "ระยะเวลาพยากรณ์ {$forecastYears} ปี" . ($period !== '' ? " ({$period})" : '')
                . ' · หมวดครุภัณฑ์: ' . ($params['filter_cat_name'] ?? 'ทั้งหมด')
                . ' · หน่วยงาน: ' . ($params['filter_org_name'] ?? 'ทั้งหมด')
                . " · งบประมาณทดแทน = มูลค่าตอนซื้อ + {$percent}%"
                . (! empty($params['calculated_at']) ? ' · คำนวณเมื่อ ' . $params['calculated_at'] : ''),
            'assets'      => $assets,
            'totalBudget' => $assets->sum(fn (array $asset) => (float) ($asset['replacement_budget'] ?? 0)),
            'sort'        => $sort,
            'direction'   => $direction,
        ];
    }

    /**
     * Display codes (AssetDisplayService::displayCode, as on the asset list page) by asset id.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, string>
     */
    private function forecastDisplayCodes(array $assetIds): array
    {
        $codes = [];
        // Oracle allows at most 1000 items in an IN list
        foreach (array_chunk(array_values(array_unique($assetIds)), 1000) as $chunk) {
            DB::connection('oracle')->table('ASSET AS a')
                ->join('ASSET_CATEGORY AS c', 'a.asscat_id', '=', 'c.id')
                ->whereIn('a.id', $chunk)
                ->select([
                    'a.id',
                    'a.ass_code',
                    'c.asscat_code',
                    DB::raw("(SELECT MAX(aa2.status) KEEP (DENSE_RANK LAST ORDER BY aa2.id)
                              FROM ASSET_ASSIGNMENT_LIST aal2
                              JOIN ASSET_ASSIGNMENT aa2 ON aa2.id = aal2.ass_assign_id
                              WHERE aal2.asset_id = a.id) AS aa_status"),
                ])
                ->get()
                ->each(function ($row) use (&$codes) {
                    $codes[(int) $row->id] = $this->assetDisplay->displayCode($row);
                });
        }

        return $codes;
    }

    // -------------------------------------------------------------------------
    // Private helpers for the replacement forecast
    // -------------------------------------------------------------------------

    private function forecastError(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => $message], $status);
    }

    /** Returns direct child org records (not self) for the forecast dropdown. */
    private function getChildOrgs(int $userOrgId): array
    {
        if ($userOrgId <= 0) {
            return [];
        }

        try {
            $org = DB::connection('oracle')->selectOne(
                'SELECT org_id, zone_flg FROM GLB_ORGANIZATION WHERE org_id = ?',
                [$userOrgId]
            );

            if ($org === null) {
                return [];
            }

            $zoneFlg = strtoupper(trim((string) ($org->zone_flg ?? '')));

            if ($zoneFlg === 'C') {
                $rows = DB::connection('oracle')->select(
                    "SELECT org_id, org_name FROM GLB_ORGANIZATION WHERE UPPER(zone_flg)='C' AND org_id <> ? ORDER BY org_name",
                    [$userOrgId]
                );
            } elseif ($zoneFlg === 'R') {
                $rows = DB::connection('oracle')->select(
                    'SELECT org_id, org_name FROM GLB_ORGANIZATION WHERE org_org_id = ? ORDER BY org_name',
                    [$userOrgId]
                );
            } else {
                return [];
            }

            return array_map(static fn ($r) => [
                'value'      => (int) $r->org_id,
                'label'      => $r->org_name ?? '',
                'searchText' => $r->org_name ?? '',
            ], $rows);
        } catch (Throwable) {
            return [];
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }
}
