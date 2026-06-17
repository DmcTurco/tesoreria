<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Evento;
use App\Services\CobroService;
use App\Models\EventoPadre;
use App\Models\EventoPrecioHistorial;
use App\Models\Movimiento;
use App\Models\Multa;
use App\Models\Padre;
use App\Services\PushNotificationService;
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
                ($e->esCuota() || $e->esActividad()) ? ['resumen_pagos' => $e->resumenPagos()] :
                ($e->esGuardia()                     ? ['resumen_pagos' => $e->resumenGuardia()] : [])
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
            'tiene_turnos'   => 'integer|in:0,1',
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
                'tiene_turnos'   => $request->input('tiene_turnos', 0),
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
            'titulo'        => 'sometimes|string|max:255',
            'descripcion'   => 'nullable|string',
            'lugar'         => 'nullable|string|max:255',
            'tiene_multa'   => 'boolean',
            'tiene_turnos'  => 'integer|in:0,1',
            'multa_monto'   => 'nullable|numeric|min:0',
            'fecha_inicio'  => 'nullable|date',
            'fecha_fin'     => 'nullable|date|after_or_equal:fecha_inicio',
            'hora_inicio'   => 'nullable|date_format:H:i',
            'hora_fin'      => 'nullable|date_format:H:i',
        ]);

        $montoAnterior   = (float) $evento->multa_monto;
        $montoNuevo      = (float) $request->input('multa_monto', $montoAnterior);
        $cambiaMonto     = $request->has('multa_monto') && $montoNuevo !== $montoAnterior;
        $activaMulta     = $request->has('tiene_multa')
                           && !$evento->tiene_multa
                           && $request->boolean('tiene_multa');
        $activaTurnos    = $request->has('tiene_turnos')
                           && $evento->tiene_turnos == 0
                           && (int) $request->input('tiene_turnos') === 1;

        $evento->update($request->only(
            'titulo', 'descripcion', 'lugar', 'tiene_multa', 'tiene_turnos', 'multa_monto',
            'fecha_inicio', 'fecha_fin', 'hora_inicio', 'hora_fin'
        ));

        $resumen = [];

        // Se activó "tiene_multa" en un evento que no era cobro (ya tenían monto_asignado = null)
        // → asignar multa_monto a todos los padres pendientes
        if ($activaMulta && !$cambiaMonto) {
            EventoPadre::where('evento_id', $evento->id)
                ->where('estado', EventoPadre::ESTADO_PENDIENTE)
                ->whereNull('monto_asignado')
                ->update(['monto_asignado' => $evento->multa_monto]);
        }

        // Se activaron los turnos → inicializar turnos_estado en filas existentes
        if ($activaTurnos) {
            EventoPadre::where('evento_id', $evento->id)
                ->whereNull('turnos_estado')
                ->get()
                ->each(function ($ep) {
                    $estadoEntrada = $ep->estado === EventoPadre::ESTADO_PRESENTE ? 1 : 0;
                    $ep->update(['turnos_estado' => [$estadoEntrada, 0]]);
                });
        }

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
                    // Si el padre ya cubrió el nuevo monto → marcar como presente
                    'estado'          => $diferencia <= 0
                        ? EventoPadre::ESTADO_PRESENTE
                        : $ep->estado,
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
            // Pendientes sin ningún turno marcado → ausentes
            EventoPadre::where('evento_id', $evento->id)
                ->where('estado', EventoPadre::ESTADO_PENDIENTE)
                ->update(['estado' => EventoPadre::ESTADO_AUSENTE]);

            // Generar multas si aplica
            if ($evento->tiene_multa) {
                $montosPorPadre = [];

                // ── Filas sin turnos (eventos normales) ───────────────────────
                $faltososSinTurnos = EventoPadre::where('evento_id', $evento->id)
                    ->where('estado', EventoPadre::ESTADO_AUSENTE)
                    ->whereNull('turnos_estado')
                    ->get();

                foreach ($faltososSinTurnos as $ep) {
                    $monto = (float) ($ep->monto_asignado ?? $evento->multa_monto);
                    $montosPorPadre[$ep->padre_id] = [
                        'monto'   => ($montosPorPadre[$ep->padre_id]['monto'] ?? 0) + $monto,
                        'concepto' => "Inasistencia: {$evento->titulo}",
                    ];
                }

                // ── Filas con turnos (bapers) ─────────────────────────────────
                $filasConTurnos = EventoPadre::where('evento_id', $evento->id)
                    ->whereNotNull('turnos_estado')
                    ->whereNotIn('estado', [
                        EventoPadre::ESTADO_EXONERADO,
                        EventoPadre::ESTADO_JUSTIFICADO,
                    ])
                    ->get();

                foreach ($filasConTurnos as $ep) {
                    $estados     = $ep->turnos_estado ?? [0, 0];
                    $ausentes    = count(array_filter($estados, fn($e) => $e !== 1));

                    if ($ausentes === 0) continue; // asistió a todos los turnos

                    $montoTotal  = (float) ($ep->monto_asignado ?? $evento->multa_monto);
                    $monto       = round($montoTotal / count($estados) * $ausentes, 2);

                    $faltóEntrada = $estados[0] !== 1;
                    $faltóSalida  = $estados[1] !== 1;

                    if ($faltóEntrada && $faltóSalida) {
                        $turnosTexto = 'entrada y salida';
                    } elseif ($faltóEntrada) {
                        $turnosTexto = 'entrada';
                    } else {
                        $turnosTexto = 'salida';
                    }

                    $montosPorPadre[$ep->padre_id] = [
                        'monto'   => ($montosPorPadre[$ep->padre_id]['monto'] ?? 0) + $monto,
                        'concepto' => "Inasistencia ({$turnosTexto}): {$evento->titulo}",
                    ];
                }

                foreach ($montosPorPadre as $padreId => $data) {
                    Multa::firstOrCreate([
                        'padre_id'  => $padreId,
                        'evento_id' => $evento->id,
                    ], [
                        'monto'          => $data['monto'],
                        'concepto'       => $data['concepto'],
                        'estado'         => Multa::ESTADO_PENDIENTE,
                        'fecha_generada' => now()->toDateString(),
                    ]);
                }
            }

            $evento->update(['estado' => Evento::ESTADO_CERRADO]);
        });

        return response()->json(['message' => 'Evento cerrado correctamente']);
    }

    /**
     * POST /api/eventos/{evento}/regenerar-multas
     * Re-genera multas para un evento ya cerrado (útil cuando se olvidó activar tiene_multa antes de cerrar).
     * Activa tiene_multa si estaba apagado, luego corre la misma lógica de cerrar().
     * Usa firstOrCreate → seguro de ejecutar múltiples veces.
     */
    public function regenerarMultas(Evento $evento)
    {
        if (!$evento->tiene_multa) {
            $evento->update(['tiene_multa' => true]);
        }

        $creadas = 0;

        DB::transaction(function () use ($evento, &$creadas) {
            $montosPorPadre = [];

            // Filas sin turnos
            $faltososSinTurnos = EventoPadre::where('evento_id', $evento->id)
                ->where('estado', EventoPadre::ESTADO_AUSENTE)
                ->whereNull('turnos_estado')
                ->get();

            foreach ($faltososSinTurnos as $ep) {
                $monto = (float) ($ep->monto_asignado ?? $evento->multa_monto);
                $montosPorPadre[$ep->padre_id] = [
                    'monto'    => ($montosPorPadre[$ep->padre_id]['monto'] ?? 0) + $monto,
                    'concepto' => "Inasistencia: {$evento->titulo}",
                ];
            }

            // Filas con turnos (bapers)
            $filasConTurnos = EventoPadre::where('evento_id', $evento->id)
                ->whereNotNull('turnos_estado')
                ->whereNotIn('estado', [
                    EventoPadre::ESTADO_EXONERADO,
                    EventoPadre::ESTADO_JUSTIFICADO,
                ])
                ->get();

            foreach ($filasConTurnos as $ep) {
                $estados  = $ep->turnos_estado ?? [0, 0];
                $ausentes = count(array_filter($estados, fn($e) => $e !== 1));
                if ($ausentes === 0) continue;

                $montoTotal = (float) ($ep->monto_asignado ?? $evento->multa_monto);
                $monto      = round($montoTotal / count($estados) * $ausentes, 2);

                $faltóEntrada = $estados[0] !== 1;
                $faltóSalida  = $estados[1] !== 1;

                $turnosTexto = match (true) {
                    $faltóEntrada && $faltóSalida => 'entrada y salida',
                    $faltóEntrada                 => 'entrada',
                    default                       => 'salida',
                };

                $montosPorPadre[$ep->padre_id] = [
                    'monto'    => ($montosPorPadre[$ep->padre_id]['monto'] ?? 0) + $monto,
                    'concepto' => "Inasistencia ({$turnosTexto}): {$evento->titulo}",
                ];
            }

            foreach ($montosPorPadre as $padreId => $data) {
                [$multa, $nueva] = [
                    Multa::firstOrCreate(
                        ['padre_id' => $padreId, 'evento_id' => $evento->id],
                        [
                            'monto'          => $data['monto'],
                            'concepto'       => $data['concepto'],
                            'estado'         => Multa::ESTADO_PENDIENTE,
                            'fecha_generada' => now()->toDateString(),
                        ]
                    ),
                    false,
                ];
                if ($multa->wasRecentlyCreated) $creadas++;
            }
        });

        return response()->json([
            'message' => "Multas regeneradas correctamente",
            'creadas' => $creadas,
        ]);
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

        // Evitar duplicado (una sola fila por padre/fecha)
        $existe = EventoPadre::where('evento_id', $evento->id)
            ->where('padre_id', $request->padre_id)
            ->where('fecha', $fecha)
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'El padre ya está asignado en esta fecha'], 422);
        }

        $this->crearFilasPadre($evento, $request->padre_id, $fecha);

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

        if ($ep->padre) {
            $etiqueta = $request->tipo === 'exonerado' ? 'exonerado' : 'justificado';
            (new PushNotificationService())->enviarAPadre(
                $ep->padre,
                'Asignación ' . $etiqueta,
                "Fuiste {$etiqueta} de: {$evento->titulo}. Ya no debes asistir ni pagar por esta asignación.",
                ['tipo' => 'exoneracion', 'evento_id' => (string) $evento->id]
            );
        }

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
            'turno'        => 'nullable|integer|in:1,2',  // 1=entrada, 2=salida
            'es_reemplazo' => 'boolean',
            'anotacion'    => 'nullable|string|max:255',
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

        if (in_array($ep->estado, [EventoPadre::ESTADO_EXONERADO, EventoPadre::ESTADO_JUSTIFICADO])) {
            return response()->json(['message' => 'El padre está exonerado o justificado'], 422);
        }

        // ── Evento con turnos → actualizar turnos_estado ──────────────────────
        if ($evento->tiene_turnos && $request->filled('turno')) {
            $idx    = (int) $request->turno === 1 ? 0 : 1; // turno 1=entrada→idx 0, 2=salida→idx 1
            $estados = $ep->turnos_estado ?? [0, 0];

            if ($estados[$idx] === 1) {
                return response()->json(['message' => 'Este turno ya fue marcado'], 422);
            }

            $estados[$idx] = 1;

            $ep->update([
                'turnos_estado' => $estados,
                'estado'        => EventoPadre::ESTADO_PRESENTE, // al menos 1 turno → presente
                'hora_marcado'  => now(),
                'es_reemplazo'  => $request->boolean('es_reemplazo', false),
                'anotacion'     => $request->anotacion,
            ]);

            return response()->json([
                'message'       => 'Turno registrado correctamente',
                'padre'         => $ep->padre->nombre,
                'hora'          => $ep->hora_marcado,
                'turnos_estado' => $estados,
            ]);
        }

        // ── Sin turnos → comportamiento original ──────────────────────────────
        if ($ep->estado === EventoPadre::ESTADO_PRESENTE) {
            return response()->json(['message' => 'Asistencia ya registrada'], 422);
        }

        $ep->update([
            'estado'       => EventoPadre::ESTADO_PRESENTE,
            'hora_marcado' => now(),
            'es_reemplazo' => $request->boolean('es_reemplazo', false),
            'anotacion'    => $request->anotacion,
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
            'monto_pagado'       => 0,
        ]);

        if ($ep->padre) {
            (new PushNotificationService())->enviarAPadre(
                $ep->padre,
                'Asignación exonerado',
                "Fuiste exonerado de: {$evento->titulo}. Ya no debes asistir ni pagar por esta asignación.",
                ['tipo' => 'exoneracion', 'evento_id' => (string) $evento->id]
            );
        }

        return response()->json(['message' => 'Padre exonerado correctamente']);
    }

    // GET /api/eventos/{id}/padres
    public function padres(Evento $evento)
    {
        $padres = $evento->eventoPadres()
            ->with('padre')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($padres);
    }

    // GET /api/eventos/{evento}/ajustes
    public function ajustes(Evento $evento)
    {
        $pendientes = EventoPadre::where('evento_id', $evento->id)
            ->where('ajuste_resuelto', 0)
            ->with('padre:id,nombre,codigo')
            ->orderByDesc('created_at')
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

        $servicio = new CobroService();

        DB::transaction(function () use ($ep, $diferencia, $padre, $evento, $request, $servicio) {

            if ($diferencia > 0 && $request->filled('monto_adicional')) {
                $servicio->procesarCobroExtra($ep, (float) $request->monto_adicional, $request->user()->id, $evento, $padre);
            }

            if ($diferencia < 0) {
                $servicio->procesarDevolucion($ep, abs($diferencia), $request->user()->id, $evento, $padre);
            }

            // Si diferencia == 0 solo marcar resuelto (ya estaba sincronizado)
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
        $movimientos = Movimiento::where('evento_id', $evento->id)
            ->orderByDesc('fecha')
            ->get();

        // ── Guardia (Bapers): basado en multas, no en eventoPadres ────────────
        if ($evento->esGuardia()) {
            $multas = Multa::where('evento_id', $evento->id)
                ->with('padre:id,nombre,codigo,hijo,grado')
                ->get();

            $resultado = $multas->map(function ($multa) use ($movimientos) {
                $movsPadre = $movimientos->filter(function ($m) use ($multa) {
                    if ($m->abono_id) {
                        return Abono::where('id', $m->abono_id)
                            ->where('padre_id', $multa->padre_id)
                            ->where('tipo_deuda', 'multa')
                            ->where('deuda_id', $multa->id)
                            ->exists();
                    }
                    return false;
                });

                return [
                    'padre_id'        => $multa->padre_id,
                    'nombre'          => $multa->padre->nombre,
                    'codigo'          => $multa->padre->codigo,
                    'hijo'            => $multa->padre->hijo,
                    'grado'           => $multa->padre->grado,
                    'monto_asignado'  => (float) $multa->monto,
                    'monto_pagado'    => (float) ($multa->monto_pagado ?? 0),
                    'estado'          => $multa->estado, // 0=pend, 1=parcial, 2=pagado, 3=exonerado, 4=anulado
                    'ajuste_resuelto' => 1,
                    'diferencia'      => (float) $multa->monto - (float) ($multa->monto_pagado ?? 0),
                    'movimientos'     => $movsPadre->values()->map(fn($m) => [
                        'tipo'        => $m->tipo,
                        'monto'       => (float) $m->monto,
                        'descripcion' => $m->descripcion,
                        'categoria'   => $m->categoria,
                        'fecha'       => $m->fecha,
                        'created_at'  => $m->created_at,
                        'anulado'     => $m->abono_id
                            ? Abono::find($m->abono_id)?->estado === Abono::ESTADO_ANULADO
                            : false,
                    ]),
                ];
            });

            $gastos = Movimiento::where('evento_id', $evento->id)
                ->where('tipo', Movimiento::TIPO_EGRESO)
                ->whereNull('abono_id')
                ->with('registrador:id,name')
                ->orderByDesc('fecha')
                ->get()
                ->map(fn($m) => [
                    'id'             => $m->id,
                    'monto'          => (float) $m->monto,
                    'descripcion'    => $m->descripcion,
                    'categoria'      => $m->categoria,
                    'fecha'          => $m->fecha,
                    'registrado_por' => $m->registrador?->name,
                ]);

            return response()->json([
                'evento'           => array_merge([
                    'id'          => $evento->id,
                    'titulo'      => $evento->titulo,
                    'multa_monto' => (float) $evento->multa_monto,
                ], $evento->resumenGuardia()),
                'precio_historial' => [],
                'padres'           => $resultado,
                'gastos'           => $gastos,
            ]);
        }

        // ── Cuota / Actividad: basado en eventoPadres ─────────────────────────
        $eventoPadres = EventoPadre::where('evento_id', $evento->id)
            ->with('padre:id,nombre,codigo,hijo,grado')
            ->get();

        $resultado = $eventoPadres->map(function ($ep) use ($movimientos, $evento) {
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
                'monto_asignado'  => (float) ($ep->monto_asignado ?: ($evento->esCuota() ? $evento->multa_monto : 0)),
                'monto_pagado'    => (float) $ep->monto_pagado,
                'estado'          => $ep->estado,
                'ajuste_resuelto' => $ep->ajuste_resuelto,
                'diferencia'      => (float) ($ep->monto_asignado ?: ($evento->esCuota() ? $evento->multa_monto : 0)) - (float) $ep->monto_pagado,
                'movimientos' => $movsPadre->values()->map(fn($m) => [
                    'tipo'        => $m->tipo,
                    'monto'       => (float) $m->monto,
                    'descripcion' => $m->descripcion,
                    'categoria'   => $m->categoria,
                    'fecha'       => $m->fecha,
                    'created_at'  => $m->created_at,
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
            ->orderByDesc('fecha')
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

    // GET /api/eventos/{evento}/gastos  — egresos manuales (shared)
    public function gastos(Evento $evento)
    {
        $gastos = Movimiento::where('evento_id', $evento->id)
            ->where('tipo', Movimiento::TIPO_EGRESO)
            ->where('categoria', '!=', Movimiento::CAT_CUOTA)
            ->whereNull('abono_id')
            ->orderByDesc('fecha')
            ->get()
            ->map(fn($m) => [
                'id'          => $m->id,
                'monto'       => (float) $m->monto,
                'descripcion' => $m->descripcion,
                'fecha'       => $m->fecha,
                'comprobante' => $m->comprobante,
            ]);

        return response()->json($gastos);
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
            $this->crearFilasPadre($evento, (int) $padreId, null);
        }
    }

    /**
     * Asigna todos los padres (cobros y reuniones).
     */
    private function asignarTodosLosPadres(Evento $evento): void
    {
        foreach (Padre::all() as $padre) {
            $this->crearFilasPadre($evento, $padre->id, null);
        }
    }

    /**
     * Crea 1 fila en evento_padres.
     * Con turnos: turnos_estado = [0, 0] y monto completo.
     * Sin turnos: turnos_estado = null.
     */
    private function crearFilasPadre(Evento $evento, int $padreId, ?string $fecha): void
    {
        $montoBase = ($evento->esCuota() || $evento->esActividad())
            ? (float) $evento->multa_monto
            : ($evento->tiene_multa ? (float) $evento->multa_monto : null);

        $turnosEstado = $evento->tiene_turnos ? [0, 0] : null;

        $ep = EventoPadre::firstOrCreate(
            ['evento_id' => $evento->id, 'padre_id' => $padreId, 'fecha' => $fecha],
            ['estado' => EventoPadre::ESTADO_PENDIENTE, 'monto_asignado' => $montoBase, 'turnos_estado' => $turnosEstado]
        );

        if ($ep->wasRecentlyCreated) {
            $this->notificarAsignacion($evento, $ep);
        }
    }

    /**
     * Notifica al padre que fue asignado a un evento (cobro, guardia, faena, reunión, actividad).
     */
    private function notificarAsignacion(Evento $evento, EventoPadre $ep): void
    {
        $padre = $ep->padre ?? Padre::find($ep->padre_id);
        if (!$padre) return;

        $servicio = new PushNotificationService();

        if ($evento->esCuota()) {
            $servicio->enviarAPadre(
                $padre,
                'Nuevo cobro pendiente',
                "Tienes un nuevo cobro: {$evento->titulo} — S/ " . number_format((float) ($ep->monto_asignado ?? $evento->multa_monto), 2) . '.',
                ['tipo' => 'cobro', 'evento_id' => (string) $evento->id]
            );
            return;
        }

        if ($evento->esGuardia()) {
            $fechaTexto = $ep->fecha ? ' el ' . $ep->fecha->format('d/m/Y') : '';
            $servicio->enviarAPadre(
                $padre,
                'Te toca guardia',
                "Fuiste asignado a la guardia: {$evento->titulo}{$fechaTexto}.",
                ['tipo' => 'guardia', 'evento_id' => (string) $evento->id]
            );
            return;
        }

        $tipoTexto = match ($evento->tipo) {
            Evento::TIPO_FAENA => 'una faena',
            Evento::TIPO_REUNION => 'una reunión',
            Evento::TIPO_ACTIVIDAD => 'una actividad',
            default => 'un evento',
        };

        $servicio->enviarAPadre(
            $padre,
            'Nueva asignación',
            "Fuiste asignado a {$tipoTexto}: {$evento->titulo}.",
            ['tipo' => 'asignacion', 'evento_id' => (string) $evento->id]
        );
    }
}
