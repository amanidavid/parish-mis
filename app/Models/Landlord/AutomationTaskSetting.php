<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationTaskSetting extends BaseModel
{
    public const TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC = 'property_subscription_expiry_sync';
    public const TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC = 'customer_contract_expiry_sync';
    public const TASK_CUSTOMER_CONTRACT_ALERTS = 'contract_alerts';
    public const TASK_PROPERTY_SUBSCRIPTION_ALERTS = 'property_subscription_alerts';

    public const MODE_INTERVAL = 'interval';
    public const MODE_DAILY = 'daily';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $connection = 'base';

    protected $table = 'automation_task_settings';

    protected $casts = [
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'meta' => 'array',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationTaskRun::class, 'automation_task_setting_id');
    }
}
