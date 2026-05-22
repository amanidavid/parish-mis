<?php

use App\Models\Tenancy\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenants:migrate-existing {--tenant=} {--chunk=20}', function () {
    $tenantUuid = $this->option('tenant');
    $chunkSize = max((int) $this->option('chunk'), 1);

    $runMigration = function (Tenant $tenant): void {
        try {
            $tenant->makeCurrent();

            $exitCode = Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant',
                '--database' => 'mysql',
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: 'Migration failed');
            }

            $this->info(sprintf('[OK] %s | %s', $tenant->uuid, $tenant->database));
        } catch (Throwable $exception) {
            $this->error(sprintf('[FAIL] %s | %s | %s', $tenant->uuid, $tenant->database, $exception->getMessage()));
        } finally {
            Tenant::forgetCurrent();
            DB::purge('mysql');
            DB::reconnect('mysql');
        }
    };

    if ($tenantUuid) {
        $tenant = Tenant::query()
            ->where('uuid', $tenantUuid)
            ->where('provisioning_status', 'ready')
            ->firstOrFail();

        $runMigration($tenant);

        return self::SUCCESS;
    }

    Tenant::query()
        ->where('provisioning_status', 'ready')
        ->orderBy('id')
        ->chunkById($chunkSize, function ($tenants) use ($runMigration) {
            foreach ($tenants as $tenant) {
                $runMigration($tenant);
            }
        });

    return self::SUCCESS;
})->purpose('Run tenant migrations for existing ready tenant databases');

Artisan::command('tenants:seed-existing {--tenant=} {--chunk=20}', function () {
    $tenantUuid = $this->option('tenant');
    $chunkSize = max((int) $this->option('chunk'), 1);

    $runSeeder = function (Tenant $tenant): void {
        try {
            $tenant->makeCurrent();

            $exitCode = Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Tenant\\TenantSeeder',
                '--database' => 'mysql',
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: 'Seeding failed');
            }

            $this->info(sprintf('[OK] %s | %s', $tenant->uuid, $tenant->database));
        } catch (Throwable $exception) {
            $this->error(sprintf('[FAIL] %s | %s | %s', $tenant->uuid, $tenant->database, $exception->getMessage()));
        } finally {
            Tenant::forgetCurrent();
            DB::purge('mysql');
            DB::reconnect('mysql');
        }
    };

    if ($tenantUuid) {
        $tenant = Tenant::query()
            ->where('uuid', $tenantUuid)
            ->where('provisioning_status', 'ready')
            ->firstOrFail();

        $runSeeder($tenant);

        return self::SUCCESS;
    }

    Tenant::query()
        ->where('provisioning_status', 'ready')
        ->orderBy('id')
        ->chunkById($chunkSize, function ($tenants) use ($runSeeder) {
            foreach ($tenants as $tenant) {
                $runSeeder($tenant);
            }
        });

    return self::SUCCESS;
})->purpose('Run tenant seeders for existing ready tenant databases');
