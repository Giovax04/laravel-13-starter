<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function la_pantalla_de_enlace_de_restablecimiento_se_muestra(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    #[Test]
    public function se_puede_solicitar_el_enlace_de_restablecimiento(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function la_pantalla_de_restablecimiento_se_muestra(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    #[Test]
    public function la_contrasena_puede_restablecerse_con_un_token_valido(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    #[Test]
    public function solicitar_el_enlace_con_un_correo_inexistente_devuelve_la_misma_respuesta(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'noexiste@sistema.local'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', trans('account.reset_link_sent'));

        Notification::assertNothingSent();
    }

    #[Test]
    public function solicitar_el_enlace_con_un_correo_existente_devuelve_la_misma_respuesta(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', trans('account.reset_link_sent'));

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function un_token_invalido_y_un_correo_inexistente_devuelven_el_mismo_error(): void
    {
        $user = User::factory()->create();

        $payload = [
            'token' => 'token-invalido',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        // Correo existente con token inválido
        $this->post('/reset-password', $payload + ['email' => $user->email])
            ->assertSessionHasErrors(['email' => trans('account.reset_invalid')]);

        // Correo inexistente
        $this->post('/reset-password', $payload + ['email' => 'noexiste@sistema.local'])
            ->assertSessionHasErrors(['email' => trans('account.reset_invalid')]);
    }

    #[Test]
    public function una_segunda_solicitud_inmediata_no_revela_el_limite_de_envio(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', trans('account.reset_link_sent'));
    }
}
