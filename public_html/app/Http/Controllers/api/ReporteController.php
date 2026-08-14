<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\EventoPadre;
use App\Models\Movimiento;
use App\Models\Multa;
use App\Models\Padre;
use App\Models\Evento;
use App\Models\Presupuesto;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    // GET /api/reportes/dashboard
    public function dashboard()
    {
        $anio = now()->year;
        $mes  = now()->month;

        // Caja: ingresos y egresos del mes actual
        $ingresosMes = Movimiento::where('tipo', Movimiento::TIPO_INGRESO)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->sum('monto');

        $egresosMes  = Movimiento::where('tipo', Movimiento::TIPO_EGRESO)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->sum('monto');

        // Saldo total histórico
        $totalIngresos = Movimiento::where('tipo', Movimiento::TIPO_INGRESO)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->sum('monto');
        $totalEgresos  = Movimiento::where('tipo', Movimiento::TIPO_EGRESO)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->sum('monto');

        // Multas pendientes — solo de padres activos
        $multasPendientes = Multa::where('estado', Multa::ESTADO_PENDIENTE)
            ->whereHas('padre', fn($q) => $q->where('retirado', false))
            ->sum('monto');
        $multasCount      = Multa::where('estado', Multa::ESTADO_PENDIENTE)
            ->whereHas('padre', fn($q) => $q->where('retirado', false))
            ->count();

        // Eventos activos
        $eventosActivos = Evento::where('estado', Evento::ESTADO_ACTIVO)->count();

        // Últimos movimientos
        $ultimosMovimientos = Movimiento::with('registrador')
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->orderByDesc('fecha')
            ->limit(5)
            ->get();

        return response()->json([
            'caja' => [
                'saldo_total'   => $totalIngresos - $totalEgresos,
                'ingresos_mes'  => $ingresosMes,
                'egresos_mes'   => $egresosMes,
                'saldo_mes'     => $ingresosMes - $egresosMes,
            ],
            'multas' => [
                'monto_pendiente' => $multasPendientes,
                'cantidad'        => $multasCount,
            ],
            'eventos_activos'     => $eventosActivos,
            'total_padres'        => Padre::where('retirado', false)->count(),
            'ultimos_movimientos' => $ultimosMovimientos,
        ]);
    }

    // ❌ deudores() eliminado → sin uso en la app; dependía de la tabla legacy `pagos`.
    //    El reporte de deudores usa /padres/con-deuda y /padres/{id}/deuda-detalle.

    // GET /api/reportes/movimientos-por-mes
    public function movimientosPorMes(Request $request)
    {
        $anio = $request->input('anio', now()->year);

        $data = Movimiento::whereYear('fecha', $anio)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->selectRaw('tipo, EXTRACT(MONTH FROM fecha)::int as mes, SUM(monto) as total')
            ->groupBy('tipo', 'mes')
            ->orderBy('mes')
            ->get();

        return response()->json($data);
    }

    // GET /api/reportes/deuda-matriz
    // Matriz de deuda: padres (filas) x eventos (columnas).
    // Misma lógica que Padre::saldoDeuda(): multas pendientes/parciales
    // + cuotas de eventos tipo CUOTA pendientes. Las multas sin evento
    // asociado se agrupan en una columna aparte "Multas (sin evento)".
    public function deudaMatriz(Request $request)
    {
        $padres = Padre::with(['multas.evento', 'eventoPadres.evento'])
            // Excluir retirados salvo que se pidan explícitamente (igual que /padres/con-deuda)
            ->when(!$request->boolean('con_retirados'), fn($q) => $q->where('retirado', false))
            ->get();

        $eventosMap = []; // key => ['key','id','titulo','fecha_inicio']
        $filas      = [];

        foreach ($padres as $padre) {
            $deudaPorEvento = []; // key evento => monto
            $total          = 0.0;

            // Multas pendientes o parciales
            foreach ($padre->multas as $multa) {
                if (!in_array($multa->estado, [Multa::ESTADO_PENDIENTE, Multa::ESTADO_PARCIAL])) {
                    continue;
                }
                $saldo = max(0, (float) $multa->monto - (float) ($multa->monto_pagado ?? 0));
                if ($saldo <= 0) {
                    continue;
                }

                if ($multa->evento) {
                    $key = 'evento_' . $multa->evento_id;
                    $eventosMap[$key] ??= [
                        'key'          => $key,
                        'id'           => $multa->evento_id,
                        'titulo'       => $multa->evento->titulo,
                        'fecha_inicio' => optional($multa->evento->fecha_inicio)->format('Y-m-d'),
                    ];
                } else {
                    $key = 'multas_sin_evento';
                    $eventosMap[$key] ??= [
                        'key'          => $key,
                        'id'           => null,
                        'titulo'       => 'Multas (sin evento)',
                        'fecha_inicio' => null,
                    ];
                }

                $deudaPorEvento[$key] = ($deudaPorEvento[$key] ?? 0) + $saldo;
                $total += $saldo;
            }

            // Cobros de eventos tipo CUOTA pendientes
            foreach ($padre->eventoPadres as $ep) {
                if ($ep->estado !== EventoPadre::ESTADO_PENDIENTE) {
                    continue;
                }
                if (!$ep->evento || $ep->evento->tipo !== Evento::TIPO_CUOTA) {
                    continue;
                }
                $saldo = (float) $ep->saldo_pendiente;
                if ($saldo <= 0) {
                    continue;
                }

                $key = 'evento_' . $ep->evento_id;
                $eventosMap[$key] ??= [
                    'key'          => $key,
                    'id'           => $ep->evento_id,
                    'titulo'       => $ep->evento->titulo,
                    'fecha_inicio' => optional($ep->evento->fecha_inicio)->format('Y-m-d'),
                ];

                $deudaPorEvento[$key] = ($deudaPorEvento[$key] ?? 0) + $saldo;
                $total += $saldo;
            }

            if ($total <= 0) {
                continue;
            }

            $filas[] = [
                'id'       => $padre->id,
                'codigo'   => $padre->codigo,
                'nombre'   => $padre->nombre,
                'hijo'     => $padre->hijo,
                'retirado' => (bool) $padre->retirado,
                'deuda'    => $deudaPorEvento, // key evento => monto
                'total'    => round($total, 2),
            ];
        }

        // Eventos ordenados por fecha (sin fecha, ej. "Multas sin evento", al final)
        $eventos = collect($eventosMap)
            ->sortBy(fn($e) => $e['fecha_inicio'] ?? '9999-99-99')
            ->values();

        usort($filas, fn($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'eventos' => $eventos,
            'padres'  => $filas,
        ]);
    }

    // GET /api/reportes/retirados-pendientes
    // Eventos/cuotas con asignación pendiente de un padre ya retirado
    // (situación que no debería ocurrir: el evento "debería evitar" a
    // los retirados). Marca si se puede limpiar directo o si tiene
    // pagos y requiere revisión manual — mismo criterio que se usó
    // para depurar el caso detectado en fix_retirados_eventos.sql.
    public function retiradosPendientes()
    {
        $items = EventoPadre::with(['evento', 'padre'])
            ->where('estado', EventoPadre::ESTADO_PENDIENTE)
            ->whereHas('padre', fn($q) => $q->where('retirado', true))
            ->get()
            ->map(function ($ep) {
                $tienePagos       = (float) $ep->monto_pagado > 0;
                $tieneAbonoActivo = Abono::where('tipo_deuda', Abono::TIPO_COBRO)
                    ->where('deuda_id', $ep->id)
                    ->where('estado', Abono::ESTADO_ACTIVO)
                    ->exists();

                return [
                    'evento_padre_id' => $ep->id,
                    'evento_id'       => $ep->evento_id,
                    'evento_titulo'   => optional($ep->evento)->titulo,
                    'evento_fecha'    => optional($ep->evento?->fecha_inicio)->format('Y-m-d'),
                    'padre_id'        => $ep->padre_id,
                    'padre_codigo'    => optional($ep->padre)->codigo,
                    'padre_nombre'    => optional($ep->padre)->nombre,
                    'fecha_retiro'    => optional($ep->padre?->fecha_retiro)->format('Y-m-d'),
                    'monto_asignado'  => (float) $ep->monto_asignado,
                    'monto_pagado'    => (float) $ep->monto_pagado,
                    'accion_sugerida' => (!$tienePagos && !$tieneAbonoActivo) ? 'eliminar_sin_riesgo' : 'revisar',
                ];
            })
            ->sortByDesc('evento_fecha')
            ->values();

        return response()->json($items);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/reportes/balance?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
    //
    // Balance de un periodo (semestral, anual o el rango que se pida), pensado
    // para presentarlo en asamblea. Devuelve todo lo que necesita el PDF:
    //
    //   resumen        → saldo inicial + ingresos - egresos = saldo final
    //   por_categoria  → desglose de ingresos y egresos
    //   por_mes        → evolución mes a mes (sin huecos)
    //   por_evento     → cuánto recaudó y cuánto gastó cada evento
    //   presupuesto    → lo planificado en el periodo vs. el egreso real
    //   deuda          → dinero por cobrar (informativo, NO suma a caja)
    //   movimientos    → detalle cronológico para el anexo
    //
    // Nota sobre anulaciones: al anular un abono el sistema marca con
    // CAT_ANULACION tanto el movimiento original como su contra-asiento, así
    // que excluir esa categoría saca los dos y la caja queda cuadrada.
    // Los movimientos sin categoría sí se cuentan (son dinero real) y se
    // reportan aparte en `advertencias` para que no pasen desapercibidos.
    // ─────────────────────────────────────────────────────────────────────────
    public function balance(Request $request)
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);

        $desde = Carbon::parse($request->input('desde'))->startOfDay();
        $hasta = Carbon::parse($request->input('hasta'))->startOfDay();

        // Query base reutilizable: fuera anulaciones, dentro lo que no tiene categoría.
        $base = fn() => Movimiento::where(function ($q) {
            $q->where('categoria', '!=', Movimiento::CAT_ANULACION)
              ->orWhereNull('categoria');
        });

        // ── 1. Saldo inicial: todo lo registrado ANTES del inicio del periodo ──
        $ingresosPrevios = (float) $base()
            ->where('tipo', Movimiento::TIPO_INGRESO)
            ->whereDate('fecha', '<', $desde->toDateString())
            ->sum('monto');
        $egresosPrevios = (float) $base()
            ->where('tipo', Movimiento::TIPO_EGRESO)
            ->whereDate('fecha', '<', $desde->toDateString())
            ->sum('monto');
        $saldoInicial = $ingresosPrevios - $egresosPrevios;

        // ── 2. Movimientos del periodo ────────────────────────────────────────
        $movs = $base()
            ->with('registrador:id,name')
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $ingresos = (float) $movs->where('tipo', Movimiento::TIPO_INGRESO)->sum('monto');
        $egresos  = (float) $movs->where('tipo', Movimiento::TIPO_EGRESO)->sum('monto');

        // ── 3. Desglose por origen ────────────────────────────────────────────
        // La columna `categoria` no sirve sola para un balance: TODO pago de un
        // padre se guarda como CAT_ABONO, sea cuota de evento o multa por
        // inasistencia, así que agruparla dejaría el 99% del dinero en una sola
        // fila llamada "Pagos de padres". Lo que de verdad distingue el origen
        // está en el abono asociado (`abonos.tipo_deuda`: 'cobro' o 'multa'),
        // así que se usa eso y la categoría queda como respaldo para los
        // movimientos registrados a mano.
        $etiquetas = [
            Movimiento::CAT_ABONO  => 'Pagos de padres',
            Movimiento::CAT_EVENTO => 'Eventos y actividades',
            Movimiento::CAT_CUOTA  => 'Cuotas registradas a mano',
            Movimiento::CAT_OTRO   => 'Otros',
        ];

        $tipoDeudaPorAbono = Abono::whereIn('id', $movs->pluck('abono_id')->filter()->unique()->all())
            ->pluck('tipo_deuda', 'id');

        $origen = function ($m) use ($tipoDeudaPorAbono, $etiquetas) {
            if ($m->abono_id && $tipoDeudaPorAbono->has($m->abono_id)) {
                return $tipoDeudaPorAbono[$m->abono_id] === Abono::TIPO_MULTA
                    ? 'Multas por inasistencia'
                    : 'Cuotas y actividades';
            }
            if ($m->categoria === null) {
                return 'Sin categoría';
            }
            return $etiquetas[(int) $m->categoria] ?? "Categoría {$m->categoria}";
        };

        $agruparPorOrigen = function (int $tipo) use ($movs, $origen) {
            return $movs->where('tipo', $tipo)
                ->groupBy($origen)
                ->map(fn($grupo, $label) => [
                    'label'    => $label,
                    'monto'    => round((float) $grupo->sum('monto'), 2),
                    'cantidad' => $grupo->count(),
                ])
                ->sortByDesc('monto')
                ->values();
        };

        // ── 4. Evolución mes a mes (incluye meses en cero) ────────────────────
        $meses  = [];
        $cursor = $desde->copy()->startOfMonth();
        $ultimo = $hasta->copy()->startOfMonth();
        while ($cursor->lessThanOrEqualTo($ultimo)) {
            $anio = $cursor->year;
            $mes  = $cursor->month;
            $delMes = $movs->filter(
                fn($m) => $m->fecha->year === $anio && $m->fecha->month === $mes
            );
            $i = (float) $delMes->where('tipo', Movimiento::TIPO_INGRESO)->sum('monto');
            $e = (float) $delMes->where('tipo', Movimiento::TIPO_EGRESO)->sum('monto');

            $meses[] = [
                'anio'     => $anio,
                'mes'      => $mes,
                'label'    => ucfirst($cursor->locale('es')->isoFormat('MMMM YYYY')),
                'ingresos' => round($i, 2),
                'egresos'  => round($e, 2),
                'neto'     => round($i - $e, 2),
            ];
            $cursor->addMonth();
        }

        // ── 5. Resultado por evento ───────────────────────────────────────────
        // Para cada evento con movimientos en el periodo se reporta, además de
        // la plata que entró y salió: cuántos padres ya pagaron, cuántos siguen
        // debiendo y en qué se gastó. La deuda se calcula con el mismo criterio
        // que Padre::saldoDeuda(): en eventos tipo CUOTA sale de la asignación
        // (evento_padres) y en los demás tipos, de las multas generadas.
        $conEvento  = $movs->filter(fn($m) => $m->evento_id !== null);
        $eventoIds  = $conEvento->pluck('evento_id')->unique()->values()->all();
        $eventosMap = Evento::whereIn('id', $eventoIds)->get()->keyBy('id');
        $tipoLabel  = [
            Evento::TIPO_GUARDIA   => 'Guardia',
            Evento::TIPO_FAENA     => 'Faena',
            Evento::TIPO_REUNION   => 'Reunión',
            Evento::TIPO_CUOTA     => 'Cuota',
            Evento::TIPO_ACTIVIDAD => 'Actividad',
        ];

        $asignacionesPorEvento = EventoPadre::with('evento')
            ->whereIn('evento_id', $eventoIds)
            ->get()
            ->groupBy('evento_id');

        $multasPorEvento = Multa::whereIn('evento_id', $eventoIds)
            ->get()
            ->groupBy('evento_id');

        $porEvento = $conEvento
            ->groupBy('evento_id')
            ->map(function ($grupo, $eventoId) use (
                $eventosMap, $tipoLabel, $asignacionesPorEvento, $multasPorEvento, $hasta
            ) {
                $eventoId  = (int) $eventoId;
                $evento    = $eventosMap->get($eventoId);
                $recaudado = (float) $grupo->where('tipo', Movimiento::TIPO_INGRESO)->sum('monto');
                $gastado   = (float) $grupo->where('tipo', Movimiento::TIPO_EGRESO)->sum('monto');

                $asigs  = $asignacionesPorEvento->get($eventoId, collect());
                $multas = $multasPorEvento->get($eventoId, collect());

                // Quien está exonerado o justificado no genera cobro
                $exonerados = $asigs
                    ->whereIn('estado', [EventoPadre::ESTADO_EXONERADO, EventoPadre::ESTADO_JUSTIFICADO])
                    ->count();

                $esCuota = $evento && $evento->tipo === Evento::TIPO_CUOTA;

                if ($esCuota) {
                    $cobrables = $asigs->reject(fn($ep) => in_array(
                        $ep->estado,
                        [EventoPadre::ESTADO_EXONERADO, EventoPadre::ESTADO_JUSTIFICADO],
                        true
                    ));
                    $pagaron   = $cobrables->filter(fn($ep) => $ep->saldo_pendiente <= 0.004
                        && (float) $ep->monto_pagado > 0)->count();
                    $parciales = $cobrables->filter(fn($ep) => $ep->saldo_pendiente > 0.004
                        && (float) $ep->monto_pagado > 0)->count();
                    $deben     = $cobrables->filter(fn($ep) => $ep->saldo_pendiente > 0.004)->count();
                    $porCobrar = (float) $cobrables->sum(fn($ep) => $ep->saldo_pendiente);
                    $baseDeuda = 'cuota';
                    $cobrablesTotal = $cobrables->count();
                } else {
                    $vigentes  = $multas->whereNotIn('estado', [Multa::ESTADO_EXONERADO, Multa::ESTADO_ANULADO]);
                    $saldo     = fn($m) => max(0, (float) $m->monto - (float) ($m->monto_pagado ?? 0));
                    $pagaron   = $vigentes->filter(fn($m) => $saldo($m) <= 0.004)->count();
                    $parciales = $vigentes->where('estado', Multa::ESTADO_PARCIAL)->count();
                    $deben     = $vigentes->filter(fn($m) => $saldo($m) > 0.004)->count();
                    $porCobrar = (float) $vigentes->sum($saldo);
                    $baseDeuda = 'multa';
                    $cobrablesTotal = $vigentes->count();
                }

                // Detalle de en qué se gastó (egresos del evento dentro del periodo)
                $gastos = $grupo->where('tipo', Movimiento::TIPO_EGRESO)
                    ->sortBy('fecha')
                    ->map(fn($m) => [
                        'fecha'       => $m->fecha->format('Y-m-d'),
                        'descripcion' => $m->descripcion ?? '—',
                        'monto'       => round((float) $m->monto, 2),
                        'comprobante' => $m->comprobante,
                    ])
                    ->values();

                // Actividad posterior al cierre del balance: aparece porque alguien
                // ya adelantó su cuota, pero lo que "falta" no es deuda todavía.
                $futura = $evento && $evento->fecha_inicio
                    && $evento->fecha_inicio->greaterThan($hasta);

                return [
                    'evento_id'   => $eventoId,
                    'titulo'      => $evento->titulo ?? "Evento #{$eventoId}",
                    'tipo'        => $evento ? ($tipoLabel[$evento->tipo] ?? '—') : '—',
                    'futura'      => $futura,
                    'fecha'       => $evento && $evento->fecha_inicio
                        ? $evento->fecha_inicio->format('Y-m-d')
                        : null,
                    'mes_evento'  => $evento && $evento->fecha_inicio
                        ? ucfirst($evento->fecha_inicio->copy()->locale('es')->isoFormat('MMM YYYY'))
                        : '—',
                    'cuota'       => $evento ? round((float) $evento->multa_monto, 2) : null,
                    'recaudado'   => round($recaudado, 2),
                    'gastado'     => round($gastado, 2),
                    'neto'        => round($recaudado - $gastado, 2),
                    // Estado de cobranza
                    'base_deuda'  => $baseDeuda,
                    'asignados'   => $asigs->count(),
                    'cobrables'   => $cobrablesTotal,
                    'pagaron'     => $pagaron,
                    'parciales'   => $parciales,
                    'deben'       => $deben,
                    'exonerados'  => $exonerados,
                    'por_cobrar'  => round($porCobrar, 2),
                    'gastos'      => $gastos,
                ];
            })
            // Orden cronológico ascendente; los que no tienen fecha, al final
            ->sortBy(fn($e) => $e['fecha'] ?? '9999-12-31')
            ->values();

        $sinEvento = $movs->filter(fn($m) => $m->evento_id === null);

        // ── 5b. Atribución al mes del evento ──────────────────────────────────
        // El dinero entra a caja cuando se cobra, y eso casi nunca coincide con
        // el mes del evento (una cuota de marzo suele pagarse en abril). Esta
        // vista reubica cada movimiento en el mes del evento al que pertenece,
        // para poder explicar en asamblea por qué un mes aparece sin ingresos.
        // Los movimientos sin evento se quedan en su mes de caja.
        $atribuido = [];
        $fuera     = ['ingresos' => 0.0, 'egresos' => 0.0];
        $clavesMes = collect($meses)->mapWithKeys(
            fn($m, $i) => [sprintf('%04d-%02d', $m['anio'], $m['mes']) => $i]
        );

        foreach ($movs as $m) {
            $evento = $m->evento_id ? $eventosMap->get((int) $m->evento_id) : null;
            $ref    = ($evento && $evento->fecha_inicio)
                ? $evento->fecha_inicio->copy()
                : $m->fecha->copy();
            $clave  = $ref->format('Y-m');
            $campo  = $m->tipo == Movimiento::TIPO_INGRESO ? 'ingresos' : 'egresos';

            if ($clavesMes->has($clave)) {
                $atribuido[$clave][$campo] = ($atribuido[$clave][$campo] ?? 0) + (float) $m->monto;
            } else {
                $fuera[$campo] += (float) $m->monto;
            }
        }

        foreach ($meses as $i => $fila) {
            $clave = sprintf('%04d-%02d', $fila['anio'], $fila['mes']);
            $ai = round($atribuido[$clave]['ingresos'] ?? 0, 2);
            $ae = round($atribuido[$clave]['egresos']  ?? 0, 2);
            $meses[$i]['ingresos_evento'] = $ai;
            $meses[$i]['egresos_evento']  = $ae;
            $meses[$i]['neto_evento']     = round($ai - $ae, 2);
        }

        $atribucion = [
            'fuera_del_periodo' => [
                'ingresos' => round($fuera['ingresos'], 2),
                'egresos'  => round($fuera['egresos'], 2),
            ],
            'nota' => 'Columna "caja" = mes en que entró o salió el dinero (es el saldo real). '
                . 'Columna "evento" = mismo dinero atribuido al mes del evento que lo originó. '
                . 'Los totales de ambas columnas coinciden salvo lo que cae fuera del periodo.',
        ];

        // ── 6. Presupuesto del periodo ────────────────────────────────────────
        // Ojo: `presupuestos.categoria` es texto libre y no se corresponde con
        // las categorías numéricas de caja, así que se compara el total
        // planificado contra el egreso real, sin cruzar categoría por categoría.
        $presupuestos = Presupuesto::query()
            ->where(function ($q) use ($desde, $hasta) {
                $q->whereBetween('anio', [$desde->year, $hasta->year]);
            })
            ->get()
            ->filter(function ($p) use ($desde, $hasta) {
                if ($p->mes === null) {                       // presupuesto anual
                    return $p->anio >= $desde->year && $p->anio <= $hasta->year;
                }
                $ref = Carbon::create($p->anio, $p->mes, 1)->startOfDay();
                return $ref->greaterThanOrEqualTo($desde->copy()->startOfMonth())
                    && $ref->lessThanOrEqualTo($hasta->copy()->startOfMonth());
            })
            ->map(fn($p) => [
                'anio'        => $p->anio,
                'mes'         => $p->mes,
                'categoria'   => $p->categoria,
                'descripcion' => $p->descripcion,
                'planificado' => round((float) $p->monto_planificado, 2),
            ])
            ->sortBy([['anio', false], ['mes', false]])
            ->values();

        // ── 6b. Multas por inasistencia ───────────────────────────────────────
        // Van aparte porque no son una cuota: se generan al cerrar un evento
        // cuando el padre no asistió. Lo cobrado ya está dentro de los ingresos
        // (llega como abono); acá se muestra el estado del padrón de multas.
        $todasMultas = Multa::whereHas('padre', fn($q) => $q->where('retirado', false))->get();
        $saldoMulta  = fn($m) => max(0, (float) $m->monto - (float) ($m->monto_pagado ?? 0));

        $cobradoMultasPeriodo = (float) $movs
            ->where('tipo', Movimiento::TIPO_INGRESO)
            ->filter(fn($m) => $m->abono_id
                && ($tipoDeudaPorAbono[$m->abono_id] ?? null) === Abono::TIPO_MULTA)
            ->sum('monto');

        $pendientes = $todasMultas->whereIn('estado', [Multa::ESTADO_PENDIENTE, Multa::ESTADO_PARCIAL]);
        $exoneradas = $todasMultas->where('estado', Multa::ESTADO_EXONERADO);
        $anuladas   = $todasMultas->where('estado', Multa::ESTADO_ANULADO);
        $pagadas    = $todasMultas->where('estado', Multa::ESTADO_PAGADO);

        $resumenMultas = [
            'cobrado_en_periodo' => round($cobradoMultasPeriodo, 2),
            'pagadas'    => ['cantidad' => $pagadas->count(),    'monto' => round((float) $pagadas->sum('monto'), 2)],
            'pendientes' => ['cantidad' => $pendientes->count(), 'monto' => round((float) $pendientes->sum($saldoMulta), 2)],
            'exoneradas' => ['cantidad' => $exoneradas->count(), 'monto' => round((float) $exoneradas->sum('monto'), 2)],
            'anuladas'   => ['cantidad' => $anuladas->count(),   'monto' => round((float) $anuladas->sum('monto'), 2)],
            'nota' => 'Lo cobrado ya está contado dentro de los ingresos del periodo. '
                . 'Lo pendiente forma parte de las cuentas por cobrar, no del saldo de caja.',
        ];

        // ── 7. Deuda pendiente (informativa, a la fecha de emisión) ───────────
        $padresConDeuda = Padre::with(['multas', 'eventoPadres.evento'])
            ->where('retirado', false)
            ->get()
            ->map(function ($p) use ($hasta) {
                // Mismo criterio que Padre::saldoDeuda(), pero separando el origen
                $deMultas = (float) $p->multas
                    ->whereIn('estado', [Multa::ESTADO_PENDIENTE, Multa::ESTADO_PARCIAL])
                    ->sum(fn($m) => max(0, (float) $m->monto - (float) ($m->monto_pagado ?? 0)));

                $cuotasPendientes = $p->eventoPadres
                    ->where('estado', EventoPadre::ESTADO_PENDIENTE)
                    ->filter(fn($ep) => $ep->evento && $ep->evento->tipo === Evento::TIPO_CUOTA);

                // Una cuota de una actividad que todavía no ocurre no es una
                // deuda: la familia no está atrasada, el evento aún no llega.
                // Mezclarlas infla el "por cobrar" y señala injustamente a los
                // padres en la asamblea, así que van en columnas distintas.
                $deCuotas = (float) $cuotasPendientes
                    ->filter(fn($ep) => $ep->evento->fecha_inicio
                        && $ep->evento->fecha_inicio->lessThanOrEqualTo($hasta))
                    ->sum(fn($ep) => $ep->saldo_pendiente);

                $porVencer = (float) $cuotasPendientes
                    ->filter(fn($ep) => $ep->evento->fecha_inicio
                        && $ep->evento->fecha_inicio->greaterThan($hasta))
                    ->sum(fn($ep) => $ep->saldo_pendiente);

                return [
                    'id'         => $p->id,
                    'codigo'     => $p->codigo,
                    'nombre'     => $p->nombre,
                    'hijo'       => $p->hijo,
                    'cuotas'     => round($deCuotas, 2),
                    'multas'     => round($deMultas, 2),
                    'total'      => round($deCuotas + $deMultas, 2),
                    'por_vencer' => round($porVencer, 2),
                ];
            })
            ->filter(fn($r) => $r['total'] > 0.004 || $r['por_vencer'] > 0.004)
            ->sortByDesc('total')
            ->values();

        // ── 8. Advertencias para la revisión ──────────────────────────────────
        $egresosSinComprobante = $movs
            ->where('tipo', Movimiento::TIPO_EGRESO)
            ->filter(fn($m) => blank($m->comprobante));
        $sinCategoria = $movs->filter(fn($m) => $m->categoria === null);

        return response()->json([
            'periodo' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'label' => ucfirst($desde->locale('es')->isoFormat('MMMM YYYY'))
                    . ' – ' . ucfirst($hasta->locale('es')->isoFormat('MMMM YYYY')),
            ],
            'resumen' => [
                'saldo_inicial' => round($saldoInicial, 2),
                'ingresos'      => round($ingresos, 2),
                'egresos'       => round($egresos, 2),
                'resultado'     => round($ingresos - $egresos, 2),
                'saldo_final'   => round($saldoInicial + $ingresos - $egresos, 2),
                'n_movimientos' => $movs->count(),
            ],
            'ingresos_por_categoria' => $agruparPorOrigen(Movimiento::TIPO_INGRESO),
            'egresos_por_categoria'  => $agruparPorOrigen(Movimiento::TIPO_EGRESO),
            'multas'                 => $resumenMultas,
            'por_mes'    => $meses,
            'atribucion' => $atribucion,
            'por_evento' => $porEvento,
            'sin_evento' => [
                'ingresos' => round((float) $sinEvento->where('tipo', Movimiento::TIPO_INGRESO)->sum('monto'), 2),
                'egresos'  => round((float) $sinEvento->where('tipo', Movimiento::TIPO_EGRESO)->sum('monto'), 2),
            ],
            'presupuesto' => [
                'items'                => $presupuestos,
                'planificado_total'    => round((float) $presupuestos->sum('planificado'), 2),
                'ejecutado_total'      => round($egresos, 2),
                'nota'                 => 'Las categorías del presupuesto son texto libre y no se cruzan automáticamente con las categorías de caja: la comparación es a nivel de total.',
            ],
            'deuda' => [
                'total'          => round((float) $padresConDeuda->sum('total'), 2),
                'total_cuotas'   => round((float) $padresConDeuda->sum('cuotas'), 2),
                'total_multas'   => round((float) $padresConDeuda->sum('multas'), 2),
                // Cuotas de actividades posteriores al cierre: no son deuda todavía
                'total_por_vencer' => round((float) $padresConDeuda->sum('por_vencer'), 2),
                'padres'         => $padresConDeuda->filter(fn($r) => $r['total'] > 0.004)->count(),
                'detalle'        => $padresConDeuda,
                'fecha_corte'    => now()->toDateString(),
                'nota'           => 'Dinero por cobrar a la fecha de emisión. No forma parte del saldo de caja.',
                'nota_por_vencer' => 'Las cuotas de actividades que aún no ocurren se muestran aparte: '
                    . 'todavía no son deuda de las familias.',
            ],
            'advertencias' => [
                'egresos_sin_comprobante' => $egresosSinComprobante->count(),
                'monto_sin_comprobante'   => round((float) $egresosSinComprobante->sum('monto'), 2),
                'movimientos_sin_categoria' => $sinCategoria->count(),
            ],
            'movimientos' => $movs->map(fn($m) => [
                'id'             => $m->id,
                'fecha'          => $m->fecha->format('Y-m-d'),
                'tipo'           => (int) $m->tipo,
                'monto'          => round((float) $m->monto, 2),
                'descripcion'    => $m->descripcion,
                'categoria'      => $m->categoria,
                'categoria_label'=> $m->categoria === null
                    ? 'Sin categoría'
                    : ($etiquetas[(int) $m->categoria] ?? "Categoría {$m->categoria}"),
                'comprobante'    => $m->comprobante,
                'evento_id'      => $m->evento_id,
                'registrado_por' => optional($m->registrador)->name,
            ])->values(),
        ]);
    }
}
