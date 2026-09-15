<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    protected $connection = 'oracle';
    protected $table      = 'ASSET_CATEGORY';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'int';

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'id',
        'asscat_code',
        'asscat_group',
        'asscat_type',
        'asscat_name',
        'asscat_unit',
        'depreciation_rate',
        'created_by',
        'updated_by',
    ];

    /**
     * Distinct หมวดครุภัณฑ์ names (asscat_group). asscat_name is the asset name (ชื่อครุภัณฑ์),
     * not the category. There is no separate group table or id, so the trimmed name identifies the group.
     *
     * @return list<string>
     */
    public static function groupNames(): array
    {
        return static::query()
            ->toBase()
            ->whereRaw('LENGTH(TRIM(asscat_group)) > 0')
            ->selectRaw('DISTINCT TRIM(asscat_group) AS group_name')
            ->orderByRaw('group_name')
            ->pluck('group_name')
            ->map(fn ($group) => (string) $group)
            ->values()
            ->all();
    }

    /**
     * หมวดครุภัณฑ์ options for <x-searchable-select>.
     *
     * @return list<array{value: string, label: string, searchText: string}>
     */
    public static function groupOptions(): array
    {
        return array_map(
            static fn (string $group) => ['value' => $group, 'label' => $group, 'searchText' => $group],
            static::groupNames(),
        );
    }

    public static function groupExists(string $group): bool
    {
        return static::query()->whereRaw('TRIM(asscat_group) = ?', [trim($group)])->exists();
    }
}
