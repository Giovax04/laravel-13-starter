# Laravel 13 Starter Kit

Plantilla base limpia y segura para aplicaciones administrativas e internas (B2B) desarrollada con **Laravel 13**, **PHP 8.5**, **Laravel Breeze** (Blade + Tailwind CSS + Alpine.js) y **Spatie Laravel Permission**, lista para ejecutarse sobre **Docker / Laravel Sail** o entornos locales.

## Características Principales

- **Autenticación Dual (`email` o `username`):** Configurable mediante `AUTH_LOGIN_FIELD` (`both`, `email`, `username`), con validación dinámica, normalización a minúsculas y prevención de enumeración de cuentas (error genérico y tiempo de respuesta equivalente para usuarios inexistentes). La recuperación de contraseña (`/forgot-password` y `/reset-password`) también responde igual exista o no el correo.
- **Control de Estatus en 3 Capas (`App\Enums\UserStatus`):**
  1. Bloqueo de acceso en login para cuentas en estado `Baja` o `Bloqueado` (solo se informa el motivo tras validar credenciales).
  2. Registro público desactivado por defecto (`AUTH_ALLOW_REGISTRATION=false`), controlado en cada petición por el middleware `registration.enabled`.
  3. Expulsión inmediata de sesión activa mediante el middleware `EnsureUserIsActive`.
- **Roles y Permisos (`spatie/laravel-permission`):**
  - Convención plana `modulo.accion` (`users.view`, `roles.create`, etc.).
  - Rol maestro `SuperAdmin` parametrizado desde `.env` (`config('auth.superadmin.*')`), oculto en listados y protegido contra edición, eliminación y auto-borrado. Nadie puede crear, renombrar ni asignar el rol `SuperAdmin` desde la interfaz.
  - Árbol de permisos agrupado dinámicamente por módulo con selección maestra en Alpine.js y traducciones en `config/permissions.php`.
- **Gestión de Usuarios Segura:** un usuario no puede cambiar su propio estatus ni su propio rol, ni eliminar su propia cuenta desde el módulo de Usuarios.
- **Gestión de Perfil Segura:** `username` de solo lectura (ignorado por el servidor aunque se envíe manualmente) y auto-eliminación de cuenta controlada por `AUTH_ALLOW_ACCOUNT_DELETION=false`.
- **Seeder Idempotente y Seguro:** `RoleAndPermissionSeeder` puede ejecutarse varias veces sin duplicar datos, no pisa la personalización del rol `Administrador`, nunca promueve a un usuario existente a `SuperAdmin` y valida la configuración del usuario maestro antes de tocar la base de datos.
- **Localización en Español (`es` / `es_MX`):** Mensajes de validación, interfaz y datos de prueba en español.
- **Suite de Pruebas Automatizadas:** Pruebas de seguridad y control de acceso en `tests/Feature/StarterKitSecurityTest.php`, más las pruebas de Breeze adaptadas (login dual, registro con `username`), con nombres en español y ejecución sobre SQLite en memoria.

## Variables de Entorno Clave

| Variable                      | Valor por defecto       | Descripción                                                                                      |
| ----------------------------- | ----------------------- | ------------------------------------------------------------------------------------------------ |
| `AUTH_LOGIN_FIELD`            | `both`                  | Modo de acceso: `both` (correo o usuario), `email` o `username`                                  |
| `AUTH_ALLOW_REGISTRATION`     | `false`                 | Habilita o deshabilita el registro público (`/register` responde `404` si está desactivado)      |
| `AUTH_ALLOW_ACCOUNT_DELETION` | `false`                 | Habilita o deshabilita la auto-eliminación de cuenta en `/profile`                               |
| `SUPERADMIN_NAME`             | `Administrador General` | Nombre visible de la cuenta maestra inicial                                                      |
| `SUPERADMIN_USERNAME`         | `superadmin`            | Clave de usuario maestra (alfanumérica ASCII, máx. 10 caracteres)                                |
| `SUPERADMIN_EMAIL`            | `admin@sistema.local`   | Correo de la cuenta maestra inicial                                                              |
| `SUPERADMIN_PASSWORD`         | `password`              | Contraseña inicial (fuera de `local` y `testing` exige >= 12 caracteres distintos de `password`) |

> **Importante:** las variables `SUPERADMIN_*` solo se usan **la primera vez**, cuando todavía no existe ningún usuario con el rol `SuperAdmin`. Cambiarlas después y volver a ejecutar el seeder no modifica la cuenta existente; para eso edita el usuario desde la base de datos o con `tinker`. Si el `SUPERADMIN_USERNAME` o el correo ya pertenecen a otro usuario, el seeder se detiene con un error en lugar de promoverlo.

## Instalación en un Proyecto Nuevo

> **Nota sobre Git:** Si creaste tu repositorio usando el botón **"Use this template"** en GitHub, ya tendrás un historial limpio. Si clonaste directamente con `git clone`, elimina la carpeta `.git` e inicializa tu propio repositorio antes de empezar:
>
> ```bash
> # Linux / macOS / WSL2
> rm -rf .git && git init && git branch -M main
>
> # Windows PowerShell
> Remove-Item -Recurse -Force .git; git init; git branch -M main
> ```

