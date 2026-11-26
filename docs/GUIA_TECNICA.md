# Guía Técnica de Arquitectura y Funcionamiento — Laravel 13 Starter Kit

Documento de referencia interna que explica cómo operan la autenticación dual, el control de estatus, la parametrización del usuario maestro, la gestión de usuarios, el árbol de permisos, las pruebas automatizadas, la creación de nuevos módulos CRUD y la ampliación de permisos en módulos existentes.

---

## 1. Stack y Entorno Base

- **Backend:** Laravel 13.x sobre PHP 8.5 con Laravel Sail (Docker) y MySQL 8.4.
- **Frontend:** Plantilla base estándar de Laravel Breeze (navegación superior Blade + Tailwind CSS + Alpine.js).
- **Autorización:** `spatie/laravel-permission` con nomenclatura plana `modulo.accion` (`users.view`, `roles.create`, etc.) y bypass global mediante `Gate::before` para el rol maestro `SuperAdmin`.
- **Localización:** Español (`es` y `es_MX`) mediante `laravel-lang/common`.
- **Pruebas:** PHPUnit sobre SQLite en memoria (ver sección 6).

---

## 2. Flujo de Autenticación y Estatus en 3 Capas

El acceso al sistema está protegido en tres momentos distintos del ciclo de vida del usuario:

### Capa 1: Inicio de Sesión (`app/Http/Requests/Auth/LoginRequest.php`)

1. Normaliza el valor ingresado en el campo `login` a minúsculas y sin espacios (`Str::lower(trim(...))`).
2. Evalúa el modo configurado en `config('auth.login_field')` (`both`, `email`, `username`; el valor se normaliza a minúsculas en `config/auth.php`):
   - En modo `both`, permite hasta `255` caracteres cuando el valor es un correo válido (`FILTER_VALIDATE_EMAIL`) y restringe a `10` caracteres cuando se trata de una clave de usuario (`username`).
   - En modo `email`, exige formato de correo (`email`, `max:255`).
   - En modo `username`, restringe a `max:10`.
3. **Prevención de enumeración de cuentas (error genérico):** Consulta el usuario y valida primero la contraseña. Si el usuario no existe o la contraseña no coincide, incrementa el `RateLimiter` y devuelve siempre el error genérico `auth.failed`.
4. **Prevención de enumeración por tiempo (hash dummy):** Si el usuario no existe, se ejecuta igualmente `Hash::check` contra un hash de referencia (`Cache::rememberForever('auth.dummy_hash', ...)`) generado con el mismo costo configurado. Así, el tiempo de respuesta de un usuario inexistente es equivalente al de uno existente con contraseña incorrecta.
   - Si cambias `BCRYPT_ROUNDS` en un entorno ya desplegado, ejecuta `php artisan cache:clear` para regenerar ese hash.
5. **Validación de estatus post-credenciales:** Únicamente cuando las credenciales son válidas comprueba `status === UserStatus::Activo`. Si la cuenta está en `UserStatus::Baja` o `UserStatus::Bloqueado`, incrementa el contador de intentos (`RateLimiter::hit`) y muestra el mensaje específico (`account.baja`, `account.bloqueado` o `account.inactivo`) sin abrir sesión.
6. Aplica protección contra fuerza bruta (`RateLimiter`) limitada a 5 intentos por combinación de credencial e IP.

### Capa 2: Registro Público Controlado (`routes/auth.php` + `EnsureRegistrationIsEnabled`)

1. Las rutas `/register` **siempre están definidas**, pero están envueltas en el middleware `registration.enabled` (`App\Http\Middleware\EnsureRegistrationIsEnabled`), que responde `404` cuando `config('auth.allow_registration')` es `false` (`AUTH_ALLOW_REGISTRATION` en `.env`).
2. La decisión se toma **en cada petición**, no al registrar las rutas. Por eso:
   - Cambiar el valor no requiere limpiar la caché de rutas (solo la de configuración si usas `config:cache`).
   - Las pruebas pueden activar o desactivar el registro con `config(['auth.allow_registration' => true])`.
