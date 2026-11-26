@props([
    'permissionTree',
    'moduleLabels' => [],
    'actionLabels' => [],
    'modelVar', // Nombre de la variable Alpine en el modal (ej. 'createPermissions' o 'editRole.permissions')
])

<div class="mt-2 max-h-80 overflow-y-auto border border-gray-200 rounded-md divide-y divide-gray-200 bg-gray-50/50">
    @forelse ($permissionTree as $moduleKey => $permissions)
        @php
            $moduleName = $moduleLabels[$moduleKey] ?? ucfirst($moduleKey);
            $modulePermNames = $permissions->pluck('name')->values()->all();
        @endphp

        <div class="p-4 bg-white"
             x-data="{
                 modulePerms: @js($modulePermNames),
                 get allSelected() {
                     return this.modulePerms.length > 0 && this.modulePerms.every(p => {{ $modelVar }}.includes(p));
                 },
                 get someSelected() {
                     return this.modulePerms.some(p => {{ $modelVar }}.includes(p)) && !this.allSelected;
                 },
                 toggleModule(checked) {
                     if (checked) {
                         this.modulePerms.forEach(p => {
                             if (!{{ $modelVar }}.includes(p)) {
                                 {{ $modelVar }}.push(p);
                             }
                         });
                     } else {
                         {{ $modelVar }} = {{ $modelVar }}.filter(p => !this.modulePerms.includes(p));
                     }
                 }
             }">

            <!-- Nodo Padre: Módulo -->
            <label class="flex items-center gap-2.5 cursor-pointer select-none">
                <input type="checkbox"
                       :checked="allSelected"
                       x-effect="$el.indeterminate = someSelected"
                       x-on:change="toggleModule($event.target.checked)"
                       class="rounded border-gray-300 text-gray-900 shadow-sm focus:ring-gray-900">
                <span class="text-sm font-semibold text-gray-900">
                    Módulo: {{ $moduleName }}
                </span>
                <span class="text-xs text-gray-400">
                    ({{ count($modulePermNames) }})
                </span>
            </label>

            <!-- Nodos Hijos: Acciones del módulo -->
            <div class="mt-3 ml-6 pl-4 border-l-2 border-gray-200 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                @foreach ($permissions as $permission)
                    @php
                        $actionKey = explode('.', $permission->name)[1] ?? $permission->name;
                        $actionLabel = $actionLabels[$actionKey] ?? ucfirst($actionKey);
                    @endphp

                    <label class="flex items-start gap-2 cursor-pointer select-none">
                        <input type="checkbox"
                               name="permissions[]"
                               value="{{ $permission->name }}"
                               x-model="{{ $modelVar }}"
                               class="mt-0.5 rounded border-gray-300 text-gray-900 shadow-sm focus:ring-gray-900">
                        <div class="leading-tight">
                            <span class="block text-sm text-gray-700">{{ $actionLabel }}</span>
                            <span class="block font-mono text-[11px] text-gray-400">{{ $permission->name }}</span>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>
    @empty
        <div class="p-4 text-sm text-gray-500 text-center">
            No hay permisos registrados en el sistema.
        </div>
    @endforelse
</div>
