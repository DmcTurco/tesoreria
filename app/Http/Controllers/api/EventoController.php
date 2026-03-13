<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\EventoPadre;
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
            ->get();

        return response()->json($eventos);
    }

    // GET /api/eventos/{id}
    public function show(Evento $evento)
    {
        return response()->json(
            $evento->load('creador', 'eventoPadres.padre')
        );
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
            'hora_fin'       => 'nullable|date_format:H:i|after:hora_inicio',
            'dias_semana'    => 'nullable|array',
            'dias_semana.*'  => 'integer|between:1,7',
            'padres_por_dia' => 'nullable|integer|min:1',
            'lugar'          => 'nullable|string|max:255',
            'tiene_multa'    => 'boolean',
            'multa_monto'    => 'nullable|numeric|min:0',
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

            // Cobro → asignar TODOS los padres automáticamente
            if ($evento->esCobro()) {
                $this->asignarTodosLosPadres($evento);
            }

            // Guardia → generar rotación de asignaciones por fecha
            if ($evento->esGuardia()) {
                $this->generarRotacionGuardia($evento);
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

        $evento->update($request->only(
            'titulo',
            'descripcion',
            'lugar',
            'tiene_multa',
            'multa_monto'
        ));

        return response()->json([
            'message' => 'Evento actualizado correctamente',
            'evento'  => $evento,
        ]);
    }

    // POST /api/eventos/{id}/cerrar
    public function cerrar(Request $request, Evento $evento)
    {
        if ($evento->estado === Evento::ESTADO_CERRADO) {
            return response()->json(['message' => 'El evento ya está cerrado'], 422);
        }

        DB::transaction(function () use ($evento, $request) {
            $fecha = $request->input('fecha', now()->toDateString());

            // Marcar ausentes: pendientes del día
            $query = $evento->eventoPadres()
                ->where('estado', EventoPadre::ESTADO_PENDIENTE);

            // Para guardias filtrar por fecha del día
            if ($evento->esGuardia()) {
                $query->where('fecha', $fecha);
            }

            $query->update(['estado' => EventoPadre::ESTADO_AUSENTE]);

            // Generar multas si aplica
            $generadas = $evento->aplicarMultasAusentes(
                $evento->esGuardia() ? $fecha : null
            );

            // Solo cerrar el evento si no es guardia (la guardia cierra al vencer fecha_fin)
            if (!$evento->esGuardia()) {
                $evento->update(['estado' => Evento::ESTADO_CERRADO]);
            }
        });

        return response()->json(['message' => 'Evento cerrado y multas generadas correctamente']);
    }

    // POST /api/eventos/{id}/asistencia  ← escaneo QR
    public function registrarAsistencia(Request $request, Evento $evento)
    {
        $request->validate([
            'padre_id' => 'required|integer|exists:padres,id',
            'fecha'    => 'nullable|date',
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

    // ── Métodos privados ──────────────────────────────────────────────────────

    /**
     * Asigna todos los padres al evento (usado en cobros).
     */
    private function asignarTodosLosPadres(Evento $evento): void
    {
        $padres = Padre::all();

        foreach ($padres as $padre) {
            EventoPadre::create([
                'evento_id' => $evento->id,
                'padre_id'  => $padre->id,
                'fecha'     => null,
                'estado'    => EventoPadre::ESTADO_PENDIENTE,
            ]);
        }
    }

    /**
     * Genera la rotación de guardias para todo el rango de fechas.
     * Distribuye los padres en grupos de $padres_por_dia rotando
     * por los días de la semana configurados.
     */
    private function generarRotacionGuardia(Evento $evento): void
    {
        if (!$evento->fecha_fin || !$evento->dias_semana || !$evento->padres_por_dia) {
            return;
        }

        $padres      = Padre::inRandomOrder()->get();
        $totalPadres = $padres->count();

        if ($totalPadres === 0) return;

        $diasSemana  = $evento->dias_semana; // [1,2,3,4,5]
        $porDia      = $evento->padres_por_dia;
        $indice      = 0; // índice rotativo de padres

        $fecha = Carbon::parse($evento->fecha_inicio);
        $fin   = Carbon::parse($evento->fecha_fin);

        while ($fecha->lte($fin)) {
            // Solo procesar si el día de la semana está en la config (1=lun, 7=dom)
            if (in_array($fecha->dayOfWeekIso, $diasSemana)) {
                for ($i = 0; $i < $porDia; $i++) {
                    $padre = $padres[$indice % $totalPadres];

                    EventoPadre::create([
                        'evento_id' => $evento->id,
                        'padre_id'  => $padre->id,
                        'fecha'     => $fecha->toDateString(),
                        'estado'    => EventoPadre::ESTADO_PENDIENTE,
                    ]);

                    $indice++;
                }
            }

            $fecha->addDay();
        }
    }
}
