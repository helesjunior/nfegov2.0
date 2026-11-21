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
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
                // Modo exclusivo do Google: trocar o e-mail para fora do domínio
                // reabriria o login por senha. O super-admin é a exceção.
                function (string $atributo, mixed $valor, \Closure $falhar) use ($user): void {
                    if (
                        \App\Support\GoogleLogin::exclusivo()
                        && ! $user->hasRole('super-admin')
                        && (string) $valor !== (string) $user->email
                    ) {
                        $falhar('O e-mail desta conta é gerenciado pelo Google e não pode ser alterado.');
                    }
                },
            ],
            'current_password' => [Rule::requiredIf($emailMudou), 'nullable', 'current_password'],
        ];
    }
}