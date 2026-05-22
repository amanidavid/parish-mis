<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->create('users', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('username')->unique();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->enum('status', ['active','suspended'])->default('active')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('users');
    }
};
