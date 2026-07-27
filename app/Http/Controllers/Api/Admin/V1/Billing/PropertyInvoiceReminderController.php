<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\Billing\BulkResendPropertyInvoiceReminderRequest;
use App\Http\Requests\Api\Admin\V1\Billing\ResendPropertyInvoiceReminderRequest;
use App\Models\Landlord\PropertyInvoice;
use App\Services\V1\Billing\PropertyInvoiceReminderResendService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class PropertyInvoiceReminderController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private PropertyInvoiceReminderResendService $propertyInvoiceReminderResendService,
    ) {
    }

    /**
     * Resend one invoice reminder.
     */
    public function resend(ResendPropertyInvoiceReminderRequest $request, PropertyInvoice $propertyInvoice)
    {
        try {
            $result = $this->propertyInvoiceReminderResendService->queueInvoiceReminder(
                $propertyInvoice,
                (string) $request->validated('channel'),
                (bool) $request->validated('force', true)
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Property invoice reminder could not be resent.',
                ['invoice' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::success(
            'Property invoice reminder resend was queued successfully.',
            $result
        );
    }

    /**
     * Bulk resend invoice reminders.
     */
    public function bulkResend(BulkResendPropertyInvoiceReminderRequest $request)
    {
        try {
            $result = $this->propertyInvoiceReminderResendService->queueBulk($request->validated());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Property invoice reminder bulk resend could not be completed.',
                ['bulk_resend' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::success(
            'Property invoice reminder bulk resend was queued successfully.',
            $result
        );
    }
}
