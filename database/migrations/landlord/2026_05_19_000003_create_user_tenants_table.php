<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->create('user_tenants', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->boolean('is_owner')->default(false);
            $table->unique(['user_id','tenant_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('user_tenants');
    }
};
