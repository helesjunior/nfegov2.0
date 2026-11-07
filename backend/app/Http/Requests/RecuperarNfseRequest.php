<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Busca manual de NFS-e por chave (`POST /api/nfses/recuperar`).
 * A autorização (permissão e escopo da unidade) é feita no controller.
 */
class RecuperarNfseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unidade_id' => ['required', 'integer'],
            'chave' => ['required', 'digits:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'chave.required' => 'Informe a chave de acesso da NFS-e.',
            'chave.digits' => 'A chave de acesso da NFS-e deve ter exatamente 50 dígitos.',
        ];
    }
}
