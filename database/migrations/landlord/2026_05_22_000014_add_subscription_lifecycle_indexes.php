<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->table('subscriptions', function (Blueprint $table) {
            $table->index(['status', 'trial_ends_at'], 'subscriptions_status_trial_end_idx');
            $table->index(['status', 'ends_at'], 'subscriptions_status_end_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_status_trial_end_idx');
            $table->dropIndex('subscriptions_status_end_idx');
        });
    }
};
