<?php

namespace Tests\Feature\Certificate;

use App\Domain\Certificate\CertificateLoader;
use App\Domain\Certificate\CertificateService;
use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate as SpedCertificate;
use Tests\TestCase;

/**
 * Criptografia do segredo e leitura em memória.
 *
 * Garante que:
 * - A senha do certificado NUNCA fica em texto claro no banco.
 * - O PFX NUNCA fica em texto claro em disco.
 * - O `CertificateLoader` monta o objeto do certificado a partir dos dados
 *   cifrados, sem jamais expor a senha.
 */
class EncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_senha_nao_fica_em_texto_no_banco(): void
    {
        $c = Certificate::factory()->create(['senha_encrypted' => 'segredo']);

        $raw = DB::table('certificates')->where('id', $c->id)->value('senha_encrypted');

        $this->assertNotSame('segredo', $raw);
        $this->assertStringNotContainsString('segredo', (string) $raw);
    }

    public function test_senha_e_lida_decifrada_pelo_model(): void
    {
        $c = Certificate::factory()->create(['senha_encrypted' => 'segredo']);

        // O cast `encrypted` devolve o valor original ao acessar via model.
        $this->assertSame('segredo', $c->fresh()->senha_encrypted);
    }

    public function test_pfx_nao_fica_em_texto_claro_no_disco(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);

        $service = app(CertificateService::class);
        $arquivo = new UploadedFile(
            base_path('tests/Fixtures/cert_e_cnpj.pfx'),
            'certificado.pfx',
            'application/x-pkcs12',
            null,
            true
        );

        $cert = $service->store($unidade, $arquivo, 'senha-pfx');

        // O conteúdo cru gravado NÃO é o PFX original (está cifrado).
        $bruto = Storage::disk('local')->get($cert->arquivo_path);
        $pfxOriginal = file_get_contents(base_path('tests/Fixtures/cert_e_cnpj.pfx'));

        $this->assertNotSame($pfxOriginal, $bruto);
        $this->assertStringStartsNotWith('0', $bruto); // não é texto/PFX cru

        // Mas é possível decifrar de volta para obter o PFX original.
        $this->assertSame($pfxOriginal, Crypt::decryptString($bruto));
    }

    public function test_loader_monta_o_certificado_a_partir_dos_dados_cifrados(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);

        $service = app(CertificateService::class);
        $cert = $service->store($unidade, new UploadedFile(
            base_path('tests/Fixtures/cert_e_cnpj.pfx'),
            'certificado.pfx',
            'application/x-pkcs12',
            null,
            true
        ), 'senha-pfx');

        $obj = app(CertificateLoader::class)->load($cert);

        $this->assertInstanceOf(SpedCertificate::class, $obj);
        $this->assertSame('11222333000181', $obj->getCnpj());
        $this->assertSame('Empresa Teste NFeGov', $obj->getCompanyName());
    }

    public function test_loader_falha_se_arquivo_ausente(): void
    {
        Storage::fake('local');

        $cert = Certificate::factory()->create([
            'arquivo_path' => 'certificados/inexistente.pfx',
        ]);

        $this->expectException(\RuntimeException::class);

        app(CertificateLoader::class)->load($cert);
    }
}