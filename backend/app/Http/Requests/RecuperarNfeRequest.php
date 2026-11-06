<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedido de recuperação manual de NF-e (`POST /api/nfes/recuperar`).
 *
 * A busca é feita por UM dos parâmetros, nunca por ambos:
 * - `chave`: chave de acesso completa (44 dígitos) → `consChNFe`.
 * - `nsu`: NSU específico (>= 1) → `consNSU`.
 *
 * NSU 0 fica DE FORA de propósito: no sped-nfe, `numNSU = 0` significa "sem
 * consulta por NSU" e cai para a varredura incremental, o oposto do que se
 * quer aqui.
 *
 * A autorização (permissão + escopo da unidade) é feita no controller.
 */
class RecuperarNfeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorização via Gate no controller.
    }

    public function rules(): array
    {
        return [
            'unidade_id' => ['required', 'integer'],
            'chave' => ['nullable', 'digits:44', 'required_without:nsu', 'prohibits:nsu'],
            'nsu' => ['nullable', 'integer', 'min:1', 'required_without:chave', 'prohibits:chave'],
        ];
    }

    public function messages(): array
    {
        return [
            'chave.digits' => 'A chave de acesso deve ter exatamente 44 dígitos.',
            'chave.required_without' => 'Informe a chave de acesso ou o NSU.',
            'chave.prohibits' => 'Informe apenas a chave OU o NSU, não os dois.',
            'nsu.min' => 'O NSU deve ser maior ou igual a 1.',
            'nsu.required_without' => 'Informe a chave de acesso ou o NSU.',
            'nsu.prohibits' => 'Informe apenas a chave OU o NSU, não os dois.',
        ];
    }
}