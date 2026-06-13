<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\EventoPadre;
use App\Models\Multa;
use App\Models\Padre;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PadreController extends Controller
{
    // GET /api/padres
    public function index(Request $request)
    {
        $padres = Padre::with('user')
            ->when(!$request->boolean('con_retirados'), fn($q) => $q->where('retirado', false))
            ->orderBy('nombre')
            ->get();

        return response()->json($padres);
    }

    // GET /api/padres/{id}
    public function show(Padre $padre)
    {
        return response()->json($padre->load('user', 'abonos', 'multas'));
    }

    // POST /api/padres
    public function store(Request $request)
    {
        $request->validate([
            'nombre'   => 'required|string|max:255',
            'hijo'     => 'required|string|max:255',
            'grado'    => 'required|string|max:50',
            'telefono' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
        ]);

        return DB::transaction(function () use ($request) {
            // Generar código correlativo PAD-0001
            $ultimo  = Padre::orderByDesc('id')->lockForUpdate()->first();
            $numero  = $ultimo ? ($ultimo->id + 1) : 1;
            $codigo  = 'PAD-' . str_pad($numero, 4, '0', STR_PAD_LEFT);

            $padre = Padre::create([
                'codigo'   => $codigo,
                'nombre'   => $request->nombre,
                'hijo'     => $request->hijo,
                'grado'    => $request->grado,
                'telefono' => $request->telefono,
            ]);

            $user = User::create([
                'name'      => $request->nombre,
                'username'  => $codigo,
                'password'  => Hash::make($request->password),
                'role'      => User::ROLE_PADRE,
                'padre_id'  => $padre->id,
            ]);

            // Auto-asignar a eventos de cuota activos
            Evento::where('tipo', Evento::TIPO_CUOTA)
                ->where('estado', 0) // 0 = activo, 1 = cerrado
                ->get()
                ->each(function ($evento) use ($padre) {
                    EventoPadre::firstOrCreate(
                        ['evento_id' => $evento->id, 'padre_id' => $padre->id, 'fecha' => null],
                        ['estado' => EventoPadre::ESTADO_PENDIENTE, 'monto_asignado' => $evento->multa_monto]
                    );
                });

            return response()->json([
                'message' => 'Padre registrado correctamente',
                'padre'   => $padre,
                'usuario' => [
                    'username' => $user->username,
                ],
            ], 201);
        });
    }

    // PUT /api/padres/{id}
    public function update(Request $request, Padre $padre)
    {
        $request->validate([
            'nombre'   => 'sometimes|string|max:255',
            'hijo'     => 'sometimes|string|max:255',
            'grado'    => 'sometimes|string|max:50',
            'telefono' => 'nullable|string|max:20',
        ]);

        $padre->update($request->only('nombre', 'hijo', 'grado', 'telefono'));

        // Sincronizar nombre en users también
        if ($request->has('nombre')) {
            $padre->user?->update(['name' => $request->nombre]);
        }

        return response()->json([
            'message' => 'Padre actualizado correctamente',
            'padre'   => $padre,
        ]);
    }

    // PUT /api/padres/{id}/retirar
    public function retirar(Padre $padre)
    {
        if ($padre->retirado) {
            return response()->json(['message' => 'El padre ya está retirado'], 422);
        }

        DB::transaction(function () use ($padre) {
            // Anular multas pendientes y parciales
            $padre->multas()
                ->whereIn('estado', [Multa::ESTADO_PENDIENTE, Multa::ESTADO_PARCIAL])
                ->update([
                    'estado'             => Multa::ESTADO_ANULADO,
                    'motivo_exoneracion' => 'Retiro del alumno',
                ]);

            // Exonerar cobros de eventos pendientes
            EventoPadre::where('padre_id', $padre->id)
                ->where('estado', EventoPadre::ESTADO_PENDIENTE)
                ->update([
                    'estado'             => EventoPadre::ESTADO_EXONERADO,
                    'motivo_exoneracion' => 'Retiro del alumno',
                ]);

            $padre->update([
                'retirado'     => true,
                'fecha_retiro' => now()->toDateString(),
            ]);
        });

        return response()->json(['message' => 'Padre retirado correctamente']);
    }

    // DELETE /api/padres/{id}
    public function destroy(Padre $padre)
    {
        DB::transaction(function () use ($padre) {
            $padre->user?->tokens()->delete();
            $padre->user?->delete();
            $padre->delete();
        });

        return response()->json(['message' => 'Padre eliminado correctamente']);
    }

    // POST /api/padres/importar
    public function importar(Request $request)
    {
        $request->validate([
            'archivo'          => 'required|file|mimes:csv,txt|max:5120',
            'password_default' => 'nullable|string|min:4',
        ]);

        $passwordDefault = $request->input('password_default', '12345678') ?: '12345678';

        $handle  = fopen($request->file('archivo')->getPathname(), 'r');
        $creados = 0;
        $errores = [];
        $fila    = 0;

        // Saltar encabezado
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            $fila++;

            $nombre        = trim($row[0] ?? '');
            $hijo          = trim($row[1] ?? '');
            $grado         = trim($row[2] ?? '');
            $telefono      = isset($row[3]) && $row[3] !== '' ? trim($row[3]) : null;
            $password      = isset($row[4]) && $row[4] !== '' ? trim($row[4]) : $passwordDefault;
            $codigoForzado = isset($row[5]) && $row[5] !== '' ? trim($row[5]) : null;

            if (!$nombre || !$hijo || !$grado) {
                $errores[] = "Fila {$fila}: nombre, hijo y grado son requeridos.";
                continue;
            }

            if ($codigoForzado && Padre::where('codigo', $codigoForzado)->exists()) {
                $errores[] = "Fila {$fila} ({$nombre}): el código {$codigoForzado} ya existe.";
                continue;
            }

            try {
                DB::transaction(function () use ($nombre, $hijo, $grado, $telefono, $password, $codigoForzado, &$creados) {
                    if ($codigoForzado) {
                        $codigo = $codigoForzado;
                    } else {
                        $ultimo = Padre::orderByDesc('id')->lockForUpdate()->first();
                        $numero = $ultimo ? ($ultimo->id + 1) : 1;
                        $codigo = 'PAD-' . str_pad($numero, 4, '0', STR_PAD_LEFT);
                    }

                    $padre = Padre::create([
                        'codigo'   => $codigo,
                        'nombre'   => $nombre,
                        'hijo'     => $hijo,
                        'grado'    => $grado,
                        'telefono' => $telefono,
                    ]);

                    User::create([
                        'name'     => $nombre,
                        'username' => $codigo,
                        'password' => Hash::make($password),
                        'role'     => User::ROLE_PADRE,
                        'padre_id' => $padre->id,
                    ]);

                    $creados++;
                });
            } catch (\Exception $e) {
                $errores[] = "Fila {$fila} ({$nombre}): " . $e->getMessage();
            }
        }

        fclose($handle);

        return response()->json(['creados' => $creados, 'errores' => $errores]);
    }

    // PUT /api/padres/{id}/reset-password
    public function resetPassword(Request $request, Padre $padre)
    {
        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $padre->user?->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['message' => 'Contraseña restablecida correctamente']);
    }

    // GET /api/padres/{id}/qr
    public function qr(Padre $padre)
    {
        return response()->json([
            'codigo'  => $padre->codigo,
            'qr_data' => $padre->qrData(),
        ]);
    }

    // ── Rutas de estado / perfil ──────────────────────────────────────────────

    // GET /mi-qr  (role:2)
    public function miQr(Request $request)
    {
        $padre = $request->user()->padre;
        if (!$padre) {
            return response()->json(['message' => 'Sin perfil de padre'], 404);
        }

        return response()->json([
            'codigo'  => $padre->codigo,
            'qr_data' => $padre->qrData(),
        ]);
    }

    // GET /mi-estado  (role:2)
    public function miEstado(Request $request)
    {
        $padre = $request->user()->padre;
        if (!$padre) {
            return response()->json(['message' => 'Sin perfil de padre'], 404);
        }

        // Solo cuotas pendientes de pago (tipo=3)
        $cobros = $padre->eventoPadres()
            ->where('estado', EventoPadre::ESTADO_PENDIENTE)
            ->whereHas('evento', fn($q) => $q->where('tipo', Evento::TIPO_CUOTA))
            ->whereColumn('monto_pagado', '<', 'monto_asignado')
            ->with('evento')
            ->get();

        // Asignaciones a eventos de asistencia (bapers, faenas, reuniones) aún activos (no cerrados)
        $asignaciones = $padre->eventoPadres()
            ->whereIn('estado', [EventoPadre::ESTADO_PENDIENTE])
            ->whereNotIn('estado', [EventoPadre::ESTADO_EXONERADO, EventoPadre::ESTADO_JUSTIFICADO])
            ->whereHas('evento', fn($q) => $q
                ->whereIn('tipo', [Evento::TIPO_GUARDIA, Evento::TIPO_FAENA, Evento::TIPO_REUNION, Evento::TIPO_ACTIVIDAD])
                ->where('estado', 0) // 0 = activo, 1 = cerrado
            )
            ->with('evento')
            ->get();

        return response()->json([
            'padre'       => $padre,
            'saldo_deuda' => $padre->saldoDeuda(),
            'multas'      => $padre->multas()->with('evento')->orderByDesc('fecha_generada')->get(),
            'abonos'      => $padre->abonos()->orderByDesc('fecha')->get()->map(function ($abono) {
                $montoNeto = null;
                if ($abono->tipo_deuda === 'multa') {
                    $multa = \App\Models\Multa::find($abono->deuda_id);
                    $montoNeto = $multa ? (float) $multa->monto_pagado : null;
                } elseif (in_array($abono->tipo_deuda, ['cobro', 'cuota'])) {
                    $ep = \App\Models\EventoPadre::find($abono->deuda_id);
                    $montoNeto = $ep ? (float) $ep->monto_pagado : null;
                }
                return array_merge($abono->toArray(), ['monto_neto' => $montoNeto]);
            }),
            'eventos'     => $padre->eventoPadres()->with('evento')->orderByDesc('created_at')->get(),
            'cobros'      => $cobros,
            'asignaciones' => $asignaciones,
        ]);
    }

    // POST /fcm-token  (role:2)
    // Guarda/actualiza el token de notificaciones push (FCM) del dispositivo
    // del padre autenticado, para poder enviarle avisos cuando la app esté cerrada.
    public function guardarFcmToken(Request $request)
    {
        $request->validate([
            'token'      => 'required|string|max:255',
            'plataforma' => 'nullable|string|max:20',
        ]);

        $padre = $request->user()->padre;
        if (!$padre) {
            return response()->json(['message' => 'Sin perfil de padre'], 404);
        }

        $padre->update([
            'fcm_token'    => $request->token,
            'fcm_platform' => $request->plataforma,
        ]);

        return response()->json(['message' => 'Token registrado correctamente']);
    }

    // GET /mi-estado-tesorero  (role:0)
    public function miEstadoTesorero(Request $request)
    {
        $padre = Padre::find($request->query('padre_id'));
        if (!$padre) {
            return response()->json(['message' => 'Padre no encontrado'], 404);
        }

        $cobros = $padre->eventoPadres()
            ->where('estado', 0)
            ->whereHas('evento', fn($q) => $q->where('tipo', 3))
            ->with('evento')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'multas' => $padre->multas()->whereIn('estado', [Multa::ESTADO_PENDIENTE, Multa::ESTADO_PARCIAL])->get(),
            'cobros' => $cobros,
        ]);
    }
}
