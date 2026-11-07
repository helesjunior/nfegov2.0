<?php

namespace App\Domain\Nfse;

use RuntimeException;

/**
 * Manifestação que não pôde ser registrada: pedido inválido para a nota
 * (regra do sistema) ou rejeitado pela SEFIN. A mensagem é mostrada ao usuário.
 */
class ManifestacaoNfseException extends RuntimeException {}
