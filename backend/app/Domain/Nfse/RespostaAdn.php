<?php

namespace App\Domain\Nfse;

/**
 * Resposta da API de contribuintes do ADN, já interpretada.
 *
 * Regras (confirmadas em chamadas reais):
 * - Fila vazia é HTTP 404 com `NENHUM_DOCUMENTO_LOCALIZADO` (E2220): NÃO é erro.
 * - Rejeição pode vir com HTTP 400 e `Erros` preenchido mesmo com
 *   `StatusProcessamento` de sucesso: quem decide a falha é o HTTP + `Erros`.
 * - Corpo que não é JSON do ADN (ex.: `application/problem+json` do roteador)
 *   também é erro.
 */
final class RespostaAdn
{
    public const DOCUMENTOS_LOCALIZADOS = 'DOCUMENTOS_LOCALIZADOS';
    public const NENHUM_DOCUMENTO = 'NENHUM_DOCUMENTO_LOCALIZADO';

    /**
     * @param  array<int, array{nsu:int, chave:string, tipo_documento:string, tipo_evento:?string, arquivo_xml:string, data_geracao:?string}>  $documentos
     * @param  array<int, array{codigo:string, descricao:string}>  $erros
     */
    public function __construct(
        public readonly int $http,
        public readonly ?string $status,
        public readonly array $documentos,
        public readonly array $erros,
    ) {}

    /**
     * Monta a resposta a partir do HTTP e do corpo cru.
     */
    public static function deCorpo(int $http, string $corpo): self
    {
        $json = json_decode($corpo, true);

        if (! is_array($json) || ! isset($json['StatusProcessamento'])) {
            return new self($http, null, [], [[
                'codigo' => 'HTTP'.$http,
                'descricao' => 'Resposta inesperada do ADN: '.mb_substr(trim($corpo), 0, 200),
            ]]);
        }

        $documentos = [];
        foreach ((array) ($json['LoteDFe'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $documentos[] = [
                'nsu' => (int) ($item['NSU'] ?? 0),
                'chave' => (string) ($item['ChaveAcesso'] ?? ''),
                'tipo_documento' => (string) ($item['TipoDocumento'] ?? ''),
                'tipo_evento' => isset($item['TipoEvento']) ? (string) $item['TipoEvento'] : null,
                'arquivo_xml' => (string) ($item['ArquivoXml'] ?? ''),
                'data_geracao' => isset($item['DataHoraGeracao']) ? (string) $item['DataHoraGeracao'] : null,
            ];
        }

        $erros = [];
        foreach ((array) ($json['Erros'] ?? []) as $erro) {
            if (is_array($erro)) {
                $erros[] = [
                    'codigo' => (string) ($erro['Codigo'] ?? ''),
                    'descricao' => (string) ($erro['Descricao'] ?? ''),
                ];
            }
        }

        return new self($http, (string) $json['StatusProcessamento'], $documentos, $erros);
    }

    /**
     * Fim da fila (ou chave sem documento): não há nada novo, e não é falha.
     */
    public function filaVazia(): bool
    {
        return $this->status === self::NENHUM_DOCUMENTO;
    }

    public function sucesso(): bool
    {
        return $this->http >= 200 && $this->http < 300
            && $this->status === self::DOCUMENTOS_LOCALIZADOS
            && $this->erros === [];
    }

    /**
     * O ADN pediu para esperar ou está indisponível: abre o freio.
     */
    public function deveAbrirFreio(): bool
    {
        return in_array($this->http, [429, 502, 503, 504], true);
    }

    public function mensagemErro(): string
    {
        if ($this->erros === []) {
            return "ADN respondeu HTTP {$this->http} ({$this->status}).";
        }

        return implode('; ', array_map(
            fn (array $e) => trim($e['codigo'].': '.$e['descricao'], ': '),
            $this->erros,
        ));
    }
}
