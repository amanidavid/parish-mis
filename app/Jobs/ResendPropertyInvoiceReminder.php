<?php

namespace App\Jobs;

use App\Models\Landlord\PropertyInvoice;
use App\Services\V1\Billing\PropertyInvoiceReminderResendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class ResendPropertyInvoiceReminder implements ShouldQueue, NotTenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    /**
     * Create a new instance.
     */
    public function __construct(
        public int $invoiceId,
        public string $channelSelection = 'email',
        public bool $force = true,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Handle the job.
     */
    public function handle(PropertyInvoiceReminderResendService $propertyInvoiceReminderResendService): void
    {
        $invoice = PropertyInvoice::query()
            ->with(['tenant', 'workspaceProperty'])
            ->find($this->invoiceId);

        if (!$invoice) {
            return;
        }

        $propertyInvoiceReminderResendService->resendInvoiceReminder(
            $invoice,
            $this->channelSelection,
            $this->force
        );
    }

    /**
     * Handle a failed job.
     */
    public function failed(\Throwable $exception): void
    {
        report($exception);

        Log::error('Property invoice reminder resend job failed.', [
            'invoice_id' => $this->invoiceId,
            'channel_selection' => $this->channelSelection,
            'force' => $this->force,
            'queue' => $this->queue,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);
    }
}
