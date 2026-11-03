<?php

namespace App\Domain\Certificate;

use App\Models\Certificate;
use App\Models\Unidade;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use NFePHP\Common\Certificate as SpedCertificate;
use Throwable;

/**
 * Serviço de certificado e-CNPJ.
 *
 * Responsabilidades:
 * - Abrir o PFX enviado (validando a senha) e extrair CNPJ/titular/validade.
 * - Rejeitar senha inválida, CNPJ divergente da unidade e certificado vencido.
 * - Gravar o PFX CIFRADO em disco (a senha vai cifrada no banco, via cast).
 * - Desativar o certificado anterior, mantendo o histórico.
 *
 * SEGURANÇA: o conteúdo do PFX e a senha nunca são gravados em claro nem
 * devolvidos por nenhuma API.
 */
class CertificateService
{
    /**
     * Processa o upload e ativa o novo certificado da unidade.
     *
     * @throws ValidationException quando o PFX é inválido/incompatível.
     */
    public function store(Unidade $unidade, UploadedFile $arquivo, string $senha): Certificate
    {
        $conteudo = file_get_contents($arquivo->getRealPath());

        try {
            $pfx = SpedCertificate::readPfx($conteudo, $senha);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não foi possível abrir o certificado: senha incorreta ou arquivo inválido.',
            ]);
        }

        $cnpjCert = preg_replace('/\D/', '', (string) $pfx->getCnpj());
        $cnpjUnidade = preg_replace('/\D/', '', (string) $unidade->cnpj);

        if ($cnpjCert === '' || $cnpjCert !== $cnpjUnidade) {
            throw ValidationException::withMessages([
                'arquivo' => 'O CNPJ do certificado não corresponde ao CNPJ da unidade.',
            ]);
        }

        $validoAte = $pfx->getValidTo();

        // Comparamos com o relógio da aplicação (Carbon), não com time():
        // isso mantém o comportamento testável e consistente com o restante
        // do sistema (alertas de vencimento também usam now()).
        if ($validoAte === null || $validoAte < now()->toDateTime()) {
            throw ValidationException::withMessages([
                'arquivo' => 'O certificado está vencido.',
            ]);
        }

        // Grava o PFX cifrado (AES-256 via Crypt) na área privada do disco.
        $path = $this->caminho($unidade);
        Storage::disk($this->disco())->put($path, Crypt::encryptString($conteudo));

        return \DB::transaction(function () use ($unidade, $pfx, $senha, $path, $validoAte) {
            // Desativa o certificado vigente anterior (mantém histórico).
            $unidade->certificates()->where('ativo', true)->update([
                'ativo' => false,
                'substituido_em' => now(),
            ]);

            return Certificate::create([
                'tenant_id' => $unidade->tenant_id,
                'unidade_id' => $unidade->id,
                'arquivo_path' => $path,
                'senha_encrypted' => $senha,
                'cnpj' => preg_replace('/\D/', '', (string) $pfx->getCnpj()),
                'titular' => (string) $pfx->getCompanyName(),
                'valido_de' => $pfx->getValidFrom(),
                'valido_ate' => $validoAte,
                'fingerprint' => $this->fingerprint($pfx),
                'emissor' => (string) ($pfx->getCSP() ?: $pfx->getICP()),
                'ativo' => true,
            ]);
        });
    }

    /**
     * Certificado vigente da unidade (ou null).
     */
    public function activeFor(Unidade $unidade): ?Certificate
    {
        return $unidade->certificates()->where('ativo', true)->latest('id')->first();
    }

    /**
     * Caminho determinístico do PFX no disco (sem expor nada sensível).
     */
    private function caminho(Unidade $unidade): string
    {
        $raiz = config('nfegov.storage.cert_root', 'certificados');

        return sprintf('%s/tenant_%d/unidade_%d/%s.pfx',
            $raiz,
            $unidade->tenant_id,
            $unidade->id,
            now()->format('YmdHis') . '_' . bin2hex(random_bytes(4)),
        );
    }

    private function disco(): string
    {
        return config('nfegov.storage.cert_disk', config('nfegov.storage.disk', 'local'));
    }

    /**
     * Fingerprint SHA-256 do certificado (der do certificado X.509).
     */
    private function fingerprint(SpedCertificate $pfx): ?string
    {
        try {
            $pub = $pfx->publicKey;
            if (! empty($pub['certificate'])) {
                return hash('sha256', base64_decode($pub['certificate']));
            }
        } catch (Throwable) {
            // fingerprint é opcional
        }

        return null;
    }
}