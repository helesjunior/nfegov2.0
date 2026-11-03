<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros da listagem de NF-e (`GET /api/nfes`).
 *
 * Todos os filtros são opcionais. Datas no formato Y-m-d; valores numéricos.
 * A paginação é validada com limites para proteger o servidor.
 */
class IndexNfeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorização via Policy/Gate no controller.
    }

    public function rules(): array
    {
        return [
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
            'unidade_id' => ['nullable', 'integer'],
            'emitente_cnpj' => ['nullable', 'string', 'max:14'],
            'valor_min' => ['nullable', 'numeric'],
            'valor_max' => ['nullable', 'numeric'],
            'chave' => ['nullable', 'string', 'max:44'],
            'q' => ['nullable', 'string', 'max:120'],
            'formato' => ['nullable', 'string', 'in:csv,xlsx'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}