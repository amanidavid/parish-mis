<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationTaskRun extends BaseModel
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $connection = 'base';

    protected $table = 'automation_task_runs';

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    public function taskSetting(): BelongsTo
    {
        return $this->belongsTo(AutomationTaskSetting::class, 'automation_task_setting_id');
    }
}
