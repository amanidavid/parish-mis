<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->create('system_admins', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->boolean('super')->default(true);
            $table->json('scopes')->nullable();
            $table->timestamps();
            $table->unique(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('system_admins');
    }
};
