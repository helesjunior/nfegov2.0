<?php

namespace App\Domain\Certificate;

use App\Models\Certificate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate as SpedCertificate;
use RuntimeException;
use Throwable;

/**
 * Carrega o certificado e-CNPJ em MEMÓRIA, a partir dos dados cifrados.
 *
 * Fluxo:
 * 1. Lê o PFX cifrado do disco.
 * 2. Decifra em memória (AES-256 via `Crypt`).
 * 3. Lê a senha decifrada (cast `encrypted` do model).
 * 4. Monta e devolve um `NFePHP\Common\Certificate`.
 *
 * SEGURANÇA: nada é gravado em claro em disco; o PFX e a senha só existem
 * em memória durante a operação. O objeto devolvido contém chave privada —
 * nunca deve ser logado nem serializado em resposta.
 */
class CertificateLoader
{
    /**
     * @throws RuntimeException quando o arquivo não existe ou não pode ser aberto.
     */
    public function load(Certificate $certificate): SpedCertificate
    {
        $disco = Storage::disk($this->disco());

        if (blank($certificate->arquivo_path) || ! $disco->exists($certificate->arquivo_path)) {
            throw new RuntimeException('Arquivo do certificado não encontrado.');
        }

        $cifrado = $disco->get($certificate->arquivo_path);

        try {
            $conteudo = Crypt::decryptString($cifrado);
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível decifrar o certificado.', 0, $e);
        }

        try {
            // A senha vem decifrada pelo cast `encrypted` do model; ela só
            // existe em memória aqui.
            return SpedCertificate::readPfx($conteudo, (string) $certificate->senha_encrypted);
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível abrir o certificado (senha ou arquivo inválido).', 0, $e);
        }
    }

    private function disco(): string
    {
        return config('nfegov.storage.cert_disk', config('nfegov.storage.disk', 'local'));
    }
}