<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * Siempre responde lo mismo (exista o no el correo, o aunque se haya
     * alcanzado el límite de envíos) para no revelar qué cuentas existen.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // El resultado se ignora a propósito: mostrar mensajes distintos según
        // el estado (usuario inexistente, límite de envíos) permitiría deducir
        // qué correos están registrados.
        Password::sendResetLink($request->only('email'));

        return back()->with('status', trans('account.reset_link_sent'));
    }
}
