<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('padres', function (Blueprint $table) {
            $table->string('fcm_token')->nullable()->after('fecha_retiro');
            $table->string('fcm_platform')->nullable()->after('fcm_token');
        });
    }

    public function down(): void
    {
        Schema::table('padres', function (Blueprint $table) {
            $table->dropColumn(['fcm_token', 'fcm_platform']);
        });
    }
};
