<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('padres', function (Blueprint $table) {
            $table->boolean('retirado')->default(false)->after('telefono');
            $table->date('fecha_retiro')->nullable()->after('retirado');
        });
    }

    public function down(): void
    {
        Schema::table('padres', function (Blueprint $table) {
            $table->dropColumn(['retirado', 'fecha_retiro']);
        });
    }
};
