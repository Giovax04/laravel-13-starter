<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    private const GUARD = 'web';

    private const SUPERADMIN_ROLE = 'SuperAdmin';

    private const ADMIN_ROLE = 'Administrador';

    private const MIN_PASSWORD_LENGTH = 12;

    /**
     * Permisos base con formato "modulo.accion".
     * Para agregar un módulo nuevo, añade sus permisos aquí y vuelve a correr el seeder.
     *
     * @var list<string>
     */
    private const PERMISSIONS = [
        // Usuarios
        'users.view',
        'users.create',
        'users.edit',
        'users.delete',

        // Roles y Permisos
        'roles.view',
        'roles.create',
        'roles.edit',
        'roles.delete',
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Todo o nada: evita quedar con roles creados pero sin usuario inicial.
        DB::transaction(function (): void {
            $newPermissions = $this->syncPermissions();

            // Rol SuperAdmin (acceso total automático vía Gate::before)
            $superAdminRole = Role::firstOrCreate([
                'name' => self::SUPERADMIN_ROLE,
                'guard_name' => self::GUARD,
            ]);

            $this->syncAdminRole($newPermissions);
            $this->ensureSuperAdmin($superAdminRole);
        });

        $registrar->forgetCachedPermissions();
    }

    /**
     * Crea los permisos faltantes y devuelve los nombres de los que se crearon ahora.
     *
     * @return list<string>
     */
    private function syncPermissions(): array
    {
        $created = [];

        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
            ]);

            if ($permission->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        return $created;
    }

    /**
     * Rol Administrador estándar.
     *
     * Solo se le asignan todos los permisos al crearlo. En corridas posteriores
     * únicamente recibe los permisos nuevos, para no pisar la personalización
     * hecha desde la interfaz (por ejemplo, quitarle "users.delete").
     *
     * @param  list<string>  $newPermissions
     */
    private function syncAdminRole(array $newPermissions): void
    {
        $adminRole = Role::firstOrCreate([
            'name' => self::ADMIN_ROLE,
            'guard_name' => self::GUARD,
        ]);

        if ($adminRole->wasRecentlyCreated) {
            $adminRole->givePermissionTo(self::PERMISSIONS);

            return;
        }

        if ($newPermissions !== []) {
            $adminRole->givePermissionTo($newPermissions);
        }
    }

    /**
     * Crea el usuario maestro solo si todavía no existe uno con el rol SuperAdmin.
     * Nunca promueve a un usuario preexistente.
     */
    private function ensureSuperAdmin(Role $superAdminRole): void
    {
        if (User::role(self::SUPERADMIN_ROLE)->exists()) {
            return;
        }

        $config = (array) config('auth.superadmin');

        $name = trim((string) ($config['name'] ?? ''));
        $username = Str::lower(trim((string) ($config['username'] ?? '')));
        $email = Str::lower(trim((string) ($config['email'] ?? '')));
        $password = (string) ($config['password'] ?? '');

        if ($name === '' || $username === '' || $email === '' || $password === '') {
            throw new RuntimeException(
                'Configuración incompleta: define SUPERADMIN_NAME, SUPERADMIN_USERNAME, SUPERADMIN_EMAIL y SUPERADMIN_PASSWORD en tu .env.'
            );
        }

        if (mb_strlen($username) > 10 || ! ctype_alnum($username)) {
            throw new RuntimeException(
                'SUPERADMIN_USERNAME debe ser alfanumérico y de máximo 10 caracteres.'
            );
        }

        $this->assertSecurePassword($password);

        if (User::where('username', $username)->orWhere('email', $email)->exists()) {
            throw new RuntimeException(
                'Ya existe un usuario con ese username o correo que no es SuperAdmin. '
                .'Cambia SUPERADMIN_USERNAME / SUPERADMIN_EMAIL o resuelve el conflicto manualmente.'
            );
        }

        $superAdmin = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($password),
            'status' => UserStatus::Activo,
        ]);

        $superAdmin->forceFill(['email_verified_at' => now()])->save();
        $superAdmin->assignRole($superAdminRole);
    }

    /**
     * Fuera de local/testing no se permite crear el usuario maestro con una
     * contraseña débil. Se valida solo cuando la contraseña realmente se usa.
     */
    private function assertSecurePassword(string $password): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        if ($password === 'password' || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException(
                'Seguridad: no puedes crear el SuperAdmin fuera de local/testing con SUPERADMIN_PASSWORD igual a "password" '
                .'o menor a '.self::MIN_PASSWORD_LENGTH.' caracteres.'
            );
        }
    }
}
