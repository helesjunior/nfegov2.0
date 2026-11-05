<?php

namespace Tests\Feature\Certificate;

use App\Domain\Certificate\CertificateService;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A mensagem de erro do upload não deve ENGANAR o usuário.
 *
 * Quando a senha está correta mas o PFX usa algoritmo que o OpenSSL não
 * consegue processar (ex.: provider `legacy` ausente), dizer "senha
 * incorreta" manda o usuário caçar um problema que não existe.
 */
class MensagemErroCertificadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_senha_incorreta_mantem_mensagem_de_senha(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);

        $arquivo = new UploadedFile(
            base_path('tests/Fixtures/cert_e_cnpj.pfx'),
            'certificado.pfx',
            'application/x-pkcs12',
            null,
            true
        );

        try {
            app(CertificateService::class)->store($unidade, $arquivo, 'senha-errada');
            $this->fail('Esperava ValidationException para senha incorreta.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'senha incorreta',
                $e->validator->errors()->first('arquivo')
            );
        }
    }

    public function test_erro_de_algoritmo_nao_suportado_nao_culpa_a_senha(): void
    {
        // Mensagem real do OpenSSL 3 quando o provider `legacy` está ausente.
        $msg = CertificateService::mensagemDeAberturaInvalida(
            'Impossivel ler o certificado, ocorreu o seguinte erro: '
            .'(error:0308010C:digital envelope routines::unsupported)'
        );

        $this->assertStringNotContainsString('senha incorreta', $msg);
        $this->assertStringContainsString('não suportada', $msg);
    }

    public function test_mac_verify_failure_culpa_a_senha(): void
    {
        $msg = CertificateService::mensagemDeAberturaInvalida(
            'Impossivel ler o certificado, ocorreu o seguinte erro: '
            .'(error:11800071:PKCS12 routines::mac verify failure)'
        );

        $this->assertStringContainsString('senha incorreta', $msg);
    }
}
