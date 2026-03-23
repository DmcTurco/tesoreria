<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\Multa;
use App\Models\Padre;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PadreController extends Controller
{
    // GET /api/padres
    public function index()
    {
        $padres = Padre::with('user')
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

        $cobros = $padre->eventoPadres()
            ->where('estado', 0)
            ->whereHas('evento', fn($q) => $q->where('tipo', Evento::TIPO_CUOTA))
            ->with('evento')
            ->get();

        return response()->json([
            'padre'       => $padre,
            'saldo_deuda' => $padre->saldoDeuda(),
            'multas'      => $padre->multas()->with('evento')->orderByDesc('fecha_generada')->get(),
            'abonos'      => $padre->abonos()->orderByDesc('fecha')->get(), // ← pagos → abonos
            'eventos'     => $padre->eventoPadres()->with('evento')->orderByDesc('created_at')->get(),
            'cobros'      => $cobros,
        ]);
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
            ->get();

        return response()->json([
            'multas' => $padre->multas()->whereIn('estado', [Multa::ESTADO_PENDIENTE, Multa::ESTADO_PARCIAL])->get(),
            'cobros' => $cobros,
        ]);
    }
}
