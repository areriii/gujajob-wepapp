<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CG_REF_CODES: code lists shared by the system (UOM, PROCUREMENT_METHOD, VAT_TYPE, ...), one list per REF_DOMAIN.
 */
class CgRefCode extends Model
{
    protected $connection = 'oracle';

    protected $table = 'CG_REF_CODES';

    public $incrementing = false;

    protected $fillable = [
        'ref_code',
        'ref_name',
        'ref_domain',
        'created_by',
        'updated_by',
    ];

    /** @return array<int|string, string> ref_code => ref_name, in code order */
    public static function options(string $domain): array
    {
        return static::query()
            ->where('ref_domain', $domain)
            ->orderBy('ref_code')
            ->pluck('ref_name', 'ref_code')
            ->toArray();
    }
}
