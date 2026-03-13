<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Movimiento extends Model
{
    // ── Flags de tipo ─────────────────────────────────────────────────────────
    const TIPO_INGRESO = 0;
    const TIPO_EGRESO  = 1;

    protected $fillable = [
        'tipo',
        'monto',
        'descripcion',
        'categoria',
        'fecha',
        'comprobante',
        'observaciones',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'tipo'  => 'integer',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function registrador()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function esIngreso(): bool
    {
        return $this->tipo === self::TIPO_INGRESO;
    }
    public function esEgreso(): bool
    {
        return $this->tipo === self::TIPO_EGRESO;
    }
}
