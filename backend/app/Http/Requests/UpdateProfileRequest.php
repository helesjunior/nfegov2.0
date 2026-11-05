<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();
        // Comparação EXATA: alterar só a caixa também afeta a credencial de
        // login (Postgres é case-sensitive), então exige a senha atual.
        $emailMudou = (string) $this->input('email') !== (string) $user->email;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => [Rule::requiredIf($emailMudou), 'nullable', 'current_password'],
        ];
    }
}