3. Las vistas deben consultar `config('auth.allow_registration')` para mostrar u ocultar enlaces de registro; **no** usar `Route::has('register')`, porque la ruta siempre existe.
4. `RegisteredUserController` normaliza `username` (`alpha_num`, `max:10`, minúsculas) y `email` (minúsculas) en `prepareForValidation()` y asigna `UserStatus::Activo` por defecto.

### Capa 3: Expulsión en Caliente (`app/Http/Middleware/EnsureUserIsActive.php`)

1. Registrado en el grupo `web` dentro de `bootstrap/app.php` (`$middleware->web(append: [EnsureUserIsActive::class])`), por lo que cubre todas las rutas web, incluidas las de `profile.*`.
2. En cada petición autenticada comprueba que `$request->user()->status === UserStatus::Activo`.
3. Si un administrador cambia el estatus de un usuario a `Baja` o `Bloqueado` mientras el usuario tiene sesión abierta, el middleware cierra su sesión inmediatamente (`Auth::guard('web')->logout()`), invalida la sesión, regenera el token CSRF y redirige a `/login` con el motivo como error del campo `login` (`->withErrors(['login' => $message])`).

### Recuperación de contraseña sin enumeración de cuentas

El flujo de Breeze viene con dos fugas que el kit corrige:

1. **`PasswordResetLinkController@store` (`/forgot-password`):** ignora a propósito el resultado de `Password::sendResetLink()` y siempre responde con el mismo mensaje (`account.reset_link_sent`), exista o no el correo y aunque se haya alcanzado el límite de envíos. Normaliza el correo a minúsculas antes de validar.
2. **`NewPasswordController@store` (`/reset-password`):** cuando el restablecimiento falla, "usuario inexistente" y "token inválido" devuelven el mismo error (`account.reset_invalid`) sobre el campo `email`. Normaliza el correo a minúsculas y compara el estado con `===`.

Lo que **no** cubre: la diferencia de tiempo entre enviar un correo y no enviarlo. Si es relevante, envía la notificación de restablecimiento por cola (queue).

### Mensajes de cuenta (`lang/es/account.php`)

Archivo propio del kit (no lo sobrescribe `laravel-lang`):

```php
<?php

return [
    'baja' => 'Tu cuenta se encuentra dada de baja. Contacta al administrador.',
    'bloqueado' => 'Tu cuenta se encuentra bloqueada. Contacta al administrador.',
    'inactivo' => 'Tu cuenta no está activa. Contacta al administrador.',
    'reset_link_sent' => 'Si el correo está registrado, te enviaremos un enlace para restablecer tu contraseña.',
    'reset_invalid' => 'El enlace de restablecimiento no es válido o ha expirado. Solicita uno nuevo.',
];
```

---

## 3. Parametrización del Usuario Maestro (`SuperAdmin`)

Para evitar credenciales fijas en el repositorio Git y facilitar la reutilización de la plantilla:

1. **Variables en `.env` y `.env.example`:**
   - `SUPERADMIN_NAME`, `SUPERADMIN_USERNAME`, `SUPERADMIN_EMAIL` y `SUPERADMIN_PASSWORD`.
2. **Mapeo en `config/auth.php` (`auth.superadmin`):**
   - Centraliza la lectura de `env()` y normaliza `username` y `email` a minúsculas para que el sistema siga operando incluso cuando se cachea la configuración (`php artisan config:cache`).
   - También define `login_field`, `allow_registration` y `allow_account_deletion`.
