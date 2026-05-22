<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use App\Models\Landlord\BaseUser;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\Access\Authorizable as AuthorizableTrait;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends BaseModel implements Authenticatable, Authorizable
{
    use AuthorizableTrait;
    use AuthenticatableTrait;
    use HasRoles;
    use Notifiable;

    protected $table = 'users';

    protected string $guard_name = 'api';

    protected $hidden = [
        'base_user_id',
    ];

    public function staffPropertyAssignments(): HasMany
    {
        return $this->hasMany(StaffPropertyAssignment::class);
    }

    public function baseUser(): BelongsTo
    {
        return $this->belongsTo(BaseUser::class, 'base_user_id');
    }
}
