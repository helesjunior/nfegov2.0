<?php

namespace App\Http\Requests;

use App\Models\Unidade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro de unidade gestora.
 *
 * O CNPJ é único DENTRO do tenant (o mesmo CNPJ pode existir em órgãos
 * diferentes). Ativo por padrão.
 */
class StoreUnidadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorização via Policy no controller.
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'nome_resumido' => ['nullable', 'string', 'max:255'],
            'codigo_unidade' => ['nullable', 'string', 'max:255'],
            'cnpj' => [
                'required', 'string', 'size:14', 'regex:/^\d{14}$/',
                // Único no tenant ativo (o global scope restringe a checagem).
                Rule::unique(Unidade::class, 'cnpj'),
            ],
            'ie' => ['nullable', 'string', 'max:20'],
            'im' => ['nullable', 'string', 'max:20'],
            'uf' => ['required', 'string', 'size:2'],
            'tp_amb' => ['nullable', 'integer', 'in:1,2'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.unique' => 'Já existe uma unidade com este CNPJ nesta entidade.',
        ];
    }
}