3. **Consumo desacoplado (`database/seeders/RoleAndPermissionSeeder.php`):**
   - **Idempotente:** puede ejecutarse varias veces sin duplicar permisos, roles ni el usuario maestro. Todo corre dentro de una transacción (`DB::transaction`).
   - **Permisos y rol `Administrador`:** el rol recibe todos los permisos solo cuando se crea. En ejecuciones posteriores únicamente recibe los **permisos nuevos** agregados al arreglo, para no revertir personalizaciones hechas desde la interfaz (por ejemplo, haberle quitado `users.delete`).
   - **Usuario maestro:** solo se crea si todavía no existe un usuario con el rol `SuperAdmin`. Nunca promueve a un usuario preexistente: si el `username` o el correo ya pertenecen a otro usuario, lanza una `RuntimeException`.
   - **Validaciones previas a tocar la base de datos:** nombre, `username`, correo y contraseña no vacíos; `username` alfanumérico ASCII de máximo 10 caracteres; y, fuera de los entornos `local` y `testing`, contraseña distinta de `password` y de al menos 12 caracteres (esto cubre también `staging`).
   - `email_verified_at` no es asignable en masa (`#[Fillable]`), por lo que se asigna de forma explícita con `forceFill(...)->save()`.
   - Las variables `SUPERADMIN_*` solo se usan la primera vez; cambiarlas después no modifica al usuario existente.
4. **Pruebas:** `StarterKitSecurityTest` consulta `config('auth.superadmin.username')` dinámicamente, y `phpunit.xml` fija valores `SUPERADMIN_*` para que los resultados no dependan del `.env` de cada desarrollador.

---

## 4. Estructura de Módulos Administrativos Core

### A. Módulo de Usuarios (`UserController`)

- **Validación:** `StoreUserRequest` y `UpdateUserRequest` normalizan `username` y `email` a minúsculas en `prepareForValidation()` y validan:
  - `username`: `alpha_num:ascii` (solo letras y números ASCII, alineado con el seeder), `max:10`, único.
  - `status`: contra el Enum `App\Enums\UserStatus`.
  - `role`: debe existir en `roles` con `guard_name = web` y **no** ser `SuperAdmin`, por lo que nadie puede crear ni asignar el rol maestro manipulando la petición.
- **Restricciones de auto-edición (`UpdateUserRequest`):** cuando el usuario edita su propio registro, su `status` debe seguir siendo `Activo` y su `role` no puede cambiar. Evita bloquearse por accidente o escalar privilegios.
- **Aislamiento del `SuperAdmin`:**
  - `User::withoutRole('SuperAdmin')` oculta al usuario maestro de la tabla principal.
  - `Role::where('name', '!=', 'SuperAdmin')` excluye el rol maestro de los selectores de alta y edición.
  - `UpdateUserRequest::authorize()` y `UserController@destroy` rechazan con `403` cualquier intento de editar o eliminar a un usuario `SuperAdmin`, así como la auto-eliminación del usuario en sesión.
- **Perfil de Usuario (`resources/views/profile/partials/update-profile-information-form.blade.php`):**
  - El campo `username` se presenta con `readonly` y `disabled`, y `ProfileUpdateRequest` solo valida `name` y `email`, por lo que `username` y `status` enviados manualmente se ignoran.
  - `ProfileUpdateRequest` normaliza el correo a minúsculas antes de validar.
- **Auto-eliminación controlada por configuración (`AUTH_ALLOW_ACCOUNT_DELETION`):**
  - Por defecto `AUTH_ALLOW_ACCOUNT_DELETION=false` (`config('auth.allow_account_deletion')`) oculta el bloque `delete-user-form` en `resources/views/profile/edit.blade.php` y bloquea con `404` el endpoint `DELETE /profile` mediante `abort_unless(config('auth.allow_account_deletion'), 404)` en `ProfileController@destroy`.
  - Si un proyecto futuro (por ejemplo un portal de clientes) requiere permitir el borrado de cuenta propio, basta con activar `AUTH_ALLOW_ACCOUNT_DELETION=true` en `.env`; `ProfileController@destroy` mantiene además `abort_if($request->user()->hasRole('SuperAdmin'), 403)` como protección permanente de la cuenta maestra.
