<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->unsignedBigInteger('abono_id')->nullable()->change();
            $table->unsignedBigInteger('movimiento_anulado_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->unsignedTinyInteger('abono_id')->nullable()->change();
            $table->unsignedTinyInteger('movimiento_anulado_id')->nullable()->change();
        });
    }
};
