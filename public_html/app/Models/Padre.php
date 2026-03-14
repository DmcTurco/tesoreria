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

    public function pagos()
    {
        return $this->hasMany(Pago::class);
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

    /** Total de deuda pendiente (multas + cuotas pendientes) */
    public function saldoDeuda(): float
    {
        $multas = $this->multas()->where('estado', Multa::ESTADO_PENDIENTE)->sum('monto');
        $cuotas = $this->pagos()->where('estado', Pago::ESTADO_PENDIENTE)->sum('monto');
        return (float) ($multas + $cuotas);
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