- **Interfaz con Modales (`resources/views/users/index.blade.php`):**
  - Emplea `<x-modal name="create-user">` y un único `<x-modal name="edit-user">` reutilizable controlado por Alpine.js (`openEdit(...)`).
  - El campo oculto `<input type="hidden" name="_modal" value="...">` permite reabrir automáticamente el modal exacto con sus errores si falla la validación en el servidor.

### B. Módulo de Roles y Permisos (`RoleController`)

- **Árbol dinámico sin tabla extra:**
  - `RoleController@index` agrupa todos los permisos de Spatie por su prefijo (`Permission::orderBy('name')->get()->groupBy(fn ($p) => explode('.', $p->name)[0])`).
  - Cruza las claves con `config/permissions.php` (`modules` y `actions`) para mostrar etiquetas legibles en español en la interfaz.
- **Componente Parcial (`resources/views/roles/partials/permission-tree.blade.php`):**
  - Reutilizado en los modales de creación y edición mediante la prop `modelVar` (`createPermissions` o `editRole.permissions`).
  - Cada módulo incorpora un checkbox maestro en Alpine.js que selecciona/deselecciona todos sus permisos hijos y activa el estado visual `:indeterminate` cuando solo algunos permisos están marcados.
- **Reglas de Integridad (`StoreRoleRequest`, `UpdateRoleRequest` y `RoleController@destroy`):**
  - El nombre `SuperAdmin` está reservado **sin distinguir mayúsculas** (`strcasecmp`): no se puede crear ni renombrar otro rol como `SUPERADMIN`, `superAdmin`, etc.
  - Los permisos enviados se validan con `exists` sobre `permissions.name` y `guard_name = web`.
  - `UpdateRoleRequest::authorize()` y `destroy()` bloquean modificar o borrar el rol `SuperAdmin`.
  - Antes de eliminar un rol, `$role->users()->exists()` verifica que no tenga usuarios asignados para evitar cuentas huérfanas.

---

## 5. Capa de Autorización y Enrutamiento (`bootstrap/app.php` y `routes/web.php`)

1. **Alias de Middlewares (`bootstrap/app.php`):**
   - Registra `role`, `permission` y `role_or_permission` de Spatie y `registration.enabled`, además de `EnsureUserIsActive` en el grupo `web`, conservando `withExceptions` con `shouldRenderJsonWhen(...)`.
2. **Bypass Global (`app/Providers/AppServiceProvider.php`):**
   - `Gate::before` retorna `true` cuando `$user->hasRole('SuperAdmin')` y `null` para cualquier otro usuario (nunca `false`), otorgando acceso automático a todas las rutas `@can` y middlewares `permission:*` sin bloquear la evaluación de Spatie para el resto de roles.
3. **Protección por Capas (`routes/web.php`):**
   - Grupo externo `auth` → grupo de módulo (`permission:users.view` / `permission:roles.view`) → middleware específico por acción (`permission:users.create`, `permission:users.edit`, `permission:users.delete`).
   - Como los grupos están anidados, **`modulo.view` es requisito para cualquier otra acción del módulo**. Un rol con `users.create` pero sin `users.view` no podrá crear usuarios. Tenlo presente al armar roles personalizados.

---

## 6. Pruebas Automatizadas (`tests/Feature/`)

La suite utiliza **PHPUnit** con `RefreshDatabase` sobre **SQLite en memoria**. `phpunit.xml` define:

- `DB_CONNECTION=sqlite` y `DB_DATABASE=:memory:` (no se toca la base de datos de desarrollo).
- `APP_LOCALE=es`, porque las pruebas comparan textos en español.
- `AUTH_LOGIN_FIELD=both`, `AUTH_ALLOW_REGISTRATION=false`, `AUTH_ALLOW_ACCOUNT_DELETION=false` y las variables `SUPERADMIN_*`, para que los resultados no dependan del `.env` de cada desarrollador (los valores de `phpunit.xml` tienen prioridad).

