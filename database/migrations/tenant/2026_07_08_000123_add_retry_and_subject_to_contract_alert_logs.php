<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contract_alert_logs')) {
            return;
        }

        Schema::table('contract_alert_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('contract_alert_logs', 'subject')) {
                $table->string('subject')->nullable()->after('recipient_address');
            }

            if (!Schema::hasColumn('contract_alert_logs', 'attempts_count')) {
                $table->unsignedSmallInteger('attempts_count')->default(0)->after('status');
            }

            if (!Schema::hasColumn('contract_alert_logs', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('contract_alert_logs')) {
            return;
        }

        Schema::table('contract_alert_logs', function (Blueprint $table) {
            if (Schema::hasColumn('contract_alert_logs', 'last_attempt_at')) {
                $table->dropColumn('last_attempt_at');
            }

            if (Schema::hasColumn('contract_alert_logs', 'attempts_count')) {
                $table->dropColumn('attempts_count');
            }

            if (Schema::hasColumn('contract_alert_logs', 'subject')) {
                $table->dropColumn('subject');
            }
        });
    }
};
