<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->create('plans', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('name')->unique();
            $table->bigInteger('price_cents');
            $table->bigInteger('price_per_property_cents')->default(0);
            $table->integer('properties_included')->default(1);
            $table->integer('trial_days')->default(14);
            $table->enum('billing_interval', ['monthly','annual']);
            $table->json('features');
            $table->enum('status', ['active','inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('plans');
    }
};
