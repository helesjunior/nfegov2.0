<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manifestação do tomador (`POST /api/nfses/{chave}/manifestacao`).
 *
 * `acao`: `confirmar` ou `rejeitar`. A rejeição exige `motivo` (1 a 5 ou 9);
 * `descricao` tem de 15 a 255 caracteres e é obrigatória no motivo 9.
 */
class ManifestarNfseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unidade_id' => ['required', 'integer'],
            'acao' => ['required', 'in:confirmar,rejeitar'],
            'motivo' => ['required_if:acao,rejeitar', 'nullable', 'integer', 'in:1,2,3,4,5,9'],
            'descricao' => ['nullable', 'string', 'min:15', 'max:255', 'required_if:motivo,9'],
        ];
    }

    public function messages(): array
    {
        return [
            'acao.in' => 'A ação deve ser "confirmar" ou "rejeitar".',
            'motivo.required_if' => 'Informe o motivo da rejeição.',
            'motivo.in' => 'Motivo da rejeição inválido.',
            'descricao.min' => 'A descrição do motivo deve ter de 15 a 255 caracteres.',
            'descricao.max' => 'A descrição do motivo deve ter de 15 a 255 caracteres.',
            'descricao.required_if' => 'Para o motivo "Outros", descreva o motivo (de 15 a 255 caracteres).',
        ];
    }
}