Antes de ejecutar `migrate:fresh --seed`, **revisa y personaliza las variables `SUPERADMIN_*` en tu `.env`**.

### Opción A: Docker / Sail en Windows (PowerShell nativo sin WSL2)

```powershell
# 1. Clonar el repositorio en la carpeta de tu nuevo proyecto
git clone https://github.com/Giovax04/laravel-13-starter mi-proyecto
cd mi-proyecto

# 2. Instalar dependencias PHP con Composer vía Docker
docker run --rm -v "${PWD}:/app" -w /app composer install

# 3. Copiar archivo de entorno y levantar contenedores
copy .env.example .env
docker compose up -d

# 4. Generar clave, migrar con datos iniciales y compilar assets
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate:fresh --seed
docker compose exec laravel.test npm install
docker compose exec laravel.test npm run build
```

*(Nota Windows NTFS: Si ocurre `tempnam(): file created in the system's temporary directory`, agrega `VIEW_COMPILED_PATH=/tmp` en tu `.env` local).*

### Opción B: Docker / Sail en Linux / macOS / WSL2

```bash
# 1. Clonar el repositorio en la carpeta de tu nuevo proyecto
git clone https://github.com/Giovax04/laravel-13-starter mi-proyecto
cd mi-proyecto

# 2. Instalar dependencias PHP con Composer vía Docker
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php85-composer:latest composer install

# 3. Copiar entorno y levantar Sail
cp .env.example .env
./vendor/bin/sail up -d

# 4. Generar clave, migrar con seeders y compilar assets
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

### Opción C: Entorno Local sin Docker (PHP 8.5 + Composer + Node.js)

Si utilizas **Laravel Herd, Laragon, XAMPP** o una instalación nativa de PHP 8.5, MySQL/SQLite y Node.js:

```bash
# 1. Clonar el repositorio en la carpeta de tu nuevo proyecto
git clone https://github.com/Giovax04/laravel-13-starter mi-proyecto
cd mi-proyecto

# 2. Instalar dependencias de PHP y Node.js
composer install
npm install

# 3. Configurar entorno (ajusta DB_CONNECTION / DB_DATABASE en .env)
cp .env.example .env
php artisan key:generate

# 4. Ejecutar migraciones con datos iniciales y compilar assets
php artisan migrate:fresh --seed
npm run build
```

### Primer acceso

Ingresa a `/login` con la clave de usuario (`SUPERADMIN_USERNAME`) o el correo (`SUPERADMIN_EMAIL`) y la contraseña que definiste en `.env`. Si dejaste los valores de ejemplo (solo recomendable en desarrollo), cámbialos antes de publicar el sistema.

## Ejecución de Pruebas

Las pruebas corren sobre una base de datos **SQLite en memoria** (configurada en `phpunit.xml`), por lo que **no tocan tu base de datos de desarrollo**. `phpunit.xml` también fija el idioma (`es`) y las variables `AUTH_*` y `SUPERADMIN_*`, de modo que los resultados no dependen del `.env` de cada desarrollador.

```powershell
# Windows PowerShell
docker compose exec laravel.test php artisan test
```

```bash
# Linux / macOS / WSL2
./vendor/bin/sail artisan test
```

Para ejecutar solo la suite de seguridad:

```bash
php artisan test --filter=StarterKitSecurityTest
```

> Requiere la extensión `pdo_sqlite` de PHP (incluida en la imagen de Sail). En instalaciones locales verifica que aparezca en `php -m`.

## Estilo de Código

El proyecto sigue el estilo de [Laravel Pint](https://laravel.com/docs/pint):

```bash
# Windows PowerShell
docker compose exec laravel.test ./vendor/bin/pint

# Linux / macOS / WSL2
./vendor/bin/sail pint
```

## Antes de Publicar en Producción

- `APP_ENV=production` y `APP_DEBUG=false`.
- `SUPERADMIN_PASSWORD` robusta (>= 12 caracteres y distinta de `password`); el seeder se detiene si no se cumple.
- `AUTH_ALLOW_REGISTRATION=false` y `AUTH_ALLOW_ACCOUNT_DELETION=false`, salvo que el proyecto lo requiera.
- Configurar el correo saliente (`MAIL_*`) para que funcione la recuperación de contraseña. Si tu instalación no usa correo, desactiva las rutas `forgot-password` y `reset-password`.
- Ejecutar `php artisan config:cache`, `route:cache` y `view:cache` en el despliegue.
- Si cambias el costo de hash (`BCRYPT_ROUNDS`), ejecuta `php artisan cache:clear` para regenerar el hash de referencia del login.

## Documentación Técnica

Consulta [`docs/GUIA_TECNICA.md`](docs/GUIA_TECNICA.md) para revisar la arquitectura interna, las decisiones de seguridad, la suite de pruebas y el paso a paso para agregar nuevos módulos CRUD y permisos personalizados.
