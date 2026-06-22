<?php

namespace App\Http\Resources\Admin\V1\Billing;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class AutomationTaskSettingResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $displayTimezone = $this->timezone ?: config('app.timezone');

        return [
            'uuid' => $this->uuid,
            'task_key' => $this->task_key,
            'name' => $this->name,
            'description' => $this->description,
            'enabled' => (bool) $this->enabled,
            'schedule_mode' => $this->schedule_mode,
            'interval_minutes' => $this->interval_minutes !== null ? (int) $this->interval_minutes : null,
            'run_at_time' => $this->run_at_time,
            'timezone' => $this->timezone,
            'last_run_at' => $this->formatTimestamp($this->last_run_at, $displayTimezone),
            'next_run_at' => $this->formatTimestamp($this->next_run_at, $displayTimezone),
            'last_status' => $this->last_status,
            'last_message' => $this->last_message,
            'updated_by_user_id' => $this->updated_by_user_id,
            'meta' => $this->meta,
            'created_at' => $this->formatTimestamp($this->created_at, $displayTimezone),
            'updated_at' => $this->formatTimestamp($this->updated_at, $displayTimezone),
        ];
    }
}
