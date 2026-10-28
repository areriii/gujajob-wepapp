<?php

namespace App\Models;

use App\Models\Dealer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialProcurement extends Model
{
    protected $connection = 'oracle';

    protected $table = 'MATERIAL_PROCUREMENT';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'id',
        'mat_pro_code',
        'dealer_id',
        'org_id',
        'mat_pro_quotation',
        'mat_pro_date',
        'mat_pro_method',
        'mat_pro_contact_no',
        'mat_pro_contact_date',
        'vat_type',
        'vat_rate',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'mat_pro_date' => 'date',
        'mat_pro_contact_date' => 'date',
        'vat_rate' => 'float',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(GlbOrganization::class, 'org_id', 'org_id');
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class, 'dealer_id', 'id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(MaterialProcurementList::class, 'mat_pro_id', 'id');
    }

    public function getStatusLabelAttribute(): string
    {
        return ($this->updated_at ?? $this->created_at) ? 'บันทึกแล้ว' : 'ร่าง';
    }
}
