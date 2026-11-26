<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== UserStatus::Activo) {
            $message = match ($user->status) {
                UserStatus::Baja => trans('account.baja'),
                UserStatus::Bloqueado => trans('account.bloqueado'),
                default => trans('account.inactivo'),
            };

            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['login' => $message]);
        }

        return $next($request);
    }
}
