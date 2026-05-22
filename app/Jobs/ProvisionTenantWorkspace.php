<?php

namespace App\Jobs;

use App\Services\V1\TenantProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class ProvisionTenantWorkspace implements ShouldQueue, NotTenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public function __construct(
        public int $tenantId,
        public int $ownerId,
        public ?string $planUuid = null,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(TenantProvisioningService $tenantProvisioningService): void
    {
        $tenantProvisioningService->provision(
            $this->tenantId,
            $this->ownerId,
            $this->planUuid,
        );
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);

        Log::critical('Provision tenant workspace job failed.', [
            'tenant_id' => $this->tenantId,
            'owner_id' => $this->ownerId,
            'plan_uuid' => $this->planUuid,
            'queue' => $this->queue,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        app(TenantProvisioningService::class)->markFailed(
            $this->tenantId,
            $exception->getMessage(),
            [
                'owner_id' => $this->ownerId,
                'plan_uuid' => $this->planUuid,
                'exception_class' => $exception::class,
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ],
        );
    }
}
