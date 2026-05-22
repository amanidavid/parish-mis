<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->create('subscription_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('metric'); // e.g., 'properties'
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->boolean('billed')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id','metric','period_start']);
            $table->index(['tenant_id','metric','period_end']);
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('subscription_usages');
    }
};
