<?php

namespace Tests\Feature\Certificate;

use App\Domain\Certificate\CertificateService;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate as SpedCertificate;
use Tests\TestCase;

/**
 * Regressão: certificados A1 da ICP-Brasil usam algoritmos PKCS#12 LEGADOS
 * (ex.: pbeWithSHA1And40BitRC2-CBC). No OpenSSL 3 esses algoritmos vivem no
 * provider `legacy` — desativado por padrão. Sem o provider, o PHP falha com
 *
 *   error:0308010C:digital envelope routines::unsupported
 *
 * e o CertificateService reporta (de forma enganosa) "senha incorreta ou
 * arquivo inválido", mesmo com senha correta.
 *
 * O fixture `cert_e_cnpj_legacy.pfx` é gerado com `-certpbe PBE-SHA1-RC2-40`
 * (ver tools/make_legacy_test_pfx.sh) e SÓ abre com o provider legacy ativo.
 * Estes testes garantem que o ambiente (OPENSSL_CONF) o habilita.
 */
class CertificadoLegadoTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureLegado(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/cert_e_cnpj_legacy.pfx'),
            'certificado.pfx',
            'application/x-pkcs12',
            null,
            true
        );
    }

    public function test_lê_pfx_com_criptografia_legada_rc2(): void
    {
        $conteudo = file_get_contents(base_path('tests/Fixtures/cert_e_cnpj_legacy.pfx'));

        // Se o provider `legacy` não estiver ativo, esta chamada lança
        // CertificateException ("digital envelope routines::unsupported").
        $pfx = SpedCertificate::readPfx($conteudo, 'senha-pfx');

        $this->assertSame('11222333000181', preg_replace('/\D/', '', $pfx->getCnpj()));
    }

    public function test_upload_aceita_certificado_legado(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);

        $cert = app(CertificateService::class)->store(
            $unidade,
            $this->fixtureLegado(),
            'senha-pfx',
        );

        $this->assertSame('11222333000181', $cert->cnpj);
        $this->assertTrue($cert->ativo);
    }
}
