<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->table('workspace_properties', function (Blueprint $table) {
            if (!Schema::connection('base')->hasColumn('workspace_properties', 'tenant_property_sequence')) {
                $table->unsignedInteger('tenant_property_sequence')->nullable()->after('tenant_id');
            }
        });

        DB::connection('base')->statement(<<<'SQL'
            WITH ranked AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY tenant_id
                        ORDER BY
                            COALESCE(property_created_at, created_at) ASC,
                            created_at ASC,
                            id ASC
                    ) AS sequence_number
                FROM workspace_properties
            )
            UPDATE workspace_properties
            SET tenant_property_sequence = ranked.sequence_number
            FROM ranked
            WHERE ranked.id = workspace_properties.id
              AND workspace_properties.tenant_property_sequence IS NULL
        SQL);

        Schema::connection('base')->table('workspace_properties', function (Blueprint $table) {
            $table->unique(['tenant_id', 'tenant_property_sequence'], 'workspace_properties_tenant_sequence_unique');
            $table->index(['tenant_id', 'tenant_property_sequence'], 'workspace_properties_tenant_sequence_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('workspace_properties', function (Blueprint $table) {
            $table->dropUnique('workspace_properties_tenant_sequence_unique');
            $table->dropIndex('workspace_properties_tenant_sequence_idx');
            $table->dropColumn('tenant_property_sequence');
        });
    }
};
