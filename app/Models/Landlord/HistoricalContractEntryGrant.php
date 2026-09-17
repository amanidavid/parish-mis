<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use App\Models\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class HistoricalContractEntryGrant extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $connection = 'base';

    protected $table = 'historical_contract_entry_grants';

    protected $casts = [
        'historical_start_date_from' => 'date',
        'historical_start_date_to' => 'date',
        'usable_from' => 'datetime',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function workspaceProperty(): BelongsTo
    {
        return $this->belongsTo(WorkspaceProperty::class, 'workspace_property_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(BaseUser::class, 'approved_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(BaseUser::class, 'revoked_by_user_id');
    }

    public function effectiveStatus(?Carbon $at = null): string
    {
        $at ??= now();

        if ($this->status === self::STATUS_ACTIVE && $this->expires_at?->lte($at)) {
            return 'expired';
        }

        return $this->status;
    }
}
