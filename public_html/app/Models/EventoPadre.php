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
        'estado',
        'monto_pagado', // ← agregar esto
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
}
