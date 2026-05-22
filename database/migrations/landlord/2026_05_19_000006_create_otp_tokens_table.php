codex<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->create('otp_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('purpose', ['login','password_reset']);
            $table->string('code_hash');
            $table->enum('channel', ['log','sms','email'])->default('log');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('consumed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id','purpose','expires_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('otp_tokens');
    }
};
