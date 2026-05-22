<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
       if(!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('base_user_id')->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->enum('status', ['active','suspended'])->default('active')->index();
            $table->timestamps();

            $table->index(['base_user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
