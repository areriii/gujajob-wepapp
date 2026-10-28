<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use App\Models\GlbOrganization;
use App\Models\Material;
use App\Models\MaterialProcurement;
use App\Models\MaterialProcurementList;
use App\Services\MaterialInventoryService;
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

class MaterialReceivingController extends Controller
{
    public function __construct(
        private readonly OrganizationVisibilityService $orgVisibility,
        private readonly MaterialInventoryService $inventory,
    ) {}
    public function index(Request $request): View
    {
        $searchBy  = $request->string('search_by', 'receipt_no')->value();
        $keyword   = trim($request->string('keyword')->value());
        $sort      = $request->string('sort', '')->value();
        $direction = strtolower($request->string('direction', 'asc')->value()) === 'desc' ? 'desc' : 'asc';

        $sortableColumns = [
            'receipt_no'     => 'mat_pro_code',
            'received_date'  => 'mat_pro_date',
            'method'         => 'mat_pro_method',
            'total_quantity' => 'total_received_qty',
        ];

        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        $query = MaterialProcurement::query()
            ->whereIn('org_id', $visibleOrgIds)
            ->withSum('details as total_received_qty', 'mat_amt');

        if ($sort === 'total_quantity') {
            // Oracle: ORDER BY aggregate alias works when alias is in the SELECT list
            $query->orderBy('total_received_qty', $direction)->orderBy('id', $direction);
        } elseif (array_key_exists($sort, $sortableColumns)) {
            $query->orderBy($sortableColumns[$sort], $direction)->orderBy('id', $direction);
        } else {
            $query->orderByRaw('UPPER(mat_pro_code) ASC')->orderBy('id', 'ASC');
        }

        if ($keyword !== '') {
            if ($searchBy === 'receipt_no') {
                $query->whereRaw('UPPER(mat_pro_code) LIKE ?', ['%' . mb_strtoupper($keyword) . '%']);
            } elseif ($searchBy === 'received_date') {
                $query->whereRaw("TO_CHAR(mat_pro_date, 'DD/MM/YYYY') LIKE ?", ['%' . $keyword . '%']);
            } elseif ($searchBy === 'organization') {
                $query->whereHas('organization', function ($q) use ($keyword) {
                    $q->whereRaw('UPPER(org_name) LIKE ?', ['%' . mb_strtoupper($keyword) . '%']);
                });
            }
        }

        return view('material.MAT-002-record-material-receiving.index', [
            'pageTitle'        => 'บันทึกการรับวัสดุเข้าคลัง',
            'receivingRecords' => $query->paginate(10)->withQueryString(),
            'searchBy'         => $searchBy,
            'keyword'          => $keyword,
            'sort'             => $sort,
            'direction'        => $direction,
            'methodOptions'    => $this->methodOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $searchBy = $request->string('search_by', 'name')->value();
        $keyword  = trim($request->string('keyword')->value());

        $materialsQuery = Material::query()->orderBy('mat_code');

        if ($keyword !== '') {
            if ($searchBy === 'code') {
                $materialsQuery->whereRaw('UPPER(mat_code) LIKE ?', ['%' . mb_strtoupper($keyword) . '%']);
            } else {
                $materialsQuery->whereRaw('UPPER(mat_name) LIKE ?', ['%' . mb_strtoupper($keyword) . '%']);
            }
        }

        return view('material.MAT-002-record-material-receiving.create', [
            'pageTitle'           => 'บันทึกการรับวัสดุเข้าคลัง',
            'nextReceiptNo'       => $this->peekProcurementCode(Carbon::now()),
            'record'              => null,
            'organizationOptions' => $this->organizationOptions(),
            'dealerOptions'       => $this->dealerOptions(),
            'materials'           => $this->materials(),
            'materialsPage'       => $materialsQuery->paginate(10)->withQueryString(),
            'searchBy'            => $searchBy,
            'keyword'             => $keyword,
            'methodOptions'       => $this->methodOptions(),
            'vatOptions'          => $this->vatOptions(),
        ]);
    }

    /**
     * Step 1: Save the MATERIAL_PROCUREMENT header row, plus any items the user
     * already staged (filled in quantity/price for) in the materials table before
     * the header existed. Both are created together in a single transaction.
     */
    public function storeHeader(Request $request): RedirectResponse
    {
        try {
            $validated = $this->validateHeader($request, true);

            // Parse draft items JSON submitted by the create form (single-pass flow)
            if ($request->filled('draft_items_json')) {
                $rawItems = json_decode((string) $request->input('draft_items_json'), true);
                if (is_array($rawItems)) {
                    $request->merge(['items' => $rawItems]);
                }
            }

            $stagedItems = $this->validateStagedItems($request);

            $userId = (int) Auth::id();
            $procurementId = DB::connection('oracle')->transaction(function () use ($validated, $stagedItems, $userId) {
                $generatedCode = $this->generateProcurementCode(Carbon::parse($validated['mat_pro_date']));
                $procurementId = $this->nextProcurementId();

                MaterialProcurement::create([
                    'id' => $procurementId,
                    'mat_pro_code'         => $generatedCode,
                    'dealer_id'            => (int) $validated['dealer_id'],
                    'org_id'               => (int) $validated['org_id'],
                    'mat_pro_date' => $validated['mat_pro_date'],
                    'mat_pro_method' => isset($validated['mat_pro_method']) ? (int) $validated['mat_pro_method'] : null,
                    'mat_pro_contact_no' => $validated['mat_pro_contact_no'] ?? null,
                    'mat_pro_contact_date' => $validated['mat_pro_contact_date'] ?? null,
                    'mat_pro_quotation' => $validated['mat_pro_quotation'] ?? null,
                    'vat_type' => (int) $validated['vat_type'],
                    'vat_rate' => match ((int) $validated['vat_type']) {
                        2 => null,
                        3 => 0,
                        default => $validated['vat_rate'] ?? 7,
                    },
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $lines = [];
                foreach ($stagedItems as $item) {
                    $lines[] = MaterialProcurementList::create([
                        'id' => $this->nextProcurementListId(),
                        'mat_pro_id' => $procurementId,
                        'mat_id' => $item['mat_id'],
                        'mat_amt' => $item['mat_amt'],
                        'mat_price' => $item['mat_price'],
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                }
                $this->inventory->applyReceiptLines(null, [], (int) $validated['org_id'], $lines, $userId);

                return $procurementId;
            });

            $newRecord = MaterialProcurement::query()->findOrFail($procurementId);

            return redirect()
                ->route('material.receiving.edit', $newRecord->mat_pro_code)
                ->with('success', 'บันทึกข้อมูลเอกสารการรับวัสดุเรียบร้อยแล้ว');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('MAT-002 storeHeader failed', ['error' => $exception->getMessage()]);

            return back()
                ->withInput()
                ->with('error', 'ไม่สามารถบันทึกข้อมูลเอกสารการรับวัสดุได้ กรุณาตรวจสอบข้อมูลและลองใหม่อีกครั้ง');
        }
    }

    /**
     * AJAX endpoint: save staged items (and optionally header fields) in one shot.
     * Header fields are all optional – missing ones get sensible defaults so the
     * user can fill them in later via the edit page.
     */
    public function saveItems(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'items'                => ['required', 'array', 'min:1'],
                'items.*.material_id'  => ['required', 'integer', 'min:1'],
                'items.*.quantity'     => ['required', 'numeric', 'gt:0'],
                'items.*.unit_price'   => ['required', 'numeric', 'min:0'],
                'mat_pro_date'         => ['nullable', 'date'],
                'org_id'               => ['nullable', 'integer'],
                'dealer_id'            => ['nullable', 'integer'],
                'mat_pro_method'       => ['nullable', 'integer'],
                'mat_pro_contact_no'   => ['nullable', 'string', 'max:100'],
                'mat_pro_contact_date' => ['nullable', 'date'],
                'mat_pro_quotation'    => ['nullable', 'string', 'max:100'],
                'vat_type'             => ['nullable', 'integer'],
                'vat_rate'             => ['nullable', 'numeric', 'min:0'],
            ]);

            $stagedItems = [];
            foreach ($validated['items'] as $item) {
                $matId = (int) $item['material_id'];
                if (Material::query()->where('id', $matId)->exists()) {
                    $stagedItems[] = [
                        'mat_id'   => $matId,
                        'mat_amt'  => (float) $item['quantity'],
                        'mat_price'=> (float) $item['unit_price'],
                    ];
                }
            }

            if (empty($stagedItems)) {
                return response()->json(['error' => 'ไม่พบรายการวัสดุที่ถูกต้อง'], 422);
            }

            $vatType  = (int) ($validated['vat_type'] ?? 1);

            // Default org/dealer/date when not supplied by user
            $orgId    = isset($validated['org_id'])    ? (int) $validated['org_id']    : $this->defaultOrgId();
            $dealerId = isset($validated['dealer_id']) ? (int) $validated['dealer_id'] : $this->defaultDealerId();
            $proDate  = $validated['mat_pro_date'] ?? now()->format('Y-m-d');
            $userId2  = (int) Auth::id();

            $procurementId = DB::connection('oracle')->transaction(function () use ($validated, $stagedItems, $vatType, $orgId, $dealerId, $proDate, $userId2) {
                $generatedCode = $this->generateProcurementCode(Carbon::parse($proDate));
                $procurementId = $this->nextProcurementId();

                MaterialProcurement::create([
                    'id'                   => $procurementId,
                    'mat_pro_code'         => $generatedCode,
                    'dealer_id'            => $dealerId,
                    'org_id'               => $orgId,
                    'mat_pro_date'         => $proDate,
                    'mat_pro_method'       => isset($validated['mat_pro_method']) ? (int) $validated['mat_pro_method'] : null,
                    'mat_pro_contact_no'   => $validated['mat_pro_contact_no']   ?? null,
                    'mat_pro_contact_date' => $validated['mat_pro_contact_date'] ?? null,
                    'mat_pro_quotation'    => $validated['mat_pro_quotation']    ?? null,
                    'vat_type'             => $vatType,
                    'vat_rate'             => match ($vatType) {
                        2 => null,
                        3 => 0,
                        default => $validated['vat_rate'] ?? 7,
                    },
                    'created_by'           => $userId2,
                    'updated_by'           => $userId2,
                ]);

                $lines = [];
                foreach ($stagedItems as $item) {
                    $lines[] = MaterialProcurementList::create([
                        'id'         => $this->nextProcurementListId(),
                        'mat_pro_id' => $procurementId,
                        'mat_id'     => $item['mat_id'],
                        'mat_amt'    => $item['mat_amt'],
                        'mat_price'  => $item['mat_price'],
                        'created_by' => $userId2,
                        'updated_by' => $userId2,
                    ]);
                }
                $this->inventory->applyReceiptLines(null, [], $orgId, $lines, $userId2);

                return $procurementId;
            });

            $newRecord = MaterialProcurement::query()->findOrFail($procurementId);

            return response()->json([
                'redirect' => route('material.receiving.edit', $newRecord->mat_pro_code),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => implode(' ', $e->validator->errors()->all())], 422);
        } catch (Throwable $e) {
            Log::error('MAT-002 saveItems failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง'], 500);
        }
    }

    public function show(string $receiptNo): View
    {
        $record = $this->findByCode($receiptNo);

        return view('material.MAT-002-record-material-receiving.show', [
            'pageTitle'     => 'บันทึกการรับวัสดุเข้าคลัง',
            'record'        => $record,
            'dealerOptions' => $this->dealerOptions(),
            'methodOptions' => $this->methodOptions(),
            'vatOptions'    => $this->vatOptions(),
        ]);
    }

    public function edit(string $receiptNo): View
    {
        $record = $this->findByCode($receiptNo);

        return view('material.MAT-002-record-material-receiving.edit', [
            'pageTitle'           => 'บันทึกการรับวัสดุเข้าคลัง',
            'record'              => $record,
            'organizationOptions' => $this->organizationOptions(),
            'dealerOptions'       => $this->dealerOptions(),
            'materials'           => $this->materials(),
            'methodOptions'       => $this->methodOptions(),
            'vatOptions'          => $this->vatOptions(),
        ]);
    }

    /**
     * Header-only update. Also handles optional item quantity changes from inline edit.
     */
    public function update(Request $request, string $receiptNo): RedirectResponse
    {
        $record = $this->findByCode($receiptNo);

        try {
            $validated = $this->validateHeader($request, false, $record->id);

            DB::connection('oracle')->transaction(function () use ($record, $validated, $request): void {
                $userId = (int) Auth::id();
                $orgBefore = (int) $record->org_id;
                $linesBefore = $this->receiptLines($record->id);
                $record->update([
                    'dealer_id'            => (int) $validated['dealer_id'],
                    'org_id'               => (int) $validated['org_id'],
                    'mat_pro_date' => $validated['mat_pro_date'],
                    'mat_pro_method' => isset($validated['mat_pro_method']) ? (int) $validated['mat_pro_method'] : null,
                    'mat_pro_contact_no' => $validated['mat_pro_contact_no'] ?? null,
                    'mat_pro_contact_date' => $validated['mat_pro_contact_date'] ?? null,
                    'mat_pro_quotation' => $validated['mat_pro_quotation'] ?? null,
                    'vat_type' => (int) $validated['vat_type'],
                    'vat_rate' => match ((int) $validated['vat_type']) {
                        2 => null,
                        3 => 0,
                        default => $validated['vat_rate'] ?? 7,
                    },
                    'updated_by' => $userId,
                ]);

                // Optional: inline-edited item quantities and prices from hidden inputs
                $items = $request->input('items');
                if (is_array($items)) {
                    foreach ($items as $itemId => $data) {
                        $qty   = filter_var($data['qty']   ?? null, FILTER_VALIDATE_FLOAT);
                        $price = filter_var($data['price'] ?? null, FILTER_VALIDATE_FLOAT);
                        if ($qty === false || $qty <= 0) {
                            continue;
                        }
                        $updateData = ['mat_amt' => $qty, 'updated_by' => $userId];
                        if ($price !== false && $price >= 0) {
                            $updateData['mat_price'] = $price;
                        }
                        MaterialProcurementList::query()
                            ->where('id', (int) $itemId)
                            ->where('mat_pro_id', $record->id)
                            ->update($updateData);
                    }
                }

                // Quantity changes and a change of receiving organization move the stock with them.
                $this->inventory->applyReceiptLines($orgBefore, $linesBefore, (int) $validated['org_id'], $this->receiptLines($record->id), $userId);
            });

            // Saving is the last step of editing, so it returns to the list
            return redirect()
                ->route('material.receiving.index')
                ->with('success', 'บันทึกการแก้ไขข้อมูลเรียบร้อยแล้ว');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('MAT-002 update failed', [
                'receipt_no' => $receiptNo,
                'error' => $exception->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'ไม่สามารถแก้ไขข้อมูลเอกสารการรับวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    /**
     * Step 2: Add ONE item row to MATERIAL_PROCUREMENT_LIST for an already-saved header.
     * Called via AJAX (fetch) from the edit page; responds with JSON.
     */
    public function storeItem(Request $request, string $receiptNo)
    {
        $record = $this->findByCode($receiptNo);

        try {
            $item = $this->validateItemInput($request, $record->id);

            $created = DB::connection('oracle')->transaction(function () use ($record, $item) {
                $newId  = $this->nextProcurementListId();
                $userId = (int) Auth::id();

                $line = MaterialProcurementList::create([
                    'id'         => $newId,
                    'mat_pro_id' => $record->id,
                    'mat_id'     => $item['mat_id'],
                    'mat_amt'    => $item['mat_amt'],
                    'mat_price'  => $item['mat_price'],
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
                $this->inventory->applyReceiptLines((int) $record->org_id, [], (int) $record->org_id, [$line], $userId);

                return $line;
            });

            $material = Material::query()->find($item['mat_id']);

            return response()->json([
                'item' => [
                    'id' => (int) $created->id,
                    'mat_id' => (int) $created->mat_id,
                    'code' => $material?->mat_code,
                    'name' => $material?->mat_name,
                    'unit' => $material?->unit,
                    'qty' => (float) $created->mat_amt,
                    'price' => (float) $created->mat_price,
                ],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('MAT-002 storeItem failed', [
                'receipt_no' => $receiptNo,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'ไม่สามารถเพิ่มรายการวัสดุได้ กรุณาลองใหม่อีกครั้ง',
            ], 500);
        }
    }

    /**
     * Delete a single item row from MATERIAL_PROCUREMENT_LIST. Called via AJAX (fetch).
     */
    public function destroyItem(string $receiptNo, string $itemId)
    {
        $record = $this->findByCode($receiptNo);

        $item = MaterialProcurementList::query()
            ->where('mat_pro_id', $record->id)
            ->where('id', (int) $itemId)
            ->firstOrFail();

        try {
            DB::connection('oracle')->transaction(function () use ($item, $record): void {
                $this->inventory->applyReceiptLines((int) $record->org_id, [$item], (int) $record->org_id, [], (int) Auth::id());
                $item->delete();
            });

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            Log::error('MAT-002 destroyItem failed', [
                'receipt_no' => $receiptNo,
                'item_id' => $itemId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'ไม่สามารถลบรายการวัสดุได้ กรุณาลองใหม่อีกครั้ง',
            ], 500);
        }
    }

    /**
     * Step: finalize/complete the document. This is the ONLY place that requires
     * at least one item to exist in MATERIAL_PROCUREMENT_LIST.
     */
    public function finalize(string $receiptNo): RedirectResponse
    {
        $record = $this->findByCode($receiptNo);

        $itemCount = MaterialProcurementList::query()
            ->where('mat_pro_id', $record->id)
            ->count();

        if ($itemCount === 0) {
            return back()->with('finalize_error', 'กรุณาเพิ่มรายการวัสดุอย่างน้อย 1 รายการ');
        }

        return redirect()
            ->route('material.receiving.index')
            ->with('success', 'บันทึกข้อมูลการรับวัสดุเรียบร้อยแล้ว');
    }

    public function destroy(string $receiptNo): RedirectResponse
    {
        $record = $this->findByCode($receiptNo);

        try {
            DB::connection('oracle')->transaction(function () use ($record): void {
                $this->inventory->applyReceiptLines((int) $record->org_id, $this->receiptLines($record->id), (int) $record->org_id, [], (int) Auth::id());
                MaterialProcurementList::query()
                    ->where('mat_pro_id', $record->id)
                    ->delete();
                $record->delete();
            });

            return redirect()
                ->route('material.receiving.index')
                ->with('success', 'ลบข้อมูลใบรับวัสดุเรียบร้อยแล้ว');
        } catch (Throwable $e) {
            Log::error('MAT-002 destroy failed', ['receipt_no' => $receiptNo, 'error' => $e->getMessage()]);

            return redirect()
                ->route('material.receiving.index')
                ->with('error', 'ไม่สามารถลบข้อมูลใบรับวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    private function findByCode(string $receiptNo): MaterialProcurement
    {
        $visibleOrgIds = $this->orgVisibility->visibleOrgIds((int) Auth::user()->org_id);

        return MaterialProcurement::query()
            ->with(['organization', 'details.material'])
            ->where('mat_pro_code', $receiptNo)
            ->whereIn('org_id', $visibleOrgIds)
            ->firstOrFail();
    }

    private function validateHeader(Request $request, bool $isCreate, ?int $currentId = null): array
    {
        $rules = [
            'mat_pro_date'        => ['required', 'date'],
            'org_id'              => ['required', 'integer'],
            'dealer_id'           => ['required', 'integer', 'min:1'],
            'mat_pro_method'      => ['nullable', 'integer'],
            'mat_pro_contact_no'  => ['nullable', 'string', 'max:100'],
            'mat_pro_contact_date'=> ['nullable', 'date'],
            'mat_pro_quotation'   => ['nullable', 'string', 'max:100'],
            'vat_type'            => ['required', 'integer'],
            'vat_rate'            => ['nullable', 'numeric', 'min:0'],
        ];

        $validated = $request->validate($rules);

        $existsOrg = GlbOrganization::query()
            ->where('org_id', (int) $validated['org_id'])
            ->exists();

        if (! $existsOrg) {
            throw ValidationException::withMessages([
                'org_id' => 'ไม่พบหน่วยงานที่เลือก',
            ]);
        }

        if (! array_key_exists((int) ($validated['vat_type'] ?? 0), $this->vatOptions())) {
            throw ValidationException::withMessages([
                'vat_type' => 'รูปแบบ VAT ไม่ถูกต้อง',
            ]);
        }

        // VAT rate must be 7 or 10 when vat_type is 1 or 2
        if (in_array((int) ($validated['vat_type'] ?? 0), [1, 2], true)) {
            $rate = (int) ($validated['vat_rate'] ?? 0);
            if (! in_array($rate, [7, 10], true)) {
                throw ValidationException::withMessages([
                    'vat_rate' => 'อัตราภาษีต้องเป็น 7 หรือ 10',
                ]);
            }
        }

        if (isset($validated['mat_pro_method']) && $validated['mat_pro_method'] !== null) {
            if (! array_key_exists((int) $validated['mat_pro_method'], $this->methodOptions())) {
                throw ValidationException::withMessages([
                    'mat_pro_method' => 'วิธีการจัดซื้อจัดจ้างไม่ถูกต้อง',
                ]);
            }
        }

        return $validated;
    }

    /**
     * Validates the materials the user staged (filled in quantity/price for) on the
     * create page's materials table BEFORE the header existed. Invalid/duplicate
     * entries are silently skipped so the header can still be saved successfully.
     */
    private function validateStagedItems(Request $request): array
    {
        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.material_id' => ['required_with:items', 'integer', 'min:1'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        $items = $validated['items'] ?? [];
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $matId = (int) $item['material_id'];

            if (isset($seen[$matId]) || ! Material::query()->where('id', $matId)->exists()) {
                continue;
            }

            $seen[$matId] = true;

            $result[] = [
                'mat_id' => $matId,
                'mat_amt' => (float) $item['quantity'],
                'mat_price' => (float) $item['unit_price'],
            ];
        }

        return $result;
    }

    /**
     * Validates ONE item submitted via storeItem(). Never invoked from
     * storeHeader()/update(), so the header can always be saved with zero items.
     */
    private function validateItemInput(Request $request, int $procurementId): array
    {
        $validated = $request->validate([
            'material_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
        ], [], [
            'material_id' => 'วัสดุ',
            'quantity' => 'จำนวน',
            'unit_price' => 'ราคา/หน่วย',
        ]);

        $matId = (int) $validated['material_id'];

        $materialExists = Material::query()->where('id', $matId)->exists();

        if (! $materialExists) {
            throw ValidationException::withMessages([
                'material_id' => 'ไม่พบวัสดุที่เลือกในระบบ',
            ]);
        }

        $duplicateExists = MaterialProcurementList::query()
            ->where('mat_pro_id', $procurementId)
            ->where('mat_id', $matId)
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'material_id' => 'วัสดุรายการนี้ถูกเพิ่มแล้ว กรุณาแก้ไขจำนวนในรายการเดิม',
            ]);
        }

        return [
            'mat_id' => $matId,
            'mat_amt' => (float) $validated['quantity'],
            'mat_price' => (float) $validated['unit_price'],
        ];
    }

    private function nextProcurementId(): int
    {
        return (int) MaterialProcurement::query()->max('id') + 1;
    }

    /** The receipt's lines as MaterialInventoryService::applyReceiptLines() expects them. */
    private function receiptLines(int $procurementId)
    {
        return MaterialProcurementList::query()
            ->where('mat_pro_id', $procurementId)
            ->get(['id', 'mat_id', 'mat_amt', 'mat_price']);
    }

    private function nextProcurementListId(): int
    {
        return (int) MaterialProcurementList::query()->max('id') + 1;
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
     * Builds the next procurement code for the given date's fiscal year.
     * Format: MPR-YYNNNNN (10 chars, MPR prefix) e.g. MPR-6900001
     */
    private function buildProcurementCode(Carbon $date, bool $withLock): string
    {
        $yearCode = substr((string) $this->fiscalYear($date), -2);

        $query = MaterialProcurement::query()
            ->whereRaw('mat_pro_code LIKE ? AND LENGTH(mat_pro_code) = 11', ['MPR-' . $yearCode . '%']);

        if ($withLock) {
            $query->lockForUpdate();
        }

        $maxRunning = $query->get(['mat_pro_code'])
            ->reduce(function (int $carry, MaterialProcurement $record): int {
                if (preg_match('/^MPR-\d{2}(\d{5})$/', (string) $record->mat_pro_code, $m) === 1) {
                    return max($carry, (int) $m[1]);
                }

                return $carry;
            }, 0);

        return 'MPR-' . $yearCode . str_pad((string) ($maxRunning + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Use inside a DB transaction: locks rows to prevent concurrent duplicates.
     */
    private function generateProcurementCode(Carbon $date): string
    {
        return $this->buildProcurementCode($date, true);
    }

    /**
     * Preview-only (no lock): may be slightly stale under very high concurrency.
     */
    private function peekProcurementCode(Carbon $date): string
    {
        return $this->buildProcurementCode($date, false);
    }

    /**
     * AJAX endpoint: returns next procurement code preview for a given date.
     * Used by the create form when the user changes the receive date.
     */
    public function previewCode(Request $request): JsonResponse
    {
        try {
            $dateStr = trim((string) $request->input('date', ''));
            $date    = $dateStr !== '' ? Carbon::parse($dateStr) : Carbon::now();

            return response()->json([
                'code' => $this->peekProcurementCode($date),
            ]);
        } catch (Throwable $e) {
            return response()->json(['code' => ''], 200);
        }
    }

    /**
     * Formats all GLB_ORGANIZATION rows as [{value, label}] for the searchable-select
     * component. No limit — all 2,157 records are loaded for client-side filtering.
     */
    private function organizationOptions(): array
    {
        return $this->organizations()->map(function ($org) {
            return [
                'value'      => $org->org_id,
                'label'      => $org->org_code . ' - ' . $org->org_name,
                'searchText' => $org->org_code . ' ' . $org->org_name,
            ];
        })->values()->all();
    }

    /**
     * TEMPORARY MOCK DATA — replace when DEALER table has real rows.
     * To switch to live data, swap the return statement with the commented query.
     *
     * @return array<int,array{value:int,label:string}>
     */
    private function defaultOrgId(): int
    {
        return (int) (Auth::user()?->org_id ?? 0);
    }

    private function defaultDealerId(): int
    {
        $options = $this->dealerOptions();
        return (int) ($options[0]['value'] ?? 1);
    }

    private function dealerOptions(): array
    {
        // TODO: replace with live query once DEALER is populated:
        // return Dealer::query()->orderBy('dealer_name')
        //     ->get(['id', 'dealer_name'])
        //     ->map(fn($d) => ['value' => $d->id, 'label' => $d->dealer_name])
        //     ->values()->all();

        return [
            ['value' => 1, 'label' => 'บริษัท ออฟฟิศพลัส (ประเทศไทย) จำกัด',  'searchText' => 'ออฟฟิศพลัส ประเทศไทย'],
            ['value' => 2, 'label' => 'บริษัท สยามซัพพลาย แอนด์ เซอร์วิส จำกัด', 'searchText' => 'สยามซัพพลาย'],
            ['value' => 3, 'label' => 'บริษัท ไทยไอทีโซลูชัน จำกัด',              'searchText' => 'ไทยไอทีโชลูชัน'],
            ['value' => 4, 'label' => 'บริษัท พรีเมียมอุปกรณ์สำนักงาน จำกัด', 'searchText' => 'พรีเมียมอุปกรณ์สำนักงาน'],
            ['value' => 5, 'label' => 'ห้างหุ้นส่วนจำกัด เจริญพาณิชย์',              'searchText' => 'เจริญพาณิชย์ ห้างหุ้นส่วน'],
        ];
    }

    private function organizations(?int $limit = null)
    {
        $query = GlbOrganization::query()
            ->orderBy('org_name')
            ->select(['org_id', 'org_code', 'org_name']);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $organizations = $query->get();

        if ($organizations->isNotEmpty()) {
            return $organizations;
        }

        // TEMPORARY MOCK DATA - remove when organization data is available
        return collect([
            (object) ['org_id' => 0, 'org_code' => '0', 'org_name' => 'กรมส่งเสริมสหกรณ์'],
            (object) ['org_id' => 1, 'org_code' => '1', 'org_name' => 'สำนักบริหารกลาง'],
            (object) ['org_id' => 2, 'org_code' => '2', 'org_name' => 'กองคลังพัสดุ'],
            (object) ['org_id' => 3, 'org_code' => '3', 'org_name' => 'ศูนย์เทคโนโลยีสารสนเทศ'],
            (object) ['org_id' => 4, 'org_code' => '4', 'org_name' => 'กลุ่มงานบริหารทรัพยากรบุคคล'],
        ]);
    }

    private function materials()
    {
        return Material::query()
            ->orderBy('mat_code')
            ->get(['id', 'mat_code', 'mat_name', 'unit']);
    }

    /** วิธีการจัดซื้อจัดจ้าง from CG_REF_CODES (PROCUREMENT_METHOD); the code is what MAT_PRO_METHOD stores. */
    private function methodOptions(): array
    {
        try {
            return \App\Models\CgRefCode::options('PROCUREMENT_METHOD');
        } catch (\Throwable $e) {
            Log::error('MAT-002 procurement methods failed to load', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function vatOptions(): array
    {
        return [
            1 => 'รวม VAT',
            2 => 'ไม่รวม VAT',
            3 => 'ไม่มีภาษี',
        ];
    }

    public function vatRateOptions(): array
    {
        return [7, 10];
    }
}
