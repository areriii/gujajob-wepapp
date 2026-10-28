<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    protected $connection   = 'oracle';
    protected $table        = 'ASSET';
    protected $primaryKey   = 'id';
    public    $incrementing = false;
    protected $keyType      = 'int';

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    protected $casts = [
        'ass_contact_date' => 'date',
        'inspect_date'     => 'date',
    ];

    protected $fillable = [
        'id',
        'asscat_id',
        'ass_code',
        'ass_desc',
        'ass_model',
        'ass_serail',
        'ass_price',
        'org_id',
        'sub_org_id',
        'ass_contact_no',
        'ass_contact_date',
        'dealer_id',
        'inspect_date',
        'warranty',
        'ass_lifetime',
        'remarks',
        'ass_trans_date',
        'ass_trans_person',
        'ass_trans_remark',
        'remain_price',
        'ass_status',
        'replacement_status',
        'created_by',
        'updated_by',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asscat_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(GlbOrganization::class, 'org_id', 'org_id');
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class, 'dealer_id');
    }

    public function subOrganization(): BelongsTo
    {
        return $this->belongsTo(GlbOrganization::class, 'sub_org_id', 'org_id');
    }
}
