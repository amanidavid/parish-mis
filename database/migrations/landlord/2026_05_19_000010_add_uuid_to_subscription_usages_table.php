<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::connection('base')->hasColumn('subscription_usages', 'uuid')) {
            Schema::connection('base')->table('subscription_usages', function (Blueprint $table) {
                $table->char('uuid', 36)->nullable()->after('id');
            });
        }

        DB::connection('base')
            ->table('subscription_usages')
            ->whereNull('uuid')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::connection('base')
                        ->table('subscription_usages')
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        DB::connection('base')->statement(
            'ALTER TABLE `subscription_usages` MODIFY `uuid` CHAR(36) NOT NULL'
        );

        Schema::connection('base')->table('subscription_usages', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        if (!Schema::connection('base')->hasColumn('subscription_usages', 'uuid')) {
            return;
        }

        Schema::connection('base')->table('subscription_usages', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
