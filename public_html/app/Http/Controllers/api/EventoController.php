<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Evento;
use App\Models\EventoPadre;
use App\Models\EventoPrecioHistorial;
use App\Models\Movimiento;
use App\Models\Multa;
use App\Models\Padre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EventoController extends Controller
{
    // GET /api/eventos
    public function index()
    {
        $eventos = Evento::with('creador')
            ->orderByDesc('fecha_inicio')
            ->get()
            ->map(fn($e) => array_merge(
                $e->toArray(),
                // Solo calcular resumen para eventos de cuota
                $e->esCuota() ? ['resumen_pagos' => $e->resumenPagos()] : []
            ));

        return response()->json($eventos);
    }

    // GET /api/eventos/{id}
    public function show(Evento $evento)
    {
        $evento->load([
            'creador:id,name',
            'padres' => function ($q) {
                $q->select('padres.id', 'padres.nombre', 'padres.dni')
                    ->withPivot('fecha', 'estado', 'es_reemplazo', 'reemplaza_a');
            }
        ]);

        return response()->json($evento);
    }

    // POST /api/eventos
    public function store(Request $request)
    {
        $request->validate([
            'titulo'         => 'required|string|max:255',
            'descripcion'    => 'nullable|string',
            'tipo'           => 'required|integer|in:0,1,2,3,4',
            'fecha_inicio'   => 'required|date',
            'fecha_fin'      => 'nullable|date|after_or_equal:fecha_inicio',
            'hora_inicio'    => 'nullable|date_format:H:i',
            'hora_fin'       => 'nullable|date_format:H:i',
            'dias_semana'    => 'nullable|array',
            'dias_semana.*'  => 'integer|between:1,7',
            'padres_por_dia' => 'nullable|integer|min:1',
            'lugar'          => 'nullable|string|max:255',
            'tiene_multa'    => 'boolean',
            'multa_monto'    => 'nullable|numeric|min:0',
            'padres_ids'     => 'nullable|array',
            'padres_ids.*'   => 'integer|exists:padres,id',
        ]);

        return DB::transaction(function () use ($request) {
            $evento = Evento::create([
                'titulo'         => $request->titulo,
                'descripcion'    => $request->descripcion,
                'tipo'           => $request->tipo,
                'fecha_inicio'   => $request->fecha_inicio,
                'fecha_fin'      => $request->fecha_fin,
                'hora_inicio'    => $request->hora_inicio,
                'hora_fin'       => $request->hora_fin,
                'dias_semana'    => $request->dias_semana,
                'padres_por_dia' => $request->padres_por_dia,
                'lugar'          => $request->lugar,
                'tiene_multa'    => $request->boolean('tiene_multa', false),
                'multa_monto'    => $request->multa_monto ?? 10.00,
                'estado'         => Evento::ESTADO_ACTIVO,
                'creado_por'     => $request->user()->id,
            ]);

            $padresIds = $request->input('padres_ids', []);

            // Cobro → todos los padres automáticamente
            if ($evento->esCuota()) {
                $this->asignarTodosLosPadres($evento);
            }
            // Reunión → todos los padres automáticamente
            elseif ($evento->tipo === Evento::TIPO_REUNION) {
                $this->asignarTodosLosPadres($evento);
            }
            // Guardia → solo registrar las fechas del evento, SIN asignar padres
            // La asignación se hace manualmente por día desde el detalle del evento
            elseif ($evento->esGuardia()) {
                $this->generarFechasGuardia($evento);
            }
            // Faena / Actividad → asignación manual si vienen padres_ids
            elseif (in_array($evento->tipo, [Evento::TIPO_FAENA, Evento::TIPO_ACTIVIDAD])) {
                if (!empty($padresIds)) {
                    $this->asignarPadresManual($evento, $padresIds);
                }
            }

            return response()->json([
                'message' => 'Evento creado correctamente',
                'evento'  => $evento->load('eventoPadres.padre'),
            ], 201);
        });
    }

    // PUT /api/eventos/{id}
    public function update(Request $request, Evento $evento)
    {
        $request->validate([
            'titulo'      => 'sometimes|string|max:255',
            'descripcion' => 'nullable|string',
            'lugar'       => 'nullable|string|max:255',
            'tiene_multa' => 'boolean',
            'multa_monto' => 'nullable|numeric|min:0',
        ]);

        $montoAnterior = (float) $evento->multa_monto;
        $montoNuevo    = (float) $request->input('multa_monto', $montoAnterior);
        $cambiaMonto   = $request->has('multa_monto') && $montoNuevo !== $montoAnterior;

        $evento->update($request->only('titulo', 'descripcion', 'lugar', 'tiene_multa', 'multa_monto'));

        $resumen = [];

        if ($cambiaMonto) {

            EventoPrecioHistorial::create([
                'evento_id'      => $evento->id,
                'monto_anterior' => $montoAnterior,
                'monto_nuevo'    => $montoNuevo,
                'registrado_por' => $request->user()->id,
            ]);

            // Pendientes → nuevo precio, sin ajuste pendiente
            EventoPadre::where('evento_id', $evento->id)
                ->where('estado', EventoPadre::ESTADO_PENDIENTE)
                ->update([
                    'monto_asignado'  => $montoNuevo,
                    'ajuste_resuelto' => 1,
                ]);

            // Pagados → actualizar monto asignado y marcar ajuste si hay diferencia
            $pagados = EventoPadre::where('evento_id', $evento->id)
                ->where('monto_pagado', '>', 0)
                ->get();

            foreach ($pagados as $ep) {
                $diferencia = $montoNuevo - (float) $ep->monto_pagado;

                $ep->update([
                    'monto_asignado'  => $montoNuevo,
                    'ajuste_resuelto' => $diferencia == 0 ? 1 : 0,
                ]);
            }

            $resumen = [
                'monto_anterior'  => $montoAnterior,
                'monto_nuevo'     => $montoNuevo,
                'con_devolucion'  => $pagados->filter(fn($ep) => $montoNuevo < (float) $ep->monto_pagado)->count(),
                'con_cobro_extra' => $pagados->filter(fn($ep) => $montoNuevo > (float) $ep->monto_pagado)->count(),
            ];
        }

        return response()->json([
            'message' => 'Evento actualizado correctamente',
            'evento'  => $evento,
            'ajustes' => $resumen,
        ]);
    }

    // POST /api/eventos/{id}/cerrar
    public function cerrar(Evento $evento)
    {
        DB::transaction(function () use ($evento) {
            // Pendientes → ausentes
            EventoPadre::where('evento_id', $evento->id)
                ->where('estado', EventoPadre::ESTADO_PENDIENTE)
                ->update(['estado' => EventoPadre::ESTADO_AUSENTE]);

            // Generar multas si aplica
            if ($evento->tiene_multa) {
                $faltosos = EventoPadre::where('evento_id', $evento->id)
                    ->where('estado', EventoPadre::ESTADO_AUSENTE)
                    ->get();

                foreach ($faltosos as $ep) {
                    Multa::firstOrCreate([
                        'padre_id'  => $ep->padre_id,
                        'evento_id' => $evento->id,
                    ], [
                        'monto'          => $ep->monto_asignado ?? $evento->multa_monto,
                        'concepto'       => "Inasistencia: {$evento->titulo}",
                        'estado'         => Multa::ESTADO_PENDIENTE,
                        'fecha_generada' => now()->toDateString(),
                    ]);
                }
            }

            $evento->update(['estado' => Evento::ESTADO_CERRADO]);
        });

        return response()->json(['message' => 'Evento cerrado correctamente']);
    }

    // POST /eventos/{evento}/agregar-padre
    // Para guardia: requiere fecha. Para otros: sin fecha.
    public function agregarPadre(Request $request, Evento $evento)
    {
        $request->validate([
            'padre_id' => 'required|integer|exists:padres,id',
            'fecha'    => 'nullable|date',
        ]);

        $fecha = $evento->esGuardia() ? $request->fecha : null;

        // Para guardia se requiere fecha
        if ($evento->esGuardia() && !$fecha) {
            return response()->json(['message' => 'Se requiere la fecha para una guardia'], 422);
        }

        // Verificar que no exceda padres_por_dia en guardias
        if ($evento->esGuardia() && $fecha) {
            // También excluir justificado aquí (solo tenías exonerado)
            $count = EventoPadre::where('evento_id', $evento->id)
                ->where('fecha', $fecha)
                ->whereNotIn('estado', [
                    EventoPadre::ESTADO_EXONERADO,
                    EventoPadre::ESTADO_JUSTIFICADO, // ← agregar
                ])
                ->count();

            if ($count >= $evento->padres_por_dia) {
                return response()->json([
                    'message' => "Ya hay {$evento->padres_por_dia} padre(s) asignados para esta fecha",
                ], 422);
            }
        }

        // Evitar duplicado
        $existe = EventoPadre::where('evento_id', $evento->id)
            ->where('padre_id', $request->padre_id)
            ->where('fecha', $fecha)
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'El padre ya está asignado en esta fecha'], 422);
        }

        EventoPadre::create([
            'evento_id' => $evento->id,
            'padre_id'  => $request->padre_id,
            'fecha'     => $fecha,
            'estado'    => EventoPadre::ESTADO_PENDIENTE,
        ]);

        return response()->json(['message' => 'Padre asignado correctamente']);
    }

    // PUT /api/eventos/{evento}/quitar-padre/{padre}
    public function quitarPadre(Request $request, Evento $evento, Padre $padre)
    {
        $request->validate([
            // 'exonerado' = imprevisto justificado, 'justificado' = con documento/motivo formal
            'tipo'   => 'required|in:exonerado,justificado',
            'motivo' => 'required|string|max:500',
            'fecha'  => 'nullable|date', // solo para guardias
        ]);

        $query = EventoPadre::where('evento_id', $evento->id)
            ->where('padre_id', $padre->id);

        if ($evento->esGuardia()) {
            $query->where('fecha', $request->fecha ?? now()->toDateString());
        }

        $ep = $query->first();

        if (!$ep) {
            return response()->json(['message' => 'Asignación no encontrada'], 404);
        }

        if ($ep->estado === EventoPadre::ESTADO_PRESENTE) {
            return response()->json(['message' => 'El padre ya registró asistencia'], 422);
        }

        $nuevoEstado = $request->tipo === 'exonerado'
            ? EventoPadre::ESTADO_EXONERADO
            : EventoPadre::ESTADO_JUSTIFICADO;

        $ep->update([
            'estado'             => $nuevoEstado,
            'motivo_exoneracion' => $request->motivo,
            'exonerado_por'      => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Estado actualizado correctamente',
            'estado'  => $request->tipo,
        ]);
    }

    // DELETE /api/eventos/{evento}/quitar-padre/{padre}
    public function eliminarPadre(Request $request, Evento $evento, Padre $padre)
    {
        $fecha = $request->query('fecha');

        $query = EventoPadre::where('evento_id', $evento->id)
            ->where('padre_id', $padre->id);

        if ($fecha) {
            $query->where('fecha', $fecha);
        }

        $ep = $query->first();

        if (!$ep) {
            return response()->json(['message' => 'Asignación no encontrada'], 404);
        }

        if ($ep->estado === EventoPadre::ESTADO_PRESENTE) {
            return response()->json(['message' => 'No se puede eliminar un padre que ya asistió'], 422);
        }

        $ep->delete();

        return response()->json(['message' => 'Asignación eliminada correctamente']);
    }


    // GET /api/eventos/{id}/fechas  ← fechas de una guardia con sus padres asignados
    public function fechas(Evento $evento)
    {
        if (!$evento->esGuardia()) {
            return response()->json(['message' => 'Solo disponible para guardias'], 422);
        }

        if (!$evento->fecha_fin || !$evento->dias_semana) {
            return response()->json([]);
        }

        $diasSemana = $evento->dias_semana;
        $fecha      = Carbon::parse($evento->fecha_inicio);
        $fin        = Carbon::parse($evento->fecha_fin);
        $fechas     = [];

        // Generar lista de fechas válidas
        while ($fecha->lte($fin)) {
            if (in_array($fecha->dayOfWeekIso, $diasSemana)) {
                $fechas[] = $fecha->toDateString();
            }
            $fecha->addDay();
        }

        // Cargar asignaciones existentes agrupadas por fecha
        $asignaciones = EventoPadre::where('evento_id', $evento->id)
            ->with('padre:id,nombre,grado,hijo')
            ->get()
            ->groupBy(fn($ep) => $ep->fecha?->toDateString());

        $resultado = array_map(fn($f) => [
            'fecha'    => $f,
            'padres'   => ($asignaciones[$f] ?? collect())->values(),
            // ← excluir exonerados (4) y justificados (3) del conteo
            'completo' => ($asignaciones[$f] ?? collect())
                ->whereNotIn('estado', [
                    EventoPadre::ESTADO_EXONERADO,
                    EventoPadre::ESTADO_JUSTIFICADO,
                ])
                ->count() >= $evento->padres_por_dia,
            'faltante' => max(0, $evento->padres_por_dia - ($asignaciones[$f] ?? collect())
                ->whereNotIn('estado', [
                    EventoPadre::ESTADO_EXONERADO,
                    EventoPadre::ESTADO_JUSTIFICADO,
                ])
                ->count()),
        ], $fechas);

        return response()->json($resultado);
    }

    // POST /api/eventos/{id}/asistencia
    public function registrarAsistencia(Request $request, Evento $evento)
    {
        $request->validate([
            'padre_id'     => 'required|integer|exists:padres,id',
            'fecha'        => 'nullable|date',
            'es_reemplazo' => 'boolean',       // ← agregado
            'anotacion'    => 'nullable|string|max:255', // ← agregado
        ]);

        $fecha = $request->input('fecha', now()->toDateString());

        $query = EventoPadre::where('evento_id', $evento->id)
            ->where('padre_id', $request->padre_id);

        if ($evento->esGuardia()) {
            $query->where('fecha', $fecha);
        }

        $ep = $query->first();

        if (!$ep) {
            return response()->json([
                'message' => 'Este padre no está asignado a este evento en la fecha indicada',
            ], 404);
        }

        if ($ep->estado === EventoPadre::ESTADO_PRESENTE) {
            return response()->json(['message' => 'Asistencia ya registrada'], 422);
        }

        if (in_array($ep->estado, [EventoPadre::ESTADO_EXONERADO, EventoPadre::ESTADO_JUSTIFICADO])) {
            return response()->json(['message' => 'El padre está exonerado o justificado'], 422);
        }

        $ep->update([
            'estado'       => EventoPadre::ESTADO_PRESENTE,
            'hora_marcado' => now(),
            'es_reemplazo' => $request->boolean('es_reemplazo', false), // ← agregado
            'anotacion'    => $request->anotacion,                       // ← agregado
        ]);

        return response()->json([
            'message' => 'Asistencia registrada correctamente',
            'padre'   => $ep->padre->nombre,
            'hora'    => $ep->hora_marcado,
        ]);
    }

    // POST /api/eventos/{id}/exonerar-padre
    public function exonerarPadre(Request $request, Evento $evento)
    {
        $request->validate([
            'padre_id'           => 'required|integer|exists:padres,id',
            'motivo_exoneracion' => 'required|string|max:500',
            'fecha'              => 'nullable|date',
        ]);

        $query = EventoPadre::where('evento_id', $evento->id)
            ->where('padre_id', $request->padre_id);

        if ($evento->esGuardia()) {
            $query->where('fecha', $request->fecha ?? now()->toDateString());
        }

        $ep = $query->first();

        if (!$ep) {
            return response()->json(['message' => 'Asignación no encontrada'], 404);
        }

        $ep->update([
            'estado'             => EventoPadre::ESTADO_EXONERADO,
            'motivo_exoneracion' => $request->motivo_exoneracion,
            'exonerado_por'      => $request->user()->id,
        ]);

        return response()->json(['message' => 'Padre exonerado correctamente']);
    }

    // GET /api/eventos/{id}/padres
    public function padres(Evento $evento)
    {
        $padres = $evento->eventoPadres()
            ->with('padre')
            ->orderBy('fecha')
            ->get();

        return response()->json($padres);
    }

    // GET /api/eventos/{evento}/ajustes
    public function ajustes(Evento $evento)
    {
        $pendientes = EventoPadre::where('evento_id', $evento->id)
            ->where('ajuste_resuelto', 0)
            ->with('padre:id,nombre,codigo')
            ->get()
            ->map(fn($ep) => [
                'padre_id'       => $ep->padre_id,
                'nombre'         => $ep->padre->nombre,
                'codigo'         => $ep->padre->codigo,
                'monto_pagado'   => (float) $ep->monto_pagado,
                'monto_asignado' => (float) $ep->monto_asignado,
                'diferencia'     => (float) $ep->monto_asignado - (float) $ep->monto_pagado,
                // positivo = debe más | negativo = se le devuelve
            ]);

        return response()->json($pendientes);
    }

    // POST /api/eventos/{evento}/resolver-ajuste
    public function resolverAjuste(Request $request, Evento $evento)
    {
        $request->validate([
            'padre_id'        => 'required|integer|exists:padres,id',
            'monto_adicional' => 'nullable|numeric|min:0',
        ]);

        $ep = EventoPadre::where('evento_id', $evento->id)
            ->where('padre_id', $request->padre_id)
            ->where('ajuste_resuelto', 0)
            ->first();

        if (!$ep) {
            return response()->json(['message' => 'No hay ajuste pendiente para este padre'], 404);
        }

        $diferencia = (float) $ep->monto_asignado - (float) $ep->monto_pagado;
        $padre      = Padre::find($request->padre_id);

        DB::transaction(function () use ($ep, $diferencia, $padre, $evento, $request) {

            // Cobro extra → el padre paga más → INGRESO
            if ($diferencia > 0 && $request->filled('monto_adicional')) {
                $ep->monto_pagado = (float) $ep->monto_pagado + (float) $request->monto_adicional;

                Movimiento::create([
                    'tipo'          => Movimiento::TIPO_INGRESO,
                    'monto'         => $request->monto_adicional,
                    'descripcion'   => "Cobro adicional: {$padre->nombre} — {$evento->titulo}",
                    'categoria'     => Movimiento::CAT_CUOTA,
                    'fecha'         => now()->toDateString(),
                    'registrado_por' => $request->user()->id,
                    'evento_id'      => $evento->id,
                ]);
            }

            // Devolución → se regresa dinero → EGRESO
            if ($diferencia < 0) {
                $ep->monto_pagado = (float) $ep->monto_pagado + $diferencia;

                Movimiento::create([
                    'tipo'          => Movimiento::TIPO_EGRESO,
                    'monto'         => abs($diferencia),
                    'descripcion'   => "Devolución: {$padre->nombre} — {$evento->titulo}",
                    'categoria'     => Movimiento::CAT_CUOTA,
                    'fecha'         => now()->toDateString(),
                    'registrado_por' => $request->user()->id,
                    'evento_id'      => $evento->id,
                ]);
            }

            $ep->ajuste_resuelto = 1;
            $ep->save();
        });

        return response()->json([
            'message'        => 'Ajuste resuelto',
            'monto_pagado'   => $ep->monto_pagado,
            'monto_asignado' => $ep->monto_asignado,
        ]);
    }

    // GET /api/eventos/{evento}/movimientos
    public function movimientos(Evento $evento)
    {
        $eventoPadres = EventoPadre::where('evento_id', $evento->id)
            ->with('padre:id,nombre,codigo,hijo,grado')
            ->get();

        $movimientos = Movimiento::where('evento_id', $evento->id)
            ->orderBy('fecha')
            ->get();

        $resultado = $eventoPadres->map(function ($ep) use ($movimientos) {
            $movsPadre = $movimientos->filter(function ($m) use ($ep) {
                if ($m->abono_id) {
                    return Abono::where('id', $m->abono_id)
                        ->where('padre_id', $ep->padre_id)
                        ->exists();
                }
                return str_contains($m->descripcion, $ep->padre->nombre);
            });

            return [
                'padre_id'        => $ep->padre_id,
                'nombre'          => $ep->padre->nombre,
                'codigo'          => $ep->padre->codigo,
                'hijo'            => $ep->padre->hijo,
                'grado'           => $ep->padre->grado,
                'monto_asignado'  => (float) $ep->monto_asignado,
                'monto_pagado'    => (float) $ep->monto_pagado,
                'estado'          => $ep->estado,
                'ajuste_resuelto' => $ep->ajuste_resuelto,
                'diferencia'      => (float) $ep->monto_asignado - (float) $ep->monto_pagado,
                'movimientos' => $movsPadre->values()->map(fn($m) => [
                    'tipo'        => $m->tipo,
                    'monto'       => (float) $m->monto,
                    'descripcion' => $m->descripcion,
                    'categoria'   => $m->categoria,
                    'fecha'       => $m->fecha,
                    'created_at'  => $m->created_at, // ← agregar
                    'anulado'     => $m->abono_id
                        ? Abono::find($m->abono_id)?->estado === Abono::ESTADO_ANULADO
                        : false,
                ]),
            ];
        });

        // Egresos manuales del evento — excluye devoluciones automáticas por cambio de precio (CAT_CUOTA)
        $gastos = Movimiento::where('evento_id', $evento->id)
            ->where('tipo', Movimiento::TIPO_EGRESO)
            ->where('categoria', '!=', Movimiento::CAT_CUOTA)
            ->whereNull('abono_id')
            ->with('registrador:id,name')
            ->orderBy('fecha')
            ->get()
            ->map(fn($m) => [
                'id'          => $m->id,
                'monto'       => (float) $m->monto,
                'descripcion' => $m->descripcion,
                'categoria'   => $m->categoria,
                'fecha'       => $m->fecha,
                'registrado_por' => $m->registrador?->name,
            ]);

        return response()->json([
            'evento' => array_merge([
                'id'          => $evento->id,
                'titulo'      => $evento->titulo,
                'multa_monto' => (float) $evento->multa_monto,
            ], $evento->resumenPagos()),
            'precio_historial' => $evento->precioHistorial()
                ->with('registrador:id,name')
                ->get()
                ->map(fn($h) => [
                    'monto_anterior' => (float) $h->monto_anterior,
                    'monto_nuevo'    => (float) $h->monto_nuevo,
                    'registrado_por' => $h->registrador->name,
                    'fecha'          => $h->created_at->toDateTimeString(),
                ]),
            'padres' => $resultado,
            'gastos' => $gastos,
        ]);
    }

    // GET /api/eventos/{evento}/precio-historial
    public function precioHistorial(Evento $evento)
    {
        $historial = $evento->precioHistorial()
            ->with('registrador:id,name')
            ->get()
            ->map(fn($h) => [
                'monto_anterior' => (float) $h->monto_anterior,
                'monto_nuevo'    => (float) $h->monto_nuevo,
                'registrado_por' => $h->registrador->name,
                'fecha'          => $h->created_at->toDateTimeString(),
            ]);

        return response()->json($historial);
    }

    // ── Métodos privados ──────────────────────────────────────────────────────

    /**
     * Genera los slots de fecha para una guardia (sin asignar padres).
     * Solo crea registros si la fecha ya tiene slot, sirve de índice.
     * En este sistema NO creamos slots vacíos — la asignación es por demanda.
     */
    private function generarFechasGuardia(Evento $evento): void
    {
        // No creamos registros aquí.
        // Las fechas se calculan dinámicamente en GET /fechas
        // y los padres se asignan manualmente con POST /agregar-padre + fecha
    }

    /**
     * Asigna una lista específica de padres (faena, actividad).
     */
    private function asignarPadresManual(Evento $evento, array $padresIds): void
    {
        foreach ($padresIds as $padreId) {
            EventoPadre::firstOrCreate([
                'evento_id' => $evento->id,
                'padre_id'  => (int) $padreId,
                'fecha'     => null,
            ], [
                'estado' => EventoPadre::ESTADO_PENDIENTE,
            ]);
        }
    }

    /**
     * Asigna todos los padres (cobros y reuniones).
     */
    private function asignarTodosLosPadres(Evento $evento): void
    {
        $padres = Padre::all();

        foreach ($padres as $padre) {
            EventoPadre::firstOrCreate(
                ['evento_id' => $evento->id, 'padre_id' => $padre->id, 'fecha' => null],
                [
                    'estado'         => EventoPadre::ESTADO_PENDIENTE,
                    'monto_asignado' => $evento->esCuota()
                        ? $evento->multa_monto                                    // cuota → siempre tiene monto
                        : ($evento->tiene_multa ? $evento->multa_monto : null),   // otros → solo si tiene multa
                ]
            );
        }
    }
}
