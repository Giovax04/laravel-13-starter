<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationIsEnabled
{
    /**
     * El registro público solo existe si AUTH_ALLOW_REGISTRATION está activo.
     * Se evalúa en cada petición (no al registrar las rutas), por lo que
     * config(['auth.allow_registration' => ...]) funciona también en las pruebas.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('auth.allow_registration', false), 404);

        return $next($request);
    }
}
