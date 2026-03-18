<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    // ── Flags de estado ───────────────────────────────────────────────────────
    const ESTADO_PENDIENTE = 0;
    const ESTADO_PAGADO    = 1;
    const ESTADO_ANULADO   = 2;
    const DEUDA_PERDONADA  = 'perdonada'; 

    protected $fillable = [
        'padre_id',
        'concepto_pago_id',
        'concepto',
        'monto',
        'fecha',
        'estado',
        'observaciones',
        'registrado_por',
    ];

    protected $casts = [
        'fecha'  => 'date',
        'monto'  => 'decimal:2',
        'estado' => 'integer',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function padre()
    {
        return $this->belongsTo(Padre::class);
    }

    public function conceptoPago()
    {
        return $this->belongsTo(ConceptoPago::class);
    }

    public function registrador()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
