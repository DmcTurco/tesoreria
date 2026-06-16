<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('padre_fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('padre_id')->constrained('padres')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('plataforma')->nullable();
            $table->timestamps();
        });

        // Migrar el token único que ya estuviera guardado en `padres.fcm_token`
        // (si existe) a la nueva tabla, para no perder registros existentes.
        DB::table('padres')
            ->whereNotNull('fcm_token')
            ->select('id', 'fcm_token', 'fcm_platform')
            ->orderBy('id')
            ->each(function ($padre) {
                DB::table('padre_fcm_tokens')->insertOrIgnore([
                    'padre_id'   => $padre->id,
                    'token'      => $padre->fcm_token,
                    'plataforma' => $padre->fcm_platform,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('padre_fcm_tokens');
    }
};
