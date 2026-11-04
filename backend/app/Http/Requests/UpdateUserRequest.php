<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $alvo = $this->route('usuario');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($alvo?->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'papel' => ['required', Rule::in(['super-admin', 'admin-tenant', 'operador', 'consulta'])],
            'tenants' => ['present', 'array'],
            'tenants.*' => ['integer', Rule::exists('tenants', 'id')],
        ];
    }
}
