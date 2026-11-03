<?php

namespace App\Domain\Sefaz;

use App\Models\Manifestacao;
use App\Models\Unidade;

/**
 * Manifestação do destinatário na SEFAZ.
 *
 * Por decisão de escopo/segurança, apenas a CIÊNCIA DA OPERAÇÃO (evento
 * 210210) é enviada — sempre automática, quando a nota é recebida no resumo.
 * Não há confirmação/desconhecimento/não realizada nesta versão.
 *
 * A operação é idempotente: se já houve ciência para a chave, não reenvia.
 */
class ManifestacaoService
{
    private const EVENTO_CIENCIA = '210210';

    public function __construct(private readonly SefazClient $client)
    {
    }

    /**
     * Registra ciência da operação para a chave. Devolve true em sucesso.
     */
    public function ciencia(Unidade $unidade, string $chave): bool
    {
        $chave = preg_replace('/\D/', '', $chave);

        // Já manifestado: nada a fazer (idempotente).
        $jaManifestado = Manifestacao::withoutGlobalScopes()
            ->where('unidade_id', $unidade->id)
            ->where('chave', $chave)
            ->where('tp_evento', self::EVENTO_CIENCIA)
            ->exists();

        if ($jaManifestado) {
            return true;
        }

        $ok = $this->client->manifestarCiencia($unidade, $chave);

        if (! $ok) {
            return false;
        }

        Manifestacao::create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'chave' => $chave,
            'tp_evento' => self::EVENTO_CIENCIA,
            'n_seq_evento' => 1,
            'enviado_em' => now(),
        ]);

        return true;
    }
}