<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do upload de certificado e-CNPJ.
 *
 * Aceita apenas .pfx/.p12 até um tamanho máximo (padrão 5 MB, configurável).
 * A senha é obrigatória e nunca é registrada em logs.
 */
class StoreCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorização via policy no controller
    }

    public function rules(): array
    {
        $maxKb = (int) config('nfegov.certificado.max_kb', 5120);

        return [
            'arquivo' => [
                'required',
                'file',
                // PFX/PKCS#12 é binário; validamos a extensão (robusto) e o
                // tamanho. O conteúdo é validado de fato pelo CertificateService.
                'extensions:pfx,p12',
                "max:{$maxKb}",
            ],
            'senha' => ['required', 'string', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'arquivo.extensions' => 'Envie um arquivo .pfx ou .p12.',
            'arquivo.max' => 'O arquivo do certificado excede o tamanho máximo permitido.',
            'senha.required' => 'A senha do certificado é obrigatória.',
        ];
    }
}