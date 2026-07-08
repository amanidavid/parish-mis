<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('base')->hasTable('property_subscription_alert_logs')) {
            return;
        }

        Schema::connection('base')->table('property_subscription_alert_logs', function (Blueprint $table) {
            if (!Schema::connection('base')->hasColumn('property_subscription_alert_logs', 'subject')) {
                $table->string('subject')->nullable()->after('recipient_address');
            }

            if (!Schema::connection('base')->hasColumn('property_subscription_alert_logs', 'attempts_count')) {
                $table->unsignedSmallInteger('attempts_count')->default(0)->after('status');
            }

            if (!Schema::connection('base')->hasColumn('property_subscription_alert_logs', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('base')->hasTable('property_subscription_alert_logs')) {
            return;
        }

        Schema::connection('base')->table('property_subscription_alert_logs', function (Blueprint $table) {
            if (Schema::connection('base')->hasColumn('property_subscription_alert_logs', 'last_attempt_at')) {
                $table->dropColumn('last_attempt_at');
            }

            if (Schema::connection('base')->hasColumn('property_subscription_alert_logs', 'attempts_count')) {
                $table->dropColumn('attempts_count');
            }

            if (Schema::connection('base')->hasColumn('property_subscription_alert_logs', 'subject')) {
                $table->dropColumn('subject');
            }
        });
    }
};
