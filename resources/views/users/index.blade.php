<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Usuarios') }}
            </h2>

            @can('users.create')
                <x-primary-button
                    x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'create-user')"
                >
                    {{ __('+ Nuevo usuario') }}
                </x-primary-button>
            @endcan
        </div>
    </x-slot>

    <div class="py-12"
         x-data="{
             editUser: {
                 id: '{{ old('user_id') }}',
                 name: '{{ old('name') }}',
                 username: '{{ old('username') }}',
                 email: '{{ old('email') }}',
                 status: '{{ old('status', 'activo') }}',
                 role: '{{ old('role') }}'
             },
             openEdit(user) {
                 this.editUser = {
                     id: user.id,
                     name: user.name,
                     username: user.username,
                     email: user.email,
                     status: user.status,
                     role: user.role
                 };
                 $dispatch('open-modal', 'edit-user');
             }
         }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <!-- Mensajes Flash -->
            @if (session('success'))
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            <!-- Buscador -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="GET" action="{{ route('users.index') }}" class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <x-text-input
                            type="text"
                            name="search"
                            :value="$search"
                            placeholder="Buscar por nombre, clave de usuario o correo..."
                            class="w-full"
                        />
                    </div>
                    <div class="flex gap-2">
                        <x-primary-button type="submit">
                            {{ __('Buscar') }}
                        </x-primary-button>

                        @if ($search !== '')
                            <a href="{{ route('users.index') }}"
                               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50">
                                {{ __('Limpiar') }}
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            <!-- Tabla de Usuarios -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuario</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Clave</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estatus</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($users as $user)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $user->name }}</div>
                                    <div class="text-sm text-gray-500">{{ $user->email }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="font-mono text-sm text-gray-800 uppercase">{{ $user->username }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            {{ $user->getRoleNames()->first() ?? 'Sin rol' }}
                                        </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $user->status->badgeColor() }}">
                                            {{ $user->status->label() }}
                                        </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                    @can('users.edit')
                                        <button type="button"
                                                x-on:click="openEdit({
                                                        id: {{ $user->id }},
                                                        name: @js($user->name),
                                                        username: @js($user->username),
                                                        email: @js($user->email),
                                                        status: @js($user->status->value),
                                                        role: @js($user->getRoleNames()->first() ?? '')
                                                    })"
                                                class="text-gray-700 hover:text-black underline">
                                            Editar
                                        </button>
                                    @endcan

                                    @can('users.delete')
                                        @if (auth()->id() !== $user->id)
                                            <form method="POST"
                                                  action="{{ route('users.destroy', $user) }}"
                                                  class="inline"
                                                  onsubmit="return confirm('¿Seguro que deseas eliminar a {{ $user->name }}?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-800 underline">
                                                    Eliminar
                                                </button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                                    No hay usuarios registrados.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($users->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $users->links() }}
                    </div>
                @endif
            </div>
        </div>

        <!-- MODAL: CREAR USUARIO -->
        @can('users.create')
            <x-modal name="create-user" :show="$errors->any() && old('_modal') === 'create-user'" focusable>
                <form method="POST" action="{{ route('users.store') }}" class="p-6">
                    @csrf
                    <input type="hidden" name="_modal" value="create-user">

                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Nuevo Usuario') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Completa los datos de acceso y asigna un rol del sistema.') }}
                    </p>

                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Nombre -->
                        <div class="sm:col-span-2">
                            <x-input-label for="create_name" :value="__('Nombre completo')" />
                            <x-text-input id="create_name" name="name" type="text" class="mt-1 block w-full"
                                          :value="old('_modal') === 'create-user' ? old('name') : ''" required />
                            @if (old('_modal') === 'create-user')
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Username -->
                        <div>
                            <x-input-label for="create_username" :value="__('Clave de usuario (máx. 10)')" />
                            <x-text-input id="create_username" name="username" type="text" maxlength="10" class="mt-1 block w-full"
                                          :value="old('_modal') === 'create-user' ? old('username') : ''" required />
                            @if (old('_modal') === 'create-user')
                                <x-input-error :messages="$errors->get('username')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Email -->
                        <div>
                            <x-input-label for="create_email" :value="__('Correo electrónico')" />
                            <x-text-input id="create_email" name="email" type="email" class="mt-1 block w-full"
                                          :value="old('_modal') === 'create-user' ? old('email') : ''" required />
                            @if (old('_modal') === 'create-user')
                                <x-input-error :messages="$errors->get('email')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Rol -->
                        <div>
                            <x-input-label for="create_role" :value="__('Rol')" />
                            <select id="create_role" name="role" required
                                    class="mt-1 block w-full border-gray-300 focus:border-gray-900 focus:ring-gray-900 rounded-md shadow-sm">
                                <option value="">Selecciona un rol...</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->name }}"
                                        @selected(old('_modal') === 'create-user' && old('role') === $role->name)>
                                        {{ $role->name }}
                                    </option>
                                @endforeach
                            </select>
                            @if (old('_modal') === 'create-user')
                                <x-input-error :messages="$errors->get('role')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Estatus -->
                        <div>
                            <x-input-label for="create_status" :value="__('Estatus')" />
                            <select id="create_status" name="status" required
                                    class="mt-1 block w-full border-gray-300 focus:border-gray-900 focus:ring-gray-900 rounded-md shadow-sm">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status->value }}"
                                        @selected(old('_modal') === 'create-user' ? old('status') === $status->value : $status->value === 'activo')>
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @if (old('_modal') === 'create-user')
                                <x-input-error :messages="$errors->get('status')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Contraseña -->
                        <div>
                            <x-input-label for="create_password" :value="__('Contraseña')" />
                            <x-text-input id="create_password" name="password" type="password" class="mt-1 block w-full" required />
                            @if (old('_modal') === 'create-user')
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Confirmar Contraseña -->
                        <div>
                            <x-input-label for="create_password_confirmation" :value="__('Confirmar contraseña')" />
                            <x-text-input id="create_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required />
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancelar') }}
                        </x-secondary-button>
                        <x-primary-button>
                            {{ __('Guardar usuario') }}
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endcan

        <!-- MODAL: EDITAR USUARIO -->
        @can('users.edit')
            <x-modal name="edit-user" :show="$errors->any() && old('_modal') === 'edit-user'" focusable>
                <form method="POST" :action="`{{ url('/users') }}/${editUser.id}`" class="p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_modal" value="edit-user">
                    <input type="hidden" name="user_id" x-model="editUser.id">

                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Editar Usuario') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Actualiza la información del usuario. Deja la contraseña en blanco si no deseas cambiarla.') }}
                    </p>

                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Nombre -->
                        <div class="sm:col-span-2">
                            <x-input-label for="edit_name" :value="__('Nombre completo')" />
                            <x-text-input id="edit_name" name="name" type="text" class="mt-1 block w-full"
                                          x-model="editUser.name" required />
                            @if (old('_modal') === 'edit-user')
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Username -->
                        <div>
                            <x-input-label for="edit_username" :value="__('Clave de usuario (máx. 10)')" />
                            <x-text-input id="edit_username" name="username" type="text" maxlength="10" class="mt-1 block w-full"
                                          x-model="editUser.username" required />
                            @if (old('_modal') === 'edit-user')
                                <x-input-error :messages="$errors->get('username')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Email -->
                        <div>
                            <x-input-label for="edit_email" :value="__('Correo electrónico')" />
                            <x-text-input id="edit_email" name="email" type="email" class="mt-1 block w-full"
                                          x-model="editUser.email" required />
                            @if (old('_modal') === 'edit-user')
                                <x-input-error :messages="$errors->get('email')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Rol -->
                        <div>
                            <x-input-label for="edit_role" :value="__('Rol')" />
                            <select id="edit_role" name="role" x-model="editUser.role" required
                                    class="mt-1 block w-full border-gray-300 focus:border-gray-900 focus:ring-gray-900 rounded-md shadow-sm">
                                <option value="">Selecciona un rol...</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                            @if (old('_modal') === 'edit-user')
                                <x-input-error :messages="$errors->get('role')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Estatus -->
                        <div>
                            <x-input-label for="edit_status" :value="__('Estatus')" />
                            <select id="edit_status" name="status" x-model="editUser.status" required
                                    class="mt-1 block w-full border-gray-300 focus:border-gray-900 focus:ring-gray-900 rounded-md shadow-sm">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </select>
                            @if (old('_modal') === 'edit-user')
                                <x-input-error :messages="$errors->get('status')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Nueva Contraseña (opcional) -->
                        <div>
                            <x-input-label for="edit_password" :value="__('Nueva contraseña (opcional)')" />
                            <x-text-input id="edit_password" name="password" type="password" class="mt-1 block w-full" />
                            @if (old('_modal') === 'edit-user')
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Confirmar Contraseña -->
                        <div>
                            <x-input-label for="edit_password_confirmation" :value="__('Confirmar contraseña')" />
                            <x-text-input id="edit_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" />
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancelar') }}
                        </x-secondary-button>
                        <x-primary-button>
                            {{ __('Actualizar usuario') }}
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endcan
    </div>
</x-app-layout>
