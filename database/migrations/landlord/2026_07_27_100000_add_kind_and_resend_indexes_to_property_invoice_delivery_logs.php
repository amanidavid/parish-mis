<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->table('property_invoice_delivery_logs', function (Blueprint $table) {
            if (!Schema::connection('base')->hasColumn('property_invoice_delivery_logs', 'kind')) {
                $table->string('kind', 60)->nullable()->after('status');
            }
        });

        DB::connection('base')->table('property_invoice_delivery_logs')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $kind = data_get((array) json_decode((string) ($row->meta ?? '{}'), true), 'kind');

                    if (!is_string($kind) || trim($kind) === '') {
                        continue;
                    }

                    DB::connection('base')->table('property_invoice_delivery_logs')
                        ->where('id', $row->id)
                        ->update(['kind' => trim($kind)]);
                }
            });

        Schema::connection('base')->table('property_invoice_delivery_logs', function (Blueprint $table) {
            $table->index(
                ['kind', 'last_attempt_at'],
                'property_invoice_delivery_kind_attempted_idx'
            );
            $table->index(
                ['kind', 'channel', 'status', 'last_attempt_at'],
                'property_invoice_delivery_kind_channel_status_attempted_idx'
            );
            $table->index(
                ['kind', 'channel', 'recipient_address'],
                'property_invoice_delivery_kind_channel_recipient_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('property_invoice_delivery_logs', function (Blueprint $table) {
            $table->dropIndex('property_invoice_delivery_kind_channel_recipient_idx');
            $table->dropIndex('property_invoice_delivery_kind_channel_status_attempted_idx');
            $table->dropIndex('property_invoice_delivery_kind_attempted_idx');

            if (Schema::connection('base')->hasColumn('property_invoice_delivery_logs', 'kind')) {
                $table->dropColumn('kind');
            }
        });
    }
};
