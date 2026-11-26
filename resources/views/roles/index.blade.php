<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Roles y Permisos') }}
            </h2>

            @can('roles.create')
                <x-primary-button
                    x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'create-role')"
                >
                    {{ __('+ Nuevo rol') }}
                </x-primary-button>
            @endcan
        </div>
    </x-slot>

    <div class="py-12"
         x-data="{
             createPermissions: @js(old('_modal') === 'create-role' ? old('permissions', []) : []),
             editRole: {
                 id: '{{ old('role_id') }}',
                 name: '{{ old('name') }}',
                 permissions: @js(old('_modal') === 'edit-role' ? old('permissions', []) : [])
             },
             openEdit(role) {
                 this.editRole = {
                     id: role.id,
                     name: role.name,
                     permissions: [...role.permissions]
                 };
                 $dispatch('open-modal', 'edit-role');
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
                <form method="GET" action="{{ route('roles.index') }}" class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <x-text-input
                            type="text"
                            name="search"
                            :value="$search"
                            placeholder="Buscar rol por nombre..."
                            class="w-full"
                        />
                    </div>
                    <div class="flex gap-2">
                        <x-primary-button type="submit">
                            {{ __('Buscar') }}
                        </x-primary-button>

                        @if ($search !== '')
                            <a href="{{ route('roles.index') }}"
                               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50">
                                {{ __('Limpiar') }}
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            <!-- Tabla de Roles -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Permisos asignados</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuarios</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($roles as $role)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">{{ $role->name }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            {{ $role->permissions->count() }} {{ $role->permissions->count() === 1 ? 'permiso' : 'permisos' }}
                                        </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $role->users_count }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                    @can('roles.edit')
                                        <button type="button"
                                                x-on:click="openEdit({
                                                        id: {{ $role->id }},
                                                        name: @js($role->name),
                                                        permissions: @js($role->permissions->pluck('name')->values()->all())
                                                    })"
                                                class="text-gray-700 hover:text-black underline">
                                            Editar
                                        </button>
                                    @endcan

                                    @can('roles.delete')
                                        <form method="POST"
                                              action="{{ route('roles.destroy', $role) }}"
                                              class="inline"
                                              onsubmit="return confirm('¿Seguro que deseas eliminar el rol {{ $role->name }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800 underline">
                                                Eliminar
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">
                                    No hay roles registrados.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($roles->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $roles->links() }}
                    </div>
                @endif
            </div>
        </div>

        <!-- MODAL: CREAR ROL -->
        @can('roles.create')
            <x-modal name="create-role" maxWidth="2xl" :show="$errors->any() && old('_modal') === 'create-role'" focusable>
                <form method="POST" action="{{ route('roles.store') }}" class="p-6">
                    @csrf
                    <input type="hidden" name="_modal" value="create-role">

                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Nuevo Rol') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Define el nombre del rol y marca los módulos o acciones a los que tendrá acceso.') }}
                    </p>

                    <div class="mt-6 space-y-5">
                        <!-- Nombre del rol -->
                        <div>
                            <x-input-label for="create_role_name" :value="__('Nombre del rol')" />
                            <x-text-input id="create_role_name" name="name" type="text" class="mt-1 block w-full"
                                          :value="old('_modal') === 'create-role' ? old('name') : ''"
                                          placeholder="Ej. Supervisor, Capturista, Auditor" required />
                            @if (old('_modal') === 'create-role')
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Árbol de permisos -->
                        <div>
                            <x-input-label :value="__('Permisos por módulo')" />
                            @include('roles.partials.permission-tree', [
                                'permissionTree' => $permissionTree,
                                'moduleLabels'   => $moduleLabels,
                                'actionLabels'   => $actionLabels,
                                'modelVar'       => 'createPermissions',
                            ])
                            @if (old('_modal') === 'create-role')
                                <x-input-error :messages="$errors->get('permissions')" class="mt-2" />
                            @endif
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancelar') }}
                        </x-secondary-button>
                        <x-primary-button>
                            {{ __('Guardar rol') }}
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endcan

        <!-- MODAL: EDITAR ROL -->
        @can('roles.edit')
            <x-modal name="edit-role" maxWidth="2xl" :show="$errors->any() && old('_modal') === 'edit-role'" focusable>
                <form method="POST" :action="`{{ url('/roles') }}/${editRole.id}`" class="p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_modal" value="edit-role">
                    <input type="hidden" name="role_id" x-model="editRole.id">

                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Editar Rol') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Modifica el nombre o los permisos asignados a este rol.') }}
                    </p>

                    <div class="mt-6 space-y-5">
                        <!-- Nombre del rol -->
                        <div>
                            <x-input-label for="edit_role_name" :value="__('Nombre del rol')" />
                            <x-text-input id="edit_role_name" name="name" type="text" class="mt-1 block w-full"
                                          x-model="editRole.name" required />
                            @if (old('_modal') === 'edit-role')
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            @endif
                        </div>

                        <!-- Árbol de permisos -->
                        <div>
                            <x-input-label :value="__('Permisos por módulo')" />
                            @include('roles.partials.permission-tree', [
                                'permissionTree' => $permissionTree,
                                'moduleLabels'   => $moduleLabels,
                                'actionLabels'   => $actionLabels,
                                'modelVar'       => 'editRole.permissions',
                            ])
                            @if (old('_modal') === 'edit-role')
                                <x-input-error :messages="$errors->get('permissions')" class="mt-2" />
                            @endif
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancelar') }}
                        </x-secondary-button>
                        <x-primary-button>
                            {{ __('Actualizar rol') }}
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endcan
    </div>
</x-app-layout>
