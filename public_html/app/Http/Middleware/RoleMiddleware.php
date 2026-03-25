<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Acepta roles como enteros o como nombres de cadena:
     *   middleware('role:0')               → solo tesorero (entero)
     *   middleware('role:tesorero')        → solo tesorero (nombre)
     *   middleware('role:tesorero,profesora') → tesorero o profesora
     *   middleware('role:0,1')             → tesorero o profesora
     */

    // Mapa de nombres → enteros (debe coincidir con User::ROLE_*)
    private const ROLE_MAP = [
        'tesorero'  => 0,
        'profesora' => 1,
        'padre'     => 2,
    ];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // \Log::info('ROLE_CHECK', [
        //     'uri'    => $request->getRequestUri(),
        //     'method' => $request->method(),
        //     'roles'  => $roles,
        //     'user_role' => $user?->role,
        //     'user_id'   => $user?->id,
        // ]);

        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        if (empty($roles)) {
            return $next($request);
        }

        // Normalizar cada rol a entero
        $rolesPermitidos = array_map(function ($rol) {
            $rol = trim($rol);
            // Si es numérico ya es un entero
            if (is_numeric($rol)) return (int) $rol;
            // Si es un nombre conocido, convertir
            return self::ROLE_MAP[$rol] ?? -1;
        }, $roles);

        if (!in_array($user->role, $rolesPermitidos)) {
            // \Log::info('ROLE_DENIED', [
            //     'user_role'       => $user->role,
            //     'roles_permitidos' => $rolesPermitidos,
            // ]);
            return response()->json(['message' => 'No tienes permiso para realizar esta acción'], 403);
        }

        return $next($request);
    }
}