Todas las pruebas usan nombres descriptivos en español con el atributo `#[Test]`.

### `StarterKitSecurityTest.php`

| Área | Qué verifica |
| :--- | :--- |
| **Login y estatus** | Login con correo o `username` (en mayúsculas). Rechazo de cuentas en `Baja`/`Bloqueado` con su mensaje específico (`#[DataProvider]`). Expulsión en caliente al cambiar el estatus. Error genérico para cuentas bloqueadas con contraseña incorrecta y para usuarios inexistentes (con hash dummy). Bloqueo temporal tras 5 intentos fallidos. `/register` devuelve `404` con el registro desactivado. |
| **Permisos y SuperAdmin** | `403` para usuarios sin permisos y acceso total para `SuperAdmin`. Ocultamiento y blindaje del usuario y rol `SuperAdmin`. Imposible crear o asignar el rol `SuperAdmin` desde `/users`. Un administrador sí puede cambiar el estatus de **otro** usuario, pero no el suyo ni su propio rol. Nombre `SuperAdmin` reservado sin importar mayúsculas. Un rol con usuarios asignados no puede eliminarse. |
| **Perfil** | El `SuperAdmin` no puede eliminar su cuenta aunque el borrado esté habilitado. El perfil ignora `username` y `status` enviados a la fuerza y normaliza el correo. |
| **Seeder** | Idempotencia (un solo `SuperAdmin`, dos roles). No pisa permisos quitados al rol `Administrador`. No promueve a un usuario existente con el mismo `username`. Rechaza un `SUPERADMIN_USERNAME` de más de 10 caracteres. |

### Pruebas de Breeze adaptadas (`tests/Feature/Auth/`)

- `AuthenticationTest`: login con el campo `login` (correo), contraseña inválida y cierre de sesión.
- `RegistrationTest`: activa el registro con `config(['auth.allow_registration' => true])` en `setUp()` y registra con `username`.
- `EmailVerificationTest`, `PasswordConfirmationTest` y `PasswordUpdateTest`: flujos estándar de Breeze.
- `PasswordResetTest`: flujos estándar de Breeze más la verificación de respuesta genérica: un correo inexistente y uno existente reciben el mismo mensaje en `/forgot-password`, una segunda solicitud inmediata no revela el límite de envío, y un token inválido y un correo inexistente devuelven el mismo error en `/reset-password`.

### Cómo ejecutarlas

```bash
# Windows PowerShell
docker compose exec laravel.test php artisan test
docker compose exec laravel.test php artisan test --filter=StarterKitSecurityTest

# Linux / macOS / WSL2
./vendor/bin/sail artisan test
./vendor/bin/sail artisan test --filter=StarterKitSecurityTest
```

> **SQLite vs. MySQL:** SQLite distingue mayúsculas y minúsculas en comparaciones de texto y MySQL no (según la collation). Esto ayuda a detectar errores de normalización, pero conviene validar el despliegue contra MySQL (por ejemplo, con un workflow de CI que use un servicio MySQL).

---

## 7. Guía para Crear un Nuevo Módulo CRUD (con Permisos Básicos y Personalizados)

Para agregar un módulo de negocio nuevo (por ejemplo `Productos`) respetando las convenciones del Starter Kit, se generan **8 archivos nuevos** (modelo, migración, factory, controlador, 2 form requests, vista Blade y test) y se actualizan **4 archivos existentes**.

### Paso 1: Generar los artefactos con Artisan

```bash
# Windows PowerShell (sin WSL2)
docker compose exec laravel.test php artisan make:model Producto -mf
docker compose exec laravel.test php artisan make:controller ProductoController
docker compose exec laravel.test php artisan make:request StoreProductoRequest
docker compose exec laravel.test php artisan make:request UpdateProductoRequest
docker compose exec laravel.test php artisan make:test ProductoCrudTest
```

