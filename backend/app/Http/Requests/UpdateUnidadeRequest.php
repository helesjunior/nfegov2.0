<?php

namespace App\Http\Requests;

use App\Models\Unidade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edição de unidade. O `tenant_id` NÃO é editável (mover entidade é proibido).
 */
class UpdateUnidadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $alvo = $this->route('unidade');
        $alvoId = $alvo instanceof Unidade ? $alvo->id : $alvo;

        return [
            'nome' => ['required', 'string', 'max:255'],
            'nome_resumido' => ['nullable', 'string', 'max:255'],
            'codigo_unidade' => ['nullable', 'string', 'max:255'],
            'cnpj' => [
                'required', 'string', 'size:14', 'regex:/^\d{14}$/',
                Rule::unique(Unidade::class, 'cnpj')->ignore($alvoId),
            ],
            'ie' => ['nullable', 'string', 'max:20'],
            'im' => ['nullable', 'string', 'max:20'],
            'uf' => ['required', 'string', 'size:2'],
            'tp_amb' => ['nullable', 'integer', 'in:1,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.unique' => 'Já existe uma unidade com este CNPJ nesta entidade.',
        ];
    }
}