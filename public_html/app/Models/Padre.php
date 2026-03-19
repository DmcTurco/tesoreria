<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Padre extends Model
{
    protected $fillable = [
        'codigo',
        'nombre',
        'hijo',
        'grado',
        'telefono',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function abonos()
    {
        return $this->hasMany(Abono::class);
    }

    public function multas()
    {
        return $this->hasMany(Multa::class);
    }

    public function eventos()
    {
        return $this->belongsToMany(Evento::class, 'evento_padres')
            ->withPivot('fecha', 'estado', 'hora_marcado', 'multa_generada', 'motivo_exoneracion', 'exonerado_por')
            ->withTimestamps();
    }

    public function eventoPadres()
    {
        return $this->hasMany(EventoPadre::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Total de deuda pendiente (multas + cobros de eventos) */
    public function saldoDeuda(): float
    {
        // Multas pendientes o parciales → saldo real = monto - monto_pagado
        $multas = $this->multas()
            ->whereIn('estado', [Multa::ESTADO_PENDIENTE, 1]) // 0=pendiente, 1=parcial
            ->get()
            ->sum(fn($m) => max(0, (float) $m->monto - (float) ($m->monto_pagado ?? 0)));

        // Cobros de eventos pendientes o parciales → saldo real
        $cobros = $this->eventoPadres()
            ->whereIn('estado', [EventoPadre::ESTADO_PENDIENTE, 1]) // 0=pendiente, 1=parcial
            ->whereHas('evento', fn($q) => $q->where('tipo', Evento::TIPO_CUOTA))
            ->with('evento')
            ->get()
            ->sum(fn($ep) => max(0, (float) ($ep->evento->multa_monto ?? 0) - (float) ($ep->monto_pagado ?? 0)));

        return (float) ($multas + $cobros);
    }

    /** String que se codifica en el QR personal del padre */
    public function qrData(): string
    {
        return implode('|', [
            'APAFA-TES',
            $this->id,
            $this->codigo,
            $this->nombre,
            $this->hijo,
            $this->grado,
        ]);
    }
}