```bash
# Linux / macOS / WSL2
./vendor/bin/sail artisan make:model Producto -mf
./vendor/bin/sail artisan make:controller ProductoController
./vendor/bin/sail artisan make:request StoreProductoRequest
./vendor/bin/sail artisan make:request UpdateProductoRequest
./vendor/bin/sail artisan make:test ProductoCrudTest
```

### Paso 2: Implementar los archivos generados

1. **`database/migrations/xxxx_create_productos_table.php`**: Define las columnas e índices del recurso.
2. **`database/factories/ProductoFactory.php`**: Define datos de prueba en español (`es_MX`) para los tests.
3. **`app/Models/Producto.php`**: Utiliza atributos modernos de PHP 8.5 (`#[Fillable([...])]`) y el método `casts()`.
4. **`app/Http/Requests/StoreProductoRequest.php`**: Valida `$this->user()->can('productos.create')` en `authorize()`, normaliza datos en `prepareForValidation()` y define reglas y mensajes en español.
5. **`app/Http/Requests/UpdateProductoRequest.php`**: Valida `$this->user()->can('productos.edit')` en `authorize()`, aplica reglas `Rule::unique(...)->ignore(...)` y define mensajes en español.
6. **`app/Http/Controllers/ProductoController.php`**: Implementa `index` (con buscador y `paginate()`), `store`, `update`, `destroy` y cualquier método adicional del módulo (por ejemplo `export()`), omitiendo `create` y `edit` porque la captura ocurre en modales.
7. **`resources/views/productos/index.blade.php`**: Extiende `<x-app-layout>`, incluye barra de búsqueda, tabla paginada, botones protegidos con `@can('productos.create')`, `@can('productos.edit')`, `@can('productos.delete')` (y `@can('productos.export')` si aplica), además de los dos modales `<x-modal>` (`create-producto` y `edit-producto`) controlados con Alpine.js y reapertura automática mediante `<input type="hidden" name="_modal" ...>`.
8. **`tests/Feature/ProductoCrudTest.php`**: Prueba PHPUnit con `RefreshDatabase`, nombres en español y atributo `#[Test]`, que verifica denegación `403` sin permisos y el ciclo completo de operaciones con un rol autorizado.

> **Mensajes de texto:** en lugar de escribir los mensajes en el controlador, puedes crear `lang/es/productos.php` y usar `trans('productos.creado')`. No modifiques los archivos de `laravel-lang` (`auth.php`, `validation.php`, `passwords.php`, `pagination.php`), porque una actualización de traducciones puede sobrescribirlos; los mensajes propios del kit viven en archivos aparte (por ejemplo `lang/es/account.php`).

### Paso 3: Registrar el módulo y permisos (básicos y personalizados) en los 4 archivos del núcleo

1. **`database/seeders/RoleAndPermissionSeeder.php`**: Agrega los 4 permisos básicos (`view`, `create`, `edit`, `delete`) y cualquier acción adicional al arreglo `PERMISSIONS`, siguiendo siempre la convención plana `modulo.accion`:

   ```php
   'productos.view',
   'productos.create',
   'productos.edit',
   'productos.delete',
   'productos.export', // Ejemplo de permiso personalizado adicional
   ```

   *(El seeder asigna automáticamente los permisos **nuevos** al rol `Administrador`. Para cualquier otro rol personalizado, asígnalos desde la interfaz de Roles y Permisos).*

