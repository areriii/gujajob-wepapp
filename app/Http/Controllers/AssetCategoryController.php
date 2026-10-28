<?php

namespace App\Http\Controllers;

use App\Models\AssetCategory;
use App\Models\UnitOfMaterial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AssetCategoryController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $searchBy  = $request->string('search_by', 'all')->value();
        $keyword   = trim($request->string('keyword')->value());
        $sort      = $request->string('sort', '')->value();
        $direction = strtolower($request->string('direction', 'asc')->value()) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = [
            'code'             => 'asscat_code',
            'name'             => 'asscat_name',
            'type'             => 'asscat_type',
            'group'            => 'asscat_group',
            'unit'             => 'asscat_unit',
            'depreciation_rate'=> 'depreciation_rate',
        ];

        $query = AssetCategory::query();

        if ($keyword !== '') {
            $escaped = $this->escapeLike($keyword);
            $upper   = mb_strtoupper($escaped);
            if ($searchBy === 'code') {
                $query->whereRaw('UPPER(asscat_code) LIKE UPPER(?)', ['%' . $upper . '%']);
            } elseif ($searchBy === 'type') {
                $query->whereRaw('UPPER(asscat_type) LIKE UPPER(?)', ['%' . $upper . '%']);
            } elseif ($searchBy === 'group') {
                $query->whereRaw('UPPER(asscat_group) LIKE UPPER(?)', ['%' . $upper . '%']);
            } elseif ($searchBy === 'name') {
                $query->whereRaw('UPPER(asscat_name) LIKE UPPER(?)', ['%' . $upper . '%']);
            } elseif ($searchBy === 'unit') {
                $query->whereRaw('UPPER(asscat_unit) LIKE UPPER(?)', ['%' . $upper . '%']);
            } else {
                // 'all': search across code, name, type, group, unit
                $query->where(function ($q) use ($upper): void {
                    $q->whereRaw('UPPER(asscat_code)  LIKE UPPER(?)', ['%' . $upper . '%'])
                      ->orWhereRaw('UPPER(asscat_name)  LIKE UPPER(?)', ['%' . $upper . '%'])
                      ->orWhereRaw('UPPER(asscat_type)  LIKE UPPER(?)', ['%' . $upper . '%'])
                      ->orWhereRaw('UPPER(asscat_group) LIKE UPPER(?)', ['%' . $upper . '%'])
                      ->orWhereRaw('UPPER(asscat_unit)  LIKE UPPER(?)', ['%' . $upper . '%']);
                });
            }
        }

        if ($sort === 'depreciation_rate') {
            $query->orderByRaw("DEPRECIATION_RATE {$direction} NULLS LAST");
        } else {
            $sortColumn = array_key_exists($sort, $allowedSorts) ? $allowedSorts[$sort] : 'asscat_code';
            $query->orderBy($sortColumn, $direction);
        }

        $categories = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('asset.ASS-001-manage-asset-categories.index', [
            'pageTitle'  => 'จัดการประเภทครุภัณฑ์',
            'categories' => $categories,
            'searchBy'   => $searchBy,
            'keyword'    => $keyword,
            'sort'       => $sort,
            'direction'  => $direction,
        ]);
    }

    public function create(): View
    {
        return view('asset.ASS-001-manage-asset-categories.create', [
            'pageTitle' => 'จัดการประเภทครุภัณฑ์',
            'units'     => UnitOfMaterial::allNames(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'asscat_code'       => [
                'required', 'string', 'max:15',
                Rule::unique('oracle.ASSET_CATEGORY', 'asscat_code'),
            ],
            'asscat_name'       => ['required', 'string', 'max:200'],
            'asscat_type'       => ['required', 'string', 'max:100'],
            'asscat_group'      => ['required', 'string', 'max:100'],
            'asscat_unit'       => ['nullable', 'string', 'max:30', Rule::in(UnitOfMaterial::allNames()->push('')->toArray())],
            'depreciation_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'asscat_code.required' => 'กรุณากรอกรหัสประเภทครุภัณฑ์',
            'asscat_code.max'      => 'รหัสประเภทครุภัณฑ์ต้องไม่เกิน 15 ตัวอักษร',
            'asscat_code.unique'   => 'รหัสประเภทครุภัณฑ์นี้มีอยู่ในระบบแล้ว',
            'asscat_name.required' => 'กรุณากรอกชื่อครุภัณฑ์',
            'asscat_type.required' => 'กรุณากรอกชนิดครุภัณฑ์',
            'asscat_group.required'=> 'กรุณากรอกหมวดครุภัณฑ์',
        ]);

        try {
            DB::connection('oracle')->transaction(function () use ($validated): void {
                $nextId = (int) DB::connection('oracle')
                    ->selectOne('SELECT ASSET_CATEGORY_SEQ.NEXTVAL AS next_id FROM DUAL')
                    ->next_id;

                AssetCategory::create([
                    'id'                => $nextId,
                    'asscat_code'       => trim($validated['asscat_code']),
                    'asscat_name'       => trim($validated['asscat_name']),
                    'asscat_type'       => trim($validated['asscat_type']),
                    'asscat_group'      => trim($validated['asscat_group']),
                    'asscat_unit'       => isset($validated['asscat_unit']) ? trim($validated['asscat_unit']) : null,
                    'depreciation_rate' => isset($validated['depreciation_rate']) ? (float) $validated['depreciation_rate'] : null,
                    'created_by'        => 1,
                    'updated_by'        => 1,
                ]);
            });
        } catch (Throwable $e) {
            Log::error('ASS-001 store failed', ['error' => $e->getMessage()]);
            return back()->withInput()->withErrors(['general' => 'บันทึกข้อมูลไม่สำเร็จ: ' . $e->getMessage()]);
        }

        return redirect()->route('asset.categories.index');
    }

    public function show(int $id): View
    {
        $category = AssetCategory::query()->findOrFail($id);

        return view('asset.ASS-001-manage-asset-categories.show', [
            'pageTitle' => 'จัดการประเภทครุภัณฑ์',
            'category'  => $category,
        ]);
    }

    public function edit(int $id): View
    {
        $category = AssetCategory::query()->findOrFail($id);

        return view('asset.ASS-001-manage-asset-categories.edit', [
            'pageTitle' => 'จัดการประเภทครุภัณฑ์',
            'category'  => $category,
            'units'     => UnitOfMaterial::allNames(),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $category = AssetCategory::query()->findOrFail($id);

        $validated = $request->validate([
            'asscat_code'       => [
                'required', 'string', 'max:15',
                Rule::unique('oracle.ASSET_CATEGORY', 'asscat_code')->ignore($id),
            ],
            'asscat_name'       => ['required', 'string', 'max:200'],
            'asscat_type'       => ['required', 'string', 'max:100'],
            'asscat_group'      => ['required', 'string', 'max:100'],
            'asscat_unit'       => ['nullable', 'string', 'max:30', Rule::in(UnitOfMaterial::allNames()->push('')->toArray())],
            'depreciation_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'asscat_code.required' => 'กรุณากรอกรหัสประเภทครุภัณฑ์',
            'asscat_code.unique'   => 'รหัสประเภทครุภัณฑ์นี้มีอยู่ในระบบแล้ว',
            'asscat_name.required' => 'กรุณากรอกชื่อครุภัณฑ์',
            'asscat_type.required' => 'กรุณากรอกชนิดครุภัณฑ์',
            'asscat_group.required'=> 'กรุณากรอกหมวดครุภัณฑ์',
        ]);

        try {
            DB::connection('oracle')->transaction(function () use ($category, $validated): void {
                $category->update([
                    'asscat_code'       => trim($validated['asscat_code']),
                    'asscat_name'       => trim($validated['asscat_name']),
                    'asscat_type'       => trim($validated['asscat_type']),
                    'asscat_group'      => trim($validated['asscat_group']),
                    'asscat_unit'       => isset($validated['asscat_unit']) ? trim($validated['asscat_unit']) : null,
                    'depreciation_rate' => isset($validated['depreciation_rate']) ? (float) $validated['depreciation_rate'] : null,
                    'updated_by'        => 1,
                ]);
            });
        } catch (Throwable $e) {
            Log::error('ASS-001 update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return back()->withInput()->withErrors(['general' => 'แก้ไขข้อมูลไม่สำเร็จ: ' . $e->getMessage()]);
        }

        return redirect()->route('asset.categories.index');
    }

    public function destroy(int $id): RedirectResponse
    {
        $category = AssetCategory::query()->findOrFail($id);

        // Check if any ASSET references this category
        $assetCount = DB::connection('oracle')
            ->table('ASSET')
            ->where('asscat_id', $id)
            ->count();

        if ($assetCount > 0) {
            return redirect()->route('asset.categories.index')
                ->with('asset_category_error', "ไม่สามารถลบได้ เนื่องจากมีครุภัณฑ์ {$assetCount} รายการอ้างอิงประเภทนี้");
        }

        try {
            $category->delete();
        } catch (Throwable $e) {
            Log::error('ASS-001 destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return redirect()->route('asset.categories.index')
                ->with('asset_category_error', 'ลบข้อมูลไม่สำเร็จ: ' . $e->getMessage());
        }

        return redirect()->route('asset.categories.index');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }
}
