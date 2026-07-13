<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->table('users', function (Blueprint $table) {
            $table->string('google_subject')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('users', function (Blueprint $table) {
            $table->dropUnique('users_google_subject_unique');
            $table->dropColumn('google_subject');
        });
    }
};
