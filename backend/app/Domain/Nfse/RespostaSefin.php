<?php

namespace App\Domain\Nfse;

use App\Domain\Sefaz\DocZipDecoder;
use Throwable;

/**
 * Resposta da SEFIN Nacional ao pedido de registro de evento, já interpretada.
 *
 * Sucesso é HTTP 201 com `eventoXmlGZipB64` (o evento completo, GZip + Base64).
 * Erro é HTTP 400/401 com `erro`: o Swagger diz objeto, mas a SEFIN responde um
 * ARRAY de `{codigo, descricao}`; aceitamos as duas formas.
 */
final class RespostaSefin
{
    /**
     * @param  array<int, array{codigo:string, descricao:string}>  $erros
     */
    public function __construct(
        public readonly int $http,
        public readonly array $erros,
        public readonly ?string $eventoXml,
    ) {}

    public static function deCorpo(int $http, string $corpo): self
    {
        $json = json_decode($corpo, true);

        if (! is_array($json)) {
            return new self($http, [[
                'codigo' => 'HTTP'.$http,
                'descricao' => 'Resposta inesperada da SEFIN: '.mb_substr(trim($corpo), 0, 200),
            ]], null);
        }

        $erros = [];
        $bruto = $json['erro'] ?? $json['erros'] ?? [];
        $lista = is_array($bruto) && array_is_list($bruto) ? $bruto : [$bruto];

        foreach ($lista as $erro) {
            if (is_array($erro) && ($erro['codigo'] ?? $erro['descricao'] ?? null) !== null) {
                $erros[] = [
                    'codigo' => (string) ($erro['codigo'] ?? ''),
                    'descricao' => (string) ($erro['descricao'] ?? ''),
                ];
            }
        }

        $evento = null;
        if (isset($json['eventoXmlGZipB64']) && is_string($json['eventoXmlGZipB64'])) {
            try {
                $evento = (new DocZipDecoder)->decode($json['eventoXmlGZipB64']);
            } catch (Throwable) {
                $evento = null;
            }
        }

        return new self($http, $erros, $evento);
    }

    /**
     * O evento foi registrado (HTTP 201 e sem erros).
     */
    public function registrado(): bool
    {
        return $this->http === 201 && $this->erros === [];
    }

    public function mensagemErro(): string
    {
        if ($this->erros === []) {
            return "A SEFIN respondeu HTTP {$this->http} sem detalhes.";
        }

        return implode('; ', array_map(
            fn (array $e) => trim($e['codigo'].': '.$e['descricao'], ': '),
            $this->erros,
        ));
    }
}
