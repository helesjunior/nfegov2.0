<?php

namespace Tests\Feature\Nfse;

use App\Domain\Nfse\Danfse;
use App\Models\Nfse;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * DANFSe local (NT SE/CGNFS-e 008 v1.02): uma página A4, campos do XML,
 * homologação, marcas d'água, blocos opcionais e o endpoint.
 */
class DanfseTest extends TestCase
{
    use NfseXml;
    use RefreshDatabase;

    private function render(string $xml, string $situacao = 'normal'): string
    {
        return (new Danfse)->render($xml, $situacao, comprimir: false);
    }

    private function paginas(string $pdf): int
    {
        return preg_match_all('#/Type /Page\b(?!s)#', $pdf);
    }

    public function test_gera_uma_unica_pagina_a4_com_os_dados_da_nota(): void
    {
        $chave = $this->chaveNfse();
        $pdf = $this->render($this->xmlNfse($chave));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $this->paginas($pdf));
        $this->assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $pdf);

        foreach ([
            'DANFSe v2.0', 'Documento Auxiliar da NFS-e', 'PRESTADOR / FORNECEDOR', 'TOMADOR / ADQUIRENTE DA OPERA',
            'SERVI', 'TRIBUTA', 'VALOR TOTAL DA NFS-E', 'INFORMA',
            $chave, 'Prestador de Teste Ltda', '99.888.777/0001-66', '11.222.333/0001-81', '14.13.02', 'R$ 5.000,00', 'R$ 4.800,00',
            '20/10/2023', 'Totais Aproximados dos Tributos cfe. Lei n',
        ] as $trecho) {
            $this->assertStringContainsString($trecho, $pdf, "Faltou no PDF: {$trecho}");
        }
    }

    public function test_so_imprime_o_que_esta_no_xml_e_usa_traco_nos_vazios(): void
    {
        $pdf = $this->render($this->xmlNfse($this->chaveNfse()));

        $this->assertStringContainsString('(-)', $pdf);
        // Sem IBS/CBS no XML, o bloco não sai.
        $this->assertStringNotContainsString("TRIBUTA\xC7\xC3O IBS", $pdf);
        $this->assertStringNotContainsString('IBS / CBS', $pdf);
    }

    public function test_homologacao_tem_a_expressao_sem_validade_juridica(): void
    {
        $xml = str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', $this->xmlNfse($this->chaveNfse()));

        $this->assertStringContainsString('NFS-e SEM VALIDADE JUR', $this->render($xml));
        $this->assertStringNotContainsString('NFS-e SEM VALIDADE JUR', $this->render($this->xmlNfse($this->chaveNfse())));
    }

    public function test_marca_dagua_de_cancelada_e_substituida(): void
    {
        $xml = $this->xmlNfse($this->chaveNfse());

        $this->assertStringContainsString('(CANCELADA)', $this->render($xml, 'cancelada'));
        $this->assertStringContainsString('(SUBSTITU', $this->render($xml, 'substituida'));
        $this->assertStringNotContainsString('(CANCELADA)', $this->render($xml, 'normal'));
        $this->assertSame(1, $this->paginas($this->render($xml, 'cancelada')));
    }

    public function test_intermediario_e_retencao_aparecem_quando_existem(): void
    {
        $xml = $this->xmlNfse($this->chaveNfse(), extra: ['interm' => '55444333000122', 'vISSQN' => '100.00', 'tpRetISSQN' => '2']);
        $pdf = $this->render($xml);

        $this->assertStringContainsString("Intermedi\xE1rio Ltda", $pdf);
        $this->assertStringContainsString('55.444.333/0001-22', $pdf);
        $this->assertStringContainsString('Retido pelo Tomador', $pdf);
        $this->assertStringNotContainsString("INTERMEDI\xC1RIO DA OPERA\xC7\xC3O N\xC3O IDENTIFICADO", $pdf);
    }

    public function test_descricao_longa_e_cortada_com_reticencias_e_continua_em_uma_pagina(): void
    {
        $xml = str_replace('Corrimão em aço inox', str_repeat('Serviço de instalação. ', 120), $this->xmlNfse($this->chaveNfse()));
        $pdf = $this->render($xml);

        $this->assertSame(1, $this->paginas($pdf));
        $this->assertStringContainsString('...)', $pdf);
    }

    public function test_xml_invalido_ou_de_outro_documento_e_recusado(): void
    {
        $this->expectException(RuntimeException::class);

        (new Danfse)->render('<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"/>');
    }

    // ------------------------------------------------------------- endpoint

    private function usuario(Tenant $tenant, string $papel = 'operador'): User
    {
        $this->seed(RolesSeeder::class);
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papel);

        return $u;
    }

    public function test_endpoint_devolve_pdf_e_audita(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $chave = $this->chaveNfse();
        Storage::disk('local')->put('x/nota.xml', $this->xmlNfse($chave));
        Nfse::factory()->create(['unidade_id' => $unidade->id, 'chave' => $chave, 'xml_path' => 'x/nota.xml']);

        $resp = $this->actingAs($this->usuario($tenant))->get("/api/nfses/{$chave}/danfse?unidade_id={$unidade->id}");

        $resp->assertOk();
        $this->assertSame('application/pdf', $resp->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $resp->getContent());
        $this->assertDatabaseHas('audit_logs', ['acao' => 'nfse.danfse.gerado']);
    }

    public function test_endpoint_exige_permissao_de_exportacao_e_xml(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $chave = $this->chaveNfse();
        Storage::disk('local')->put('x/nota.xml', $this->xmlNfse($chave));
        Nfse::factory()->create(['unidade_id' => $unidade->id, 'chave' => $chave, 'xml_path' => 'x/nota.xml']);
        $semXml = Nfse::factory()->create(['unidade_id' => $unidade->id, 'xml_path' => null]);

        $this->actingAs(User::factory()->comTenant($tenant)->create())
            ->get("/api/nfses/{$chave}/danfse")->assertForbidden();

        $this->actingAs($this->usuario($tenant))
            ->get("/api/nfses/{$semXml->chave}/danfse")->assertNotFound();
    }
}
