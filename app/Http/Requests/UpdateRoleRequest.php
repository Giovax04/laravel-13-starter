<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $targetRole = $this->route('role');

        return $this->user()->can('roles.edit') && $targetRole->name !== 'SuperAdmin';
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
        $roleId = $this->route('role')->id;

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (strcasecmp(trim((string) $value), 'SuperAdmin') === 0) {
                        $fail('El nombre de rol SuperAdmin está reservado por el sistema.');
                    }
                },
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'web'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un rol registrado con ese nombre.',
        ];
    }
}