2. **`config/permissions.php`**: Registra la etiqueta legible del módulo en `modules` y, **si agregaste acciones distintas a las 4 básicas (`view`, `create`, `edit`, `delete`), decláralas en `actions`** para que el árbol de permisos las traduzca automáticamente en español en lugar de mostrar la clave técnica en inglés:

   ```php
   return [
       'modules' => [
           'users'     => 'Usuarios',
           'roles'     => 'Roles y Permisos',
           'productos' => 'Productos',
       ],

       'actions' => [
           'view'    => 'Ver listado',
           'create'  => 'Crear',
           'edit'    => 'Editar',
           'delete'  => 'Eliminar',
           'export'  => 'Exportar',  // Traducción de productos.export
           'import'  => 'Importar',  // Otras acciones reutilizables
           'approve' => 'Aprobar',
       ],
   ];
   ```

   *(Nota: Como `config/permissions.php` es un diccionario global de acciones, una vez que agregas `'export' => 'Exportar'` en `actions`, cualquier módulo futuro que use `*.export` lo traducirá automáticamente).*

3. **`routes/web.php`**: Declara las rutas protegidas dentro de `Route::middleware('auth')->group(...)`, asignando el middleware `permission:modulo.accion` correspondiente a cada endpoint (colocando las rutas estáticas como `/productos/export` **antes** de cualquier ruta con parámetro dinámico como `/productos/{producto}`):

   ```php
   Route::middleware('permission:productos.view')->group(function () {
       Route::get('/productos', [ProductoController::class, 'index'])->name('productos.index');
       Route::get('/productos/export', [ProductoController::class, 'export'])
           ->middleware('permission:productos.export')
           ->name('productos.export');
       Route::post('/productos', [ProductoController::class, 'store'])
           ->middleware('permission:productos.create')
           ->name('productos.store');
       Route::put('/productos/{producto}', [ProductoController::class, 'update'])
           ->middleware('permission:productos.edit')
           ->name('productos.update');
       Route::delete('/productos/{producto}', [ProductoController::class, 'destroy'])
           ->middleware('permission:productos.delete')
           ->name('productos.destroy');
   });
   ```

4. **`resources/views/layouts/navigation.blade.php` y vista del módulo**:

   - En `navigation.blade.php`, agrega el enlace `@can('productos.view')` tanto en el menú de escritorio (`<x-nav-link>`) como en el menú responsivo móvil (`<x-responsive-nav-link>`).
   - Dentro de `resources/views/productos/index.blade.php`, envuelve el botón de exportación con su directiva `@can`:

     ```blade
     @can('productos.export')
         <a href="{{ route('productos.export') }}" class="...">
             Exportar
         </a>
     @endcan
     ```

### Paso 4: Ejecutar migración y sincronizar permisos

```bash
# Windows PowerShell (en Linux/macOS/WSL2 usa ./vendor/bin/sail)
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test php artisan db:seed --class=RoleAndPermissionSeeder
docker compose exec laravel.test php artisan permission:cache-reset
```

---

## 8. Caso Práctico: Agregar Permisos Adicionales a un Módulo Existente

Si necesitas incorporar una acción extra a un módulo que ya existe (por ejemplo, agregar `users.export` al módulo **Usuarios**), **no debes crear ninguna migración nueva**: Spatie almacena los permisos como filas en la tabla `permissions`, por lo que basta con registrar el permiso en el seeder, traducirlo y limpiar la caché.

### Paso 1: Agregar el permiso en `database/seeders/RoleAndPermissionSeeder.php`

Incluye el nuevo permiso dentro de la constante `PERMISSIONS` usando la convención `modulo.accion`. Como el seeder usa `Permission::firstOrCreate()`, ejecutarlo nuevamente no duplica ni altera los permisos existentes, y solo los permisos **recién creados** se asignan al rol `Administrador`:

```php
private const PERMISSIONS = [
    'users.view',
    'users.create',
    'users.edit',
    'users.delete',
    'users.export', // <-- Nuevo permiso agregado al módulo existente
    // ... resto de permisos
];
```

### Paso 2: Traducir la acción en `config/permissions.php`

Si la acción (`export`) aún no existe en el arreglo `'actions'`, agrégala para que el árbol de permisos del modal de Roles la muestre en español:

