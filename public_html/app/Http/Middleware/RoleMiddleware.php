<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Uso en rutas:
     *   middleware('role:0')     → solo tesorero
     *   middleware('role:0,1')   → tesorero y profesora
     *   middleware('role:2')     → solo padre
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // Convertir los roles permitidos a enteros y verificar
        $rolesPermitidos = array_map('intval', $roles);

        if (!in_array($user->role, $rolesPermitidos)) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción'], 403);
        }

        return $next($request);
    }
}
