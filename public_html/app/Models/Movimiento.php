<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Movimiento extends Model
{
    // ── Flags de tipo ─────────────────────────────────────────────────────────
    const TIPO_INGRESO = 0;
    const TIPO_EGRESO  = 1;

    const CAT_ABONO     = 0;
    const CAT_ANULACION = 1;
    const CAT_EVENTO    = 2;
    const CAT_CUOTA     = 3;
    const CAT_OTRO      = 4;

    protected $fillable = [
        'tipo',
        'monto',
        'descripcion',
        'categoria',
        'fecha',
        'comprobante',
        'observaciones',
        'registrado_por',
        'abono_id',
        'movimiento_anulado_id',
        'evento_id',

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
    public function movimientoAnulado()
    {
        return $this->belongsTo(Movimiento::class, 'movimiento_anulado_id');
    }

    public function anulacion()
    {
        return $this->hasOne(Movimiento::class, 'movimiento_anulado_id');
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
