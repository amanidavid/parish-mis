<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->table('users', function (Blueprint $table) {
            $table->dropUnique('users_phone_unique');
            $table->dropUnique('users_email_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('users', function (Blueprint $table) {
            $table->unique('phone');
            $table->unique('email');
        });
    }
};
