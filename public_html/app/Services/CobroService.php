<?php

namespace App\Services;

use App\Models\Abono;
use App\Models\Evento;
use App\Models\EventoPadre;
use App\Models\Movimiento;
use App\Models\Padre;

/**
 * Fuente única de verdad para todo lo que toca monto_pagado en evento_padres.
 *
 * Regla invariante:
 *   evento_padres.monto_pagado = SUM(abonos WHERE tipo_deuda='cobro' AND deuda_id=ep.id AND estado=0)
 *
 * Nunca modificar monto_pagado directo fuera de este servicio.
 */
class CobroService
{
    /**
     * Recalcula monto_pagado y estado desde los abonos activos.
     * Llamar siempre que se crea o anula un abono de tipo 'cobro'.
     */
    public function sincronizar(EventoPadre $ep): void
    {
        $total         = (float) Abono::where('tipo_deuda', 'cobro')
            ->where('deuda_id', $ep->id)
            ->where('estado', Abono::ESTADO_ACTIVO)
            ->sum('monto');

        $montoAsignado = (float) ($ep->monto_asignado ?? $ep->evento->multa_monto ?? 0);

        $estado = $ep->estado;

        // No pisar exonerado/justificado: esos estados solo se cambian
        // explícitamente con revertir-exoneracion.
        $esExoneradoOJustificado = in_array($ep->estado, [
            EventoPadre::ESTADO_EXONERADO,
            EventoPadre::ESTADO_JUSTIFICADO,
        ]);

        if ($montoAsignado > 0 && !$esExoneradoOJustificado) {
            $estado = $total >= $montoAsignado
                ? EventoPadre::ESTADO_PRESENTE
                : EventoPadre::ESTADO_PENDIENTE;
        }

        $ep->update(['monto_pagado' => $total, 'estado' => $estado]);
    }

    /**
     * Registra un pago (abono de cobro) y sincroniza monto_pagado.
     */
    public function registrarPago(
        EventoPadre $ep,
        float       $monto,
        string      $fecha,
        int         $registradoPor,
        Evento      $evento
    ): Abono {
        $abono = Abono::create([
            'padre_id'       => $ep->padre_id,
            'tipo_deuda'     => 'cobro',
            'deuda_id'       => $ep->id,
            'monto'          => $monto,
            'fecha'          => $fecha,
            'registrado_por' => $registradoPor,
            'estado'         => Abono::ESTADO_ACTIVO,
        ]);

        Movimiento::create([
            'tipo'           => Movimiento::TIPO_INGRESO,
            'monto'          => $monto,
            'descripcion'    => 'Abono cobro - ' . optional($ep->padre)->nombre,
            'categoria'      => Movimiento::CAT_ABONO,
            'fecha'          => $fecha,
            'registrado_por' => $registradoPor,
            'abono_id'       => $abono->id,
            'evento_id'      => $evento->id,
            'padre_id'       => $ep->padre_id,
        ]);

        $this->sincronizar($ep);

        return $abono;
    }

    /**
     * Procesa una devolución por cambio de precio (precio bajó).
     * Anula el/los abonos existentes, crea uno nuevo por el neto y registra el egreso.
     */
    public function procesarDevolucion(
        EventoPadre $ep,
        float       $montoDevolucion,
        int         $registradoPor,
        Evento      $evento,
        Padre       $padre
    ): void {
        // Anular abonos existentes hasta cubrir el monto a devolver
        $pendienteAnular = $montoDevolucion;
        $abonosActivos   = Abono::where('tipo_deuda', 'cobro')
            ->where('deuda_id', $ep->id)
            ->where('estado', Abono::ESTADO_ACTIVO)
            ->orderByDesc('monto')
            ->get();

        foreach ($abonosActivos as $abono) {
            if ($pendienteAnular <= 0) break;
            $abono->update([
                'estado'           => Abono::ESTADO_ANULADO,
                'motivo_anulacion' => "Devolución por cambio de precio: {$evento->titulo}",
                'anulado_por'      => $registradoPor,
                'anulado_at'       => now(),
                'deuda_perdonada'  => false,
            ]);
            $pendienteAnular -= (float) $abono->monto;
        }

        // Si se anuló de más, crear abono por el exceso restante
        $nuevoNeto = (float) $ep->monto_pagado - $montoDevolucion;
        if ($nuevoNeto > 0) {
            $abonoNuevo = Abono::create([
                'padre_id'       => $padre->id,
                'tipo_deuda'     => 'cobro',
                'deuda_id'       => $ep->id,
                'monto'          => $nuevoNeto,
                'fecha'          => now()->toDateString(),
                'registrado_por' => $registradoPor,
                'estado'         => Abono::ESTADO_ACTIVO,
            ]);

            Movimiento::create([
                'tipo'           => Movimiento::TIPO_INGRESO,
                'monto'          => $nuevoNeto,
                'descripcion'    => "Reabono tras devolución: {$padre->nombre} — {$evento->titulo}",
                'categoria'      => Movimiento::CAT_CUOTA,
                'fecha'          => now()->toDateString(),
                'registrado_por' => $registradoPor,
                'abono_id'       => $abonoNuevo->id,
                'evento_id'      => $evento->id,
                'padre_id'       => $padre->id,
            ]);
        }

        // Egreso de caja por la devolución
        Movimiento::create([
            'tipo'           => Movimiento::TIPO_EGRESO,
            'monto'          => $montoDevolucion,
            'descripcion'    => "Devolución: {$padre->nombre} — {$evento->titulo}",
            'categoria'      => Movimiento::CAT_CUOTA,
            'fecha'          => now()->toDateString(),
            'registrado_por' => $registradoPor,
            'evento_id'      => $evento->id,
            'padre_id'       => $padre->id,
        ]);

        $this->sincronizar($ep);
    }

    /**
     * Procesa un cobro extra por cambio de precio (precio subió).
     * Crea un nuevo abono por el monto adicional y registra el ingreso.
     */
    public function procesarCobroExtra(
        EventoPadre $ep,
        float       $montoAdicional,
        int         $registradoPor,
        Evento      $evento,
        Padre       $padre
    ): void {
        $abono = Abono::create([
            'padre_id'       => $padre->id,
            'tipo_deuda'     => 'cobro',
            'deuda_id'       => $ep->id,
            'monto'          => $montoAdicional,
            'fecha'          => now()->toDateString(),
            'registrado_por' => $registradoPor,
            'estado'         => Abono::ESTADO_ACTIVO,
        ]);

        Movimiento::create([
            'tipo'           => Movimiento::TIPO_INGRESO,
            'monto'          => $montoAdicional,
            'descripcion'    => "Cobro adicional: {$padre->nombre} — {$evento->titulo}",
            'categoria'      => Movimiento::CAT_CUOTA,
            'fecha'          => now()->toDateString(),
            'registrado_por' => $registradoPor,
            'abono_id'       => $abono->id,
            'evento_id'      => $evento->id,
            'padre_id'       => $padre->id,
        ]);

        $this->sincronizar($ep);
    }
}
