<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Models\Movimiento;

class Evento extends Model
{
    // ── Flags de tipo ─────────────────────────────────────────────────────────
    const TIPO_GUARDIA   = 0;
    const TIPO_FAENA     = 1;
    const TIPO_REUNION   = 2;
    const TIPO_CUOTA     = 3; // ← TIPO_COBRO → TIPO_CUOTA
    const TIPO_ACTIVIDAD = 4;

    // ── Flags de estado ───────────────────────────────────────────────────────
    const ESTADO_ACTIVO  = 0;
    const ESTADO_CERRADO = 1;

    protected $fillable = [
        'titulo',
        'descripcion',
        'tipo',
        'fecha_inicio',
        'fecha_fin',
        'hora_inicio',
        'hora_fin',
        'dias_semana',
        'padres_por_dia',
        'lugar',
        'tiene_multa',
        'multa_monto',
        'estado',
        'creado_por',
    ];

    protected $casts = [
        'fecha_inicio'   => 'date',
        'fecha_fin'      => 'date',
        'dias_semana'    => 'array',
        'tiene_multa'    => 'boolean',
        'multa_monto'    => 'decimal:2',
        'tipo'           => 'integer',
        'estado'         => 'integer',
        'padres_por_dia' => 'integer',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function padres()
    {
        return $this->belongsToMany(Padre::class, 'evento_padres')
            ->withPivot('fecha', 'estado', 'hora_marcado', 'multa_generada', 'motivo_exoneracion', 'exonerado_por')
            ->withTimestamps();
    }

    public function eventoPadres()
    {
        return $this->hasMany(EventoPadre::class);
    }

    public function multas()
    {
        return $this->hasMany(Multa::class);
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    // ── Helpers de tipo ───────────────────────────────────────────────────────

    public function esGuardia(): bool
    {
        return $this->tipo === self::TIPO_GUARDIA;
    }
    public function esFaena(): bool
    {
        return $this->tipo === self::TIPO_FAENA;
    }
    public function esReunion(): bool
    {
        return $this->tipo === self::TIPO_REUNION;
    }
    public function esCuota(): bool  // ← esCobro() → esCuota()
    {
        return $this->tipo === self::TIPO_CUOTA;
    }
    public function esActividad(): bool
    {
        return $this->tipo === self::TIPO_ACTIVIDAD;
    }
    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
    public function precioHistorial()
    {
        return $this->hasMany(EventoPrecioHistorial::class)->orderBy('created_at');
    }

    /**
     * Indica si ahora mismo estamos dentro del rango horario.
     * Solo aplica a guardias, faenas y reuniones.
     */
    public function estaEnHorario(): bool
    {
        if (!$this->hora_inicio || !$this->hora_fin) return false;

        $ahora  = Carbon::now();
        $inicio = Carbon::parse($ahora->format('Y-m-d') . ' ' . $this->hora_inicio);
        $fin    = Carbon::parse($ahora->format('Y-m-d') . ' ' . $this->hora_fin);

        return $ahora->between($inicio, $fin);
    }

    /**
     * Aplica multas automáticas a los ausentes del día indicado.
     * Para guardias se filtra por fecha, para otros tipos aplica a todos.
     */
    public function aplicarMultasAusentes(?string $fecha = null): int
    {
        if (!$this->tiene_multa) return 0;

        $query = $this->eventoPadres()
            ->where('estado', EventoPadre::ESTADO_AUSENTE)
            ->where('multa_generada', false);

        if ($fecha) {
            $query->where('fecha', $fecha);
        }

        $ausentes = $query->get();

        foreach ($ausentes as $ep) {
            $descripcionFecha = $fecha ? " — {$fecha}" : '';

            Multa::create([
                'padre_id'       => $ep->padre_id,
                'evento_id'      => $this->id,
                'monto'          => $this->multa_monto,
                'concepto'       => "Ausencia a {$this->titulo}{$descripcionFecha}",
                'estado'         => Multa::ESTADO_PENDIENTE,
                'fecha_generada' => now()->toDateString(),
            ]);

            $ep->update(['multa_generada' => true]);
        }

        return $ausentes->count();
    }

    public function resumenPagos(): array
    {
        $eventoPadres = $this->eventoPadres()->get();

        // Solo egresos manuales — excluir devoluciones automáticas por cambio de precio (CAT_CUOTA)
        $monto_entregado = Movimiento::where('evento_id', $this->id)
            ->where('tipo', Movimiento::TIPO_EGRESO)
            ->where('categoria', '!=', Movimiento::CAT_CUOTA)
            ->sum('monto');

        return [
            'total_padres'    => $eventoPadres->count(),
            'pagados'         => $eventoPadres->filter(fn($ep) => (float) $ep->monto_pagado >= (float) ($ep->monto_asignado ?? $this->multa_monto))->count(),
            'pendientes'      => $eventoPadres->filter(fn($ep) => (float) $ep->monto_pagado < (float) ($ep->monto_asignado ?? $this->multa_monto))->count(),
            'monto_recaudado' => (float) $eventoPadres->sum('monto_pagado'),
            'monto_esperado'  => (float) $eventoPadres->sum(fn($ep) => $ep->monto_asignado ?? $this->multa_monto),
            'monto_entregado' => (float) $monto_entregado,
        ];
    }
}