```php
'actions' => [
    'view'   => 'Ver listado',
    'create' => 'Crear',
    'edit'   => 'Editar',
    'delete' => 'Eliminar',
    'export' => 'Exportar', // <-- Traducción en el árbol de permisos
],
```

### Paso 3: Ejecutar el Seeder y limpiar la caché de Spatie

Ejecuta únicamente `RoleAndPermissionSeeder` (nunca `migrate:fresh` en entornos con datos) y limpia la caché interna de permisos de Spatie:

```bash
# Windows PowerShell (sin WSL2)
docker compose exec laravel.test php artisan db:seed --class=RoleAndPermissionSeeder
docker compose exec laravel.test php artisan permission:cache-reset
```

```bash
# Linux / macOS / WSL2
./vendor/bin/sail artisan db:seed --class=RoleAndPermissionSeeder
./vendor/bin/sail artisan permission:cache-reset
```

### Paso 4: Proteger la ruta, la vista y asignar a los roles

1. **En `routes/web.php`** (dentro del grupo `permission:users.view`, y **antes** de cualquier ruta `/users/{user}`):

   ```php
   Route::get('/users/export', [UserController::class, 'export'])
       ->middleware('permission:users.export')
       ->name('users.export');
   ```

2. **En `resources/views/users/index.blade.php`:**

   ```blade
   @can('users.export')
       <a href="{{ route('users.export') }}" class="...">
           Exportar
       </a>
   @endcan
   ```

3. **Asignación de acceso:**
   - El rol `SuperAdmin` obtiene el permiso automáticamente mediante `Gate::before`.
   - El rol `Administrador` lo recibe automáticamente la primera vez que el seeder crea el permiso. Si alguien se lo quitó después, el seeder **no** se lo devuelve.
   - Para otros roles personalizados, ingresa a **Roles y Permisos → Editar** y marca la casilla **Exportar** dentro del módulo **Usuarios**.

---

## 9. Decisiones de Diseño y Límites Conocidos

Estos puntos son decisiones conscientes (o riesgos a vigilar) y conviene tenerlos presentes al extender el kit:

1. **Sin jerarquía de roles.** Quien tiene `users.edit` o `roles.edit` puede asignar cualquier rol o permiso, excepto el rol `SuperAdmin`. Si un proyecto necesita que un rol no pueda otorgar permisos que él mismo no posee, hay que implementarlo explícitamente.
2. **`modulo.view` es prerrequisito** de las demás acciones del módulo por el anidamiento de grupos de rutas (sección 5).
3. **Eliminación real de usuarios.** `UserController@destroy` ejecuta `delete()`. Antes de agregar módulos que guarden referencias a usuarios (por ejemplo `created_by`), decide entre `SoftDeletes` o pasar al usuario a estatus `Baja` para no dejar registros huérfanos.
4. **El rol `Administrador` es editable.** Si se renombra o elimina desde la interfaz, el seeder lo vuelve a crear con todos los permisos al ejecutarse de nuevo.
5. **Rate limiting del login por `login|ip`.** Un atacante que pruebe muchos usuarios distintos desde una misma IP no alcanza el límite por clave. Si el proyecto lo requiere, agrega un segundo limitador solo por IP con un umbral más alto.
6. **Recuperación de contraseña.** Las respuestas de `/forgot-password` y `/reset-password` ya son genéricas (ver sección 2). Queda únicamente la diferencia de tiempo entre enviar y no enviar el correo; se cierra enviando la notificación por cola. Si la instalación no usa correo saliente, desactiva esas rutas.
7. **SQLite en pruebas vs. MySQL en producción.** Ver nota de la sección 6.
8. **Mensajes en controladores.** Algunos mensajes de éxito/error de `UserController` y `RoleController` están escritos directamente en español. Si el kit se usará en más de un idioma, muévelos a `lang/es/users.php` y `lang/es/roles.php`.
