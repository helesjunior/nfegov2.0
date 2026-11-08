<?php

namespace App\Http\Requests;

use App\Domain\Nfse\NfseQuery;
use App\Support\Ordenacao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros da listagem de NFS-e (`GET /api/nfses`). Todos opcionais.
 * `data` escolhe se o período vale para a emissão ou a competência.
 */
class IndexNfseRequest extends FormRequest
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
            'data' => ['nullable', 'string', 'in:emissao,competencia'],
            'unidade_id' => ['nullable', 'integer'],
            'papel' => ['nullable', 'string', 'in:tomador,prestador,intermediario,outro'],
            'situacao' => ['nullable', 'string', 'in:normal,cancelada,substituida'],
            'prestador_documento' => ['nullable', 'string', 'max:40'],
            'valor_min' => ['nullable', 'numeric'],
            'valor_max' => ['nullable', 'numeric'],
            'chave' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:120'],
            'ordem' => ['nullable', 'string', Rule::in(array_keys(NfseQuery::ORDENACOES))],
            'direcao' => ['nullable', 'string', Rule::in(Ordenacao::DIRECOES)],
            'formato' => ['nullable', 'string', 'in:csv,xlsx'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
