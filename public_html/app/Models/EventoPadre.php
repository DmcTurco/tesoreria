<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoPadre extends Model
{
    protected $table = 'evento_padres';

    // ── Flags de estado ───────────────────────────────────────────────────────
    const ESTADO_PENDIENTE   = 0;
    const ESTADO_PRESENTE    = 1;
    const ESTADO_AUSENTE     = 2;
    const ESTADO_JUSTIFICADO = 3;
    const ESTADO_EXONERADO   = 4;

    protected $fillable = [
        'evento_id',
        'padre_id',
        'fecha',
        'turnos_estado',
        'estado',
        'monto_pagado',
        'monto_asignado',
        'ajuste_resuelto',
        'hora_marcado',
        'multa_generada',
        'motivo_exoneracion',
        'exonerado_por',
        'es_reemplazo',
        'anotacion',
    ];

    protected $casts = [
        'fecha'          => 'date',
        'hora_marcado'   => 'datetime',
        'multa_generada' => 'boolean',
        'estado'         => 'integer',
        'monto_pagado'    => 'decimal:2',
        'monto_asignado'  => 'decimal:2',
        'ajuste_resuelto' => 'integer',
        'turnos_estado'   => 'array',   // [entrada, salida]  →  0=pendiente, 1=presente
    ];

    // Se incluyen automáticamente en la respuesta JSON (toArray/toJson),
    // así el front (React y Flutter) no necesita recalcular el fallback
    // monto_asignado ?? evento.multa_monto.
    protected $appends = [
        'monto_real',
        'saldo_pendiente',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function evento()
    {
        return $this->belongsTo(Evento::class);
    }

    public function padre()
    {
        return $this->belongsTo(Padre::class);
    }

    public function exonerador()
    {
        return $this->belongsTo(User::class, 'exonerado_por');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }
    public function estaPresente(): bool
    {
        return $this->estado === self::ESTADO_PRESENTE;
    }
    public function estaAusente(): bool
    {
        return $this->estado === self::ESTADO_AUSENTE;
    }
    public function estaJustificado(): bool
    {
        return $this->estado === self::ESTADO_JUSTIFICADO;
    }
    public function estaExonerado(): bool
    {
        return $this->estado === self::ESTADO_EXONERADO;
    }

    public function tieneAjustePendiente(): bool
    {
        return $this->ajuste_resuelto === 0;
    }

    public function diferencia(): float
    {
        return (float) $this->monto_asignado - (float) $this->monto_pagado;
        // positivo = debe más | negativo = se le devuelve
    }

    /**
     * Monto real de la cuota/cobro: usa `monto_asignado` si está definido
     * (> 0); si no, cae al `multa_monto` del evento asociado.
     * Centraliza el fallback que antes se repetía en backend y front.
     */
    public function getMontoRealAttribute(): float
    {
        $asignado = (float) ($this->monto_asignado ?? 0);
        if ($asignado > 0) {
            return $asignado;
        }

        return (float) ($this->evento?->multa_monto ?? 0);
    }

    /** Saldo pendiente real (monto_real - monto_pagado), nunca negativo. */
    public function getSaldoPendienteAttribute(): float
    {
        $saldo = $this->monto_real - (float) ($this->monto_pagado ?? 0);
        return max(0, $saldo);
    }
}
