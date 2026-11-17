<?php

namespace App\Http\Controllers;

use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class MaterialController extends Controller
{
    public function index(Request $request): View
    {
        $searchBy = $request->string('search_by', 'name')->value();
        $keyword = trim($request->string('keyword')->value());

        $query = Material::query()->orderBy('id');

        if ($keyword !== '') {
            if ($searchBy === 'code') {
                $query->whereRaw('UPPER(mat_code) LIKE ?', ['%' . mb_strtoupper($keyword) . '%']);
            } else {
                $query->whereRaw('UPPER(mat_name) LIKE ?', ['%' . mb_strtoupper($keyword) . '%']);
            }
        }

        return view('material.MAT-001-manage-material-items.index', [
            'pageTitle' => 'จัดการรายการวัสดุ',
            'materials' => $query->paginate(10)->withQueryString(),
            'searchBy' => $searchBy,
            'keyword' => $keyword,
        ]);
    }

    public function create(): View
    {
        return view('material.MAT-001-manage-material-items.create', [
            'pageTitle' => 'จัดการรายการวัสดุ',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Normalize material code to uppercase before validation
        $request->merge([
            'MAT_CODE' => strtoupper(trim((string) $request->input('MAT_CODE', ''))),
        ]);

        $validated = $request->validate([
            'MAT_CODE' => [
                'required',
                'string',
                'max:10',
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('oracle.MATERIALS', 'mat_code'),
            ],
            'MAT_NAME' => ['required', 'string', 'max:200'],
            'MAT_DESC' => ['nullable', 'string', 'max:1000'],
            'UNIT'     => ['required', 'string', 'max:20'],
            'MIN_AMT'  => ['nullable', 'integer', 'min:0'],
            'MAX_AMT'  => ['nullable', 'integer', 'min:0', 'gte:MIN_AMT'],
        ], [
            'MAT_CODE.required' => 'กรุณากรอกรหัสวัสดุ',
            'MAT_CODE.max'      => 'รหัสวัสดุต้องไม่เกิน 10 ตัวอักษร',
            'MAT_CODE.regex'    => 'รหัสวัสดุใช้ได้เฉพาะตัวอักษรภาษาอังกฤษ ตัวเลข และเครื่องหมาย -',
            'MAT_CODE.unique'   => 'รหัสวัสดุนี้มีอยู่ในระบบแล้ว',
        ]);

        try {
            DB::connection('oracle')->transaction(function () use ($validated): void {
                Material::create([
                    'id'         => $this->nextMaterialId(),
                    'mat_code'   => $validated['MAT_CODE'],
                    'mat_name'   => $validated['MAT_NAME'],
                    'mat_desc'   => $validated['MAT_DESC'] ?? null,
                    'unit'       => $validated['UNIT'],
                    'min_amt'    => $validated['MIN_AMT'] ?? 0,
                    'max_amt'    => $validated['MAX_AMT'] ?? 0,
                    'created_by' => 1,
                    'updated_by' => 1,
                ]);
            });

            return redirect()
                ->route('material.items.index')
                ->with('success', 'บันทึกรายการวัสดุเรียบร้อยแล้ว');
        } catch (Throwable $exception) {
            Log::error('MAT-001 store failed', ['error' => $exception->getMessage()]);

            return back()
                ->withInput()
                ->with('error', 'ไม่สามารถบันทึกข้อมูลวัสดุได้ กรุณาตรวจสอบการเชื่อมต่อ Oracle และลองใหม่อีกครั้ง');
        }
    }

    public function show(string $code): View
    {
        return view('material.MAT-001-manage-material-items.show', [
            'pageTitle' => 'จัดการรายการวัสดุ',
            'material' => $this->findByCode($code),
        ]);
    }

    public function edit(string $code): View
    {
        return view('material.MAT-001-manage-material-items.edit', [
            'pageTitle' => 'จัดการรายการวัสดุ',
            'material' => $this->findByCode($code),
        ]);
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        $validated = $request->validate([
            'MAT_NAME' => ['required', 'string', 'max:200'],
            'MAT_DESC' => ['nullable', 'string', 'max:1000'],
            'UNIT' => ['required', 'string', 'max:20'],
            'MIN_AMT' => ['nullable', 'integer', 'min:0'],
            'MAX_AMT' => ['nullable', 'integer', 'min:0', 'gte:MIN_AMT'],
        ]);

        try {
            DB::connection('oracle')->transaction(function () use ($validated, $code): void {
                $material = $this->findByCode($code);

                $material->update([
                    'mat_name' => $validated['MAT_NAME'],
                    'mat_desc' => $validated['MAT_DESC'] ?? null,
                    'unit' => $validated['UNIT'],
                    'min_amt' => $validated['MIN_AMT'] ?? 0,
                    'max_amt' => $validated['MAX_AMT'] ?? 0,
                    'updated_by' => 1,
                ]);
            });

            // กลับไปหน้ารายการเหมือนตอนเพิ่มวัสดุใหม่ ผู้ใช้มาจากหน้ารายการ
            // การเด้งไปหน้ารายละเอียดทำให้ต้องกดย้อนกลับอีกทีเพื่อแก้ตัวถัดไป
            return redirect()
                ->route('material.items.index')
                ->with('success', 'แก้ไขรายการวัสดุเรียบร้อยแล้ว');
        } catch (Throwable $exception) {
            Log::error('MAT-001 update failed', ['code' => $code, 'error' => $exception->getMessage()]);

            return back()
                ->withInput()
                ->with('error', 'ไม่สามารถแก้ไขข้อมูลวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    public function destroy(string $code): RedirectResponse
    {
        try {
            DB::connection('oracle')->transaction(function () use ($code): void {
                $material = $this->findByCode($code);
                $material->delete();
            });

            return redirect()
                ->route('material.items.index')
                ->with('success', 'ลบรายการวัสดุเรียบร้อยแล้ว');
        } catch (Throwable $exception) {
            Log::error('MAT-001 delete failed', ['code' => $code, 'error' => $exception->getMessage()]);

            return redirect()
                ->route('material.items.show', $code)
                ->with('error', 'ไม่สามารถลบรายการวัสดุได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    private function findByCode(string $code): Material
    {
        return Material::query()->where('mat_code', $code)->firstOrFail();
    }

    private function nextMaterialId(): int
    {
        return (int) Material::query()->max('id') + 1;
    }

    public function searchApi(Request $request): JsonResponse
    {
        $allowedTypes = ['code', 'name'];
        $type         = $request->string('type', 'code')->value();

        if (! in_array($type, $allowedTypes, true)) {
            return response()->json(['data' => []], 422);
        }

        $raw     = trim($request->string('q')->value());
        $keyword = mb_substr($raw, 0, 100);   // cap at 100 chars

        if ($keyword === '') {
            return response()->json(['data' => []]);
        }

        $limit   = min((int) $request->input('limit', 20), 30);
        $escaped = $this->escapeLike($keyword);

        $query = Material::query()->orderBy('mat_code');

        if ($type === 'name') {
            $query->whereRaw('UPPER(mat_name) LIKE UPPER(?)', ['%' . $escaped . '%']);
        } else {
            $query->whereRaw('UPPER(mat_code) LIKE UPPER(?)', ['%' . $escaped . '%']);
        }

        $data = $query->limit($limit)->get(['id', 'mat_code', 'mat_name', 'unit'])
            ->map(fn ($m) => [
                'id'   => (int) $m->id,
                'code' => $m->mat_code,
                'name' => $m->mat_name,
                'unit' => $m->unit ?? '',
            ])->values();

        return response()->json(['data' => $data]);
    }

    /** Escape LIKE wildcard characters to prevent unintended pattern matching. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
