<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Multa extends Model
{
    // ── Flags de estado ───────────────────────────────────────────────────────
    const ESTADO_PENDIENTE  = 0;
    const ESTADO_PARCIAL    = 1; // ← nuevo
    const ESTADO_PAGADO     = 2; // ← era 1
    const ESTADO_EXONERADO  = 3; // ← era 2
    const ESTADO_ANULADO    = 4; // ← era 3

    protected $fillable = [
        'padre_id',
        'evento_id',
        'monto',
        'monto_pagado',  // ← agregar si no está
        'concepto',
        'estado',
        'fecha_generada',
        'fecha_pagado',
        'pagado_por',
        'motivo_exoneracion',
        'exonerado_por',
        'fecha_exoneracion',
        'observaciones',
    ];

    protected $casts = [
        'fecha_generada'    => 'date',
        'fecha_pagado'      => 'date',
        'fecha_exoneracion' => 'datetime',
        'monto'             => 'decimal:2',
        'monto_pagado'      => 'decimal:2',
        'estado'            => 'integer',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function padre()
    {
        return $this->belongsTo(Padre::class);
    }

    public function evento()
    {
        return $this->belongsTo(Evento::class);
    }

    public function pagador()
    {
        return $this->belongsTo(User::class, 'pagado_por');
    }

    public function exonerador()
    {
        return $this->belongsTo(User::class, 'exonerado_por');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function estaPendiente(): bool  { return $this->estado === self::ESTADO_PENDIENTE; }
    public function estaParcial(): bool    { return $this->estado === self::ESTADO_PARCIAL; }
    public function estaPagada(): bool     { return $this->estado === self::ESTADO_PAGADO; }
    public function estaExonerada(): bool  { return $this->estado === self::ESTADO_EXONERADO; }
    public function estaAnulada(): bool    { return $this->estado === self::ESTADO_ANULADO; }

    /** Saldo pendiente real */
    public function saldo(): float
    {
        return max(0, (float) $this->monto - (float) ($this->monto_pagado ?? 0));
    }
}