<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El registro viene desactivado por defecto; aquí se activa solo para estas pruebas.
        config(['auth.allow_registration' => true]);
    }

    #[Test]
    public function la_pantalla_de_registro_se_muestra(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    #[Test]
    public function los_usuarios_nuevos_pueden_registrarse(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'username' => 'testuser01',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
