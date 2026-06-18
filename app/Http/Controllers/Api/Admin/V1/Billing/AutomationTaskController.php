<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\Billing\AutomationTaskRunIndexRequest;
use App\Http\Requests\Api\Admin\V1\Billing\UpdateAutomationTaskSettingRequest;
use App\Http\Resources\Admin\V1\Billing\AutomationTaskRunResource;
use App\Http\Resources\Admin\V1\Billing\AutomationTaskSettingResource;
use App\Models\Landlord\AutomationTaskSetting;
use App\Services\V1\Billing\PropertySubscriptionAutomationService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class AutomationTaskController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private PropertySubscriptionAutomationService $propertySubscriptionAutomationService,
    ) {
    }

    /**
     * Handle the index request.
     */
    public function index()
    {
        return ApiResponse::resource(
            AutomationTaskSettingResource::collection($this->propertySubscriptionAutomationService->listTaskSettings()),
            'Automation tasks retrieved successfully.'
        );
    }

    /**
     * Handle runs.
     */
    public function runs(AutomationTaskRunIndexRequest $request, AutomationTaskSetting $automationTaskSetting)
    {
        return ApiResponse::resource(
            AutomationTaskRunResource::collection(
                $this->propertySubscriptionAutomationService->listTaskRuns(
                    $automationTaskSetting,
                    (int) ($request->validated()['per_page'] ?? 15)
                )
            ),
            'Automation task runs retrieved successfully.'
        );
    }

    /**
     * Handle the update request.
     */
    public function update(UpdateAutomationTaskSettingRequest $request, AutomationTaskSetting $automationTaskSetting)
    {
        try {
            $taskSetting = $this->propertySubscriptionAutomationService->updateTaskSetting(
                $automationTaskSetting,
                $request->validated(),
                request()->user()
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Automation task could not be updated.',
                ['automation_task' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            new AutomationTaskSettingResource($taskSetting),
            'Automation task updated successfully.'
        );
    }

    /**
     * Handle the run now request.
     */
    public function runNow(AutomationTaskSetting $automationTaskSetting)
    {
        try {
            $run = $this->propertySubscriptionAutomationService->runNow($automationTaskSetting);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Automation task could not be run.',
                ['automation_task' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            new AutomationTaskRunResource($run),
            'Automation task executed successfully.'
        );
    }
}
