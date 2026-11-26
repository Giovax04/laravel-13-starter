<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('roles.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
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
                'unique:roles,name',
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
