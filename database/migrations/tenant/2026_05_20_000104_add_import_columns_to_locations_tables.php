<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            if (!Schema::hasColumn('countries', 'legacy_id')) {
                $table->unsignedInteger('legacy_id')->nullable()->after('id');
                $table->unique('legacy_id');
            }

            if (!Schema::hasColumn('countries', 'dial_code')) {
                $table->string('dial_code', 20)->nullable()->after('name');
                $table->index('dial_code');
            }
        });

        Schema::table('regions', function (Blueprint $table) {
            if (!Schema::hasColumn('regions', 'legacy_id')) {
                $table->unsignedInteger('legacy_id')->nullable()->after('id');
                $table->unique('legacy_id');
            }

            if (!Schema::hasColumn('regions', 'post_code')) {
                $table->unsignedInteger('post_code')->nullable()->after('name');
                $table->index('post_code');
            }
        });

        Schema::table('districts', function (Blueprint $table) {
            if (!Schema::hasColumn('districts', 'legacy_id')) {
                $table->unsignedInteger('legacy_id')->nullable()->after('id');
                $table->unique('legacy_id');
            }

            if (!Schema::hasColumn('districts', 'post_code')) {
                $table->unsignedInteger('post_code')->nullable()->after('name');
                $table->index('post_code');
            }
        });

        Schema::table('wards', function (Blueprint $table) {
            if (!Schema::hasColumn('wards', 'legacy_id')) {
                $table->unsignedInteger('legacy_id')->nullable()->after('id');
                $table->unique('legacy_id');
            }

            if (!Schema::hasColumn('wards', 'post_code')) {
                $table->unsignedInteger('post_code')->nullable()->after('name');
                $table->index('post_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wards', function (Blueprint $table) {
            if (Schema::hasColumn('wards', 'post_code')) {
                $table->dropIndex(['post_code']);
                $table->dropColumn('post_code');
            }

            if (Schema::hasColumn('wards', 'legacy_id')) {
                $table->dropUnique(['legacy_id']);
                $table->dropColumn('legacy_id');
            }
        });

        Schema::table('districts', function (Blueprint $table) {
            if (Schema::hasColumn('districts', 'post_code')) {
                $table->dropIndex(['post_code']);
                $table->dropColumn('post_code');
            }

            if (Schema::hasColumn('districts', 'legacy_id')) {
                $table->dropUnique(['legacy_id']);
                $table->dropColumn('legacy_id');
            }
        });

        Schema::table('regions', function (Blueprint $table) {
            if (Schema::hasColumn('regions', 'post_code')) {
                $table->dropIndex(['post_code']);
                $table->dropColumn('post_code');
            }

            if (Schema::hasColumn('regions', 'legacy_id')) {
                $table->dropUnique(['legacy_id']);
                $table->dropColumn('legacy_id');
            }
        });

        Schema::table('countries', function (Blueprint $table) {
            if (Schema::hasColumn('countries', 'dial_code')) {
                $table->dropIndex(['dial_code']);
                $table->dropColumn('dial_code');
            }

            if (Schema::hasColumn('countries', 'legacy_id')) {
                $table->dropUnique(['legacy_id']);
                $table->dropColumn('legacy_id');
            }
        });
    }
};
