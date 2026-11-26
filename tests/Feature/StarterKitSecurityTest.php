<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StarterKitSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function crearAdministrador(string $username = 'admin02'): User
    {
        $user = User::factory()->create([
            'username' => $username,
            'status' => UserStatus::Activo,
        ]);

        $user->assignRole('Administrador');

        return $user;
    }

    /**
     * Payload válido para PUT /users/{user}, tomando los valores actuales del usuario.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payloadUsuario(User $user, array $override = []): array
    {
        return array_merge([
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->status->value,
            'role' => $user->getRoleNames()->first(),
        ], $override);
    }

    // ------------------------------------------------------------------
    // Login y estatus
    // ------------------------------------------------------------------

    #[Test]
    public function un_usuario_activo_puede_iniciar_sesion_con_correo_o_username(): void
    {
        config(['auth.login_field' => 'both']);

        $user = User::factory()->create([
            'username' => 'cgio',
            'email' => 'carlos@sistema.local',
            'status' => UserStatus::Activo,
        ]);

        // Login con username en mayúsculas
        $this->post('/login', [
            'login' => 'CGIO',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        // Login con correo electrónico
        $this->post('/login', [
            'login' => 'carlos@sistema.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public static function inactiveStatusesProvider(): array
    {
        return [
            'baja' => [UserStatus::Baja, 'account.baja'],
            'bloqueado' => [UserStatus::Bloqueado, 'account.bloqueado'],
        ];
    }

    #[Test]
    #[DataProvider('inactiveStatusesProvider')]
    public function un_usuario_dado_de_baja_o_bloqueado_no_puede_iniciar_sesion(UserStatus $status, string $messageKey): void
    {
        User::factory()->create([
            'username' => 'inactivo',
            'email' => 'inactivo@sistema.local',
            'status' => $status,
        ]);

        // Con la contraseña correcta se informa el motivo específico.
        $this->post('/login', [
            'login' => 'inactivo',
            'password' => 'password',
        ])->assertSessionHasErrors(['login' => trans($messageKey)]);

        $this->assertGuest();
    }

    #[Test]
    public function un_usuario_autenticado_es_expulsado_si_su_estatus_cambia_a_bloqueado(): void
    {
        $user = User::factory()->create([
            'username' => 'activo01',
            'status' => UserStatus::Activo,
        ]);

        // 1. Inicia sesión real
        $this->post('/login', [
            'login' => 'activo01',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        // 2. El administrador cambia el estatus en base de datos
        User::where('id', $user->id)->update(['status' => UserStatus::Bloqueado]);

        // 3. Limpia la caché en memoria del guard para que la siguiente petición relea el usuario desde BD
        Auth::forgetGuards();

        // 4. En la siguiente petición el middleware EnsureUserIsActive debe expulsarlo
        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['login' => trans('account.bloqueado')]);

        $this->assertGuest();
    }

    #[Test]
    public function un_usuario_bloqueado_con_contrasena_incorrecta_recibe_el_error_generico(): void
    {
        $user = User::factory()->create([
            'username' => 'bloqtest',
            'status' => UserStatus::Bloqueado,
        ]);

        $response = $this->post('/login', [
            'login' => $user->username,
            'password' => 'contrasena-incorrecta',
        ]);

        $response->assertSessionHasErrors([
            'login' => trans('auth.failed'),
        ]);
        $this->assertGuest();
    }

    #[Test]
    public function un_usuario_inexistente_recibe_el_mismo_error_generico_y_se_ejecuta_el_hash_dummy(): void
    {
        Cache::forget('auth.dummy_hash');

        $this->post('/login', [
            'login' => 'noexiste',
            'password' => 'cualquier-cosa',
        ])->assertSessionHasErrors(['login' => trans('auth.failed')]);

        // El hash dummy solo se genera cuando el usuario no existe: confirma que
        // la ruta de "usuario inexistente" también pasa por Hash::check.
        $this->assertTrue(Cache::has('auth.dummy_hash'));
        $this->assertGuest();
    }

    #[Test]
    public function el_login_se_bloquea_temporalmente_tras_cinco_intentos_fallidos(): void
    {
        $user = User::factory()->create([
            'username' => 'ratetest',
            'status' => UserStatus::Activo,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'login' => $user->username,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post('/login', [
            'login' => $user->username,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('login'));
    }

    #[Test]
    public function el_registro_publico_devuelve_404_cuando_esta_desactivado(): void
    {
        // El middleware registration.enabled lee la config en cada petición.
        config(['auth.allow_registration' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Nuevo Usuario',
            'username' => 'nuevousr',
            'email' => 'nuevo@sistema.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Permisos y SuperAdmin
    // ------------------------------------------------------------------

    #[Test]
    public function usuario_sin_permisos_recibe_403_pero_superadmin_tiene_acceso_total(): void
    {
        $sinPermisos = User::factory()->create([
            'username' => 'invitado',
            'status' => UserStatus::Activo,
        ]);

        $this->actingAs($sinPermisos)->get('/users')->assertForbidden();
        $this->actingAs($sinPermisos)->get('/roles')->assertForbidden();

        $superAdminUsername = config('auth.superadmin.username');
        $superAdmin = User::where('username', $superAdminUsername)->first();

        $this->actingAs($superAdmin)->get('/users')->assertOk();
        $this->actingAs($superAdmin)->get('/roles')->assertOk();
    }

    #[Test]
    public function el_usuario_y_rol_superadmin_estan_ocultos_y_protegidos(): void
    {
        $adminUser = $this->crearAdministrador();

        $superAdminUsername = config('auth.superadmin.username');
        $superAdmin = User::where('username', $superAdminUsername)->first();
        $superAdminRole = Role::where('name', 'SuperAdmin')->first();

        // No aparecen en las vistas
        $this->actingAs($adminUser)
            ->get('/users')
            ->assertOk()
            ->assertDontSee($superAdminUsername);

        $this->actingAs($adminUser)
            ->get('/roles')
            ->assertOk()
            ->assertDontSee('SuperAdmin');

        // No se pueden eliminar por petición directa
        $this->actingAs($adminUser)
            ->delete("/users/{$superAdmin->id}")
            ->assertForbidden();

        $this->actingAs($adminUser)
            ->delete("/roles/{$superAdminRole->id}")
            ->assertForbidden();
    }

    #[Test]
    public function un_administrador_no_puede_crear_un_usuario_con_rol_superadmin(): void
    {
        $admin = $this->crearAdministrador();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Intruso',
            'username' => 'intruso01',
            'email' => 'intruso@sistema.local',
            'password' => 'Cl4ve-Segura-2026',
            'password_confirmation' => 'Cl4ve-Segura-2026',
            'status' => UserStatus::Activo->value,
            'role' => 'SuperAdmin',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['username' => 'intruso01']);
    }

    #[Test]
    public function un_administrador_no_puede_asignar_el_rol_superadmin_a_otro_usuario(): void
    {
        $admin = $this->crearAdministrador();
        $objetivo = $this->crearAdministrador('objetivo01');

        $this->actingAs($admin)
            ->put("/users/{$objetivo->id}", $this->payloadUsuario($objetivo, ['role' => 'SuperAdmin']))
            ->assertSessionHasErrors('role');

        $this->assertFalse($objetivo->fresh()->hasRole('SuperAdmin'));
    }

    #[Test]
    public function un_administrador_si_puede_cambiar_el_estatus_de_otro_usuario(): void
    {
        $admin = $this->crearAdministrador();
        $objetivo = $this->crearAdministrador('objetivo02');

        $this->actingAs($admin)
            ->put("/users/{$objetivo->id}", $this->payloadUsuario($objetivo, [
                'status' => UserStatus::Bloqueado->value,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(UserStatus::Bloqueado, $objetivo->fresh()->status);
    }

    #[Test]
    public function un_usuario_no_puede_cambiar_su_propio_estatus(): void
    {
        $admin = $this->crearAdministrador();

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", $this->payloadUsuario($admin, [
                'status' => UserStatus::Bloqueado->value,
            ]))
            ->assertSessionHasErrors('status');

        $this->assertSame(UserStatus::Activo, $admin->fresh()->status);
    }

    #[Test]
    public function un_usuario_no_puede_cambiar_su_propio_rol(): void
    {
        $admin = $this->crearAdministrador();
        Role::create(['name' => 'Auditor', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", $this->payloadUsuario($admin, ['role' => 'Auditor']))
            ->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->hasRole('Administrador'));
    }

    #[Test]
    public function el_nombre_superadmin_esta_reservado_sin_importar_mayusculas(): void
    {
        $admin = $this->crearAdministrador();
        $auditor = Role::create(['name' => 'Auditor', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->post('/roles', ['name' => 'SUPERADMIN', 'permissions' => []])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->put("/roles/{$auditor->id}", ['name' => 'superAdmin', 'permissions' => []])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseMissing('roles', ['name' => 'SUPERADMIN']);
        $this->assertDatabaseHas('roles', ['id' => $auditor->id, 'name' => 'Auditor']);
    }

    #[Test]
    public function un_rol_con_usuarios_asignados_no_puede_eliminarse(): void
    {
        $adminRole = Role::where('name', 'Administrador')->firstOrFail();

        $adminUser = User::factory()->create(['status' => UserStatus::Activo]);
        $adminUser->assignRole($adminRole);

        $this->actingAs($adminUser)
            ->delete("/roles/{$adminRole->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $adminRole->id]);
    }

    // ------------------------------------------------------------------
    // Perfil
    // ------------------------------------------------------------------

    #[Test]
    public function el_superadmin_no_puede_eliminar_su_cuenta_aunque_el_borrado_este_habilitado(): void
    {
        config(['auth.allow_account_deletion' => true]);

        $superAdmin = User::where('username', config('auth.superadmin.username'))->firstOrFail();

        $this->actingAs($superAdmin)
            ->delete('/profile', ['password' => config('auth.superadmin.password')])
            ->assertForbidden();

        $this->assertNotNull($superAdmin->fresh());
    }

    #[Test]
    public function el_perfil_no_permite_cambiar_username_ni_estatus_y_normaliza_el_correo(): void
    {
        $user = User::factory()->create([
            'username' => 'perfil01',
            'status' => UserStatus::Activo,
        ]);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Nombre Nuevo',
            'email' => 'NUEVO@Sistema.Local',
            'username' => 'hackeado',
            'status' => UserStatus::Bloqueado->value,
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $fresh = $user->fresh();

        $this->assertSame('Nombre Nuevo', $fresh->name);
        $this->assertSame('nuevo@sistema.local', $fresh->email);
        $this->assertSame('perfil01', $fresh->username);
        $this->assertSame(UserStatus::Activo, $fresh->status);
    }

    // ------------------------------------------------------------------
    // Seeder
    // ------------------------------------------------------------------

    #[Test]
    public function el_seeder_es_idempotente_y_no_duplica_roles_ni_superadmin(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertSame(1, User::role('SuperAdmin')->count());
        $this->assertSame(2, Role::count());
    }

    #[Test]
    public function el_seeder_no_pisa_los_permisos_que_se_le_quitaron_al_rol_administrador(): void
    {
        Role::findByName('Administrador')->revokePermissionTo('users.delete');

        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertFalse(Role::findByName('Administrador')->hasPermissionTo('users.delete'));
    }

    #[Test]
    public function el_seeder_no_promueve_a_un_usuario_existente_con_el_username_del_superadmin(): void
    {
        User::role('SuperAdmin')->get()->each->delete();

        User::factory()->create([
            'username' => config('auth.superadmin.username'),
            'status' => UserStatus::Activo,
        ]);

        $this->expectException(RuntimeException::class);

        $this->seed(RoleAndPermissionSeeder::class);
    }

    #[Test]
    public function el_seeder_rechaza_un_username_de_superadmin_demasiado_largo(): void
    {
        User::role('SuperAdmin')->get()->each->delete();

        config(['auth.superadmin.username' => 'abcdefghijk']);

        $this->expectException(RuntimeException::class);

        $this->seed(RoleAndPermissionSeeder::class);
    }
}
