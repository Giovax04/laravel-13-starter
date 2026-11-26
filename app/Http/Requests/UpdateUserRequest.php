<?php

namespace App\Http\Requests;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $targetUser = $this->route('user');

        return $this->user()->can('users.edit') && ! $targetUser->hasRole('SuperAdmin');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => Str::lower(trim((string) $this->input('username'))),
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        $targetUser = $this->route('user');
        $userId = $targetUser->id;

        $statusRules = ['required', new Enum(UserStatus::class)];

        $roleRules = [
            'required',
            'string',
            Rule::exists('roles', 'name')
                ->where('guard_name', 'web')
                ->whereNot('name', 'SuperAdmin'),
        ];

        // Al editarse a sí mismo no puede cambiar su propio estatus ni su rol,
        // para evitar bloquearse o escalar privilegios por accidente.
        if ($this->user()->is($targetUser)) {
            $statusRules[] = Rule::in([UserStatus::Activo->value]);

            $currentRoles = $this->user()->getRoleNames()->all();

            if ($currentRoles !== []) {
                $roleRules[] = Rule::in($currentRoles);
            }
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'alpha_num:ascii',
                'max:10',
                Rule::unique(User::class)->ignore($userId),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($userId),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'status' => $statusRules,
            'role' => $roleRules,
        ];
    }

    public function messages(): array
    {
        return [
            'username.alpha_num' => 'La clave de usuario solo puede contener letras y números sin espacios.',
            'username.max' => 'La clave de usuario no debe exceder los 10 caracteres.',
            'role.exists' => 'El rol seleccionado no es válido.',
            'role.in' => 'No puedes cambiar tu propio rol.',
            'status.in' => 'No puedes cambiar tu propio estatus.',
        ];
    }
}
