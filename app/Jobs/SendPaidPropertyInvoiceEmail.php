<?php

namespace App\Jobs;

use App\Models\Landlord\PropertyInvoice;
use App\Services\V1\Billing\PropertyInvoiceEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class SendPaidPropertyInvoiceEmail implements ShouldQueue, NotTenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public function __construct(
        public int $invoiceId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(PropertyInvoiceEmailService $propertyInvoiceEmailService): void
    {
        $invoice = PropertyInvoice::query()
            ->with(['workspaceProperty', 'tenant', 'items', 'deliveryLogs'])
            ->find($this->invoiceId);

        if (!$invoice) {
            return;
        }

        $propertyInvoiceEmailService->sendPaidInvoice($invoice);
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);

        Log::error('Send paid property invoice email job failed.', [
            'invoice_id' => $this->invoiceId,
            'queue' => $this->queue,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);
    }
}
