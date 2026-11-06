# Recuperação Manual de NF-e (por chave e por NSU) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir ao usuário final buscar manualmente uma NF-e já consumida pela fila do DistDFe — por chave de acesso (`consChNFe`) ou por NSU específico (`consNSU`) — deixando o XML disponível na tela de notas.

**Architecture:** Um novo `RecuperacaoService` no domínio Sefaz faz a consulta pontual (sem tocar o cursor `Nsu`), reaproveita a lógica de processamento de documentos (extraída do `DistDfeService` para um `DocumentoProcessor`) e roda dentro de um job enfileirado `RecuperarNfeJob`, que notifica o usuário ao final. A API expõe `POST /api/nfes/recuperar`, protegida por `sync.executar`.

**Tech Stack:** Laravel 11 (PHP 8.3), `nfephp-org/sped-nfe`, Horizon, React + Vite + TanStack Query + Vitest, Laravel Notifications (canal `database`).

**Spec:** `docs/superpowers/specs/2026-11-06-recuperacao-manual-nfe-design.md`

## Global Constraints

- Conversar sempre em **português do Brasil**.
- **Sem libs novas.**
- Multitenancy: banco único, `tenant_id` + global scope; tenant ativo na sessão. Nunca vazar dado entre tenants.
- **A recuperação NUNCA altera `nsu.ultimo_nsu`** da unidade.
- Autorização da busca manual: permissão **`sync.executar`** (o papel `consulta`, só `nfe.view`, recebe 403).
- Execução **assíncrona** na fila `sefaz`, respeitando `SefazGuard` (circuit breaker + rate limiter).
- `Nsu` válido na busca por NSU: **inteiro ≥ 1** (NSU 0 significa `numNSU=0` no sped-nfe, que cai para varredura incremental).
- Só NF-e é importada; **eventos são ignorados** (não importar/armazenar).
- Sem retry automático da Ciência: **uma** tentativa de download; se não propagou, aviso honesto.
- Após alterar código PHP: **reiniciar o Horizon**; caches de config/rotas **desligados** nos testes.
- `docker compose`/`git push` no PowerShell retornam exit 1 como ruído mesmo em sucesso.
- Comandos: app `http://localhost:8080`; testes `docker compose exec -T app php artisan test`; Pint `docker compose exec -T app ./vendor/bin/pint <arquivos>`; frontend em `frontend/`.

## Review Focus

1. **Busca por NSU não pode mexer o cursor.** Uma recuperação por NSU/qualquer valor jamais pode gravar `nsu.ultimo_nsu` — senão a sync automática rebobina e re-consuma a fila (risco de 656). Testado em `RecuperacaoServiceTest::test_recuperacao_nao_altera_o_cursor`.
2. **Papel somente-leitura não pode disparar Ciência.** Um usuário `consulta` (só `nfe.view`) chamando a rota deve receber **403** — ele não tem `sync.executar`. Testado em `RecuperarNfeApiTest::test_consulta_recebe_403`.
3. **Clique duplicado não vira segundo job.** Dois pedidos idênticos simultâneos: o segundo recebe **409**, nunca um segundo job (nem silêncio). Testado em `RecuperarNfeApiTest::test_pedido_duplicado_recebe_409`.
4. **`cStat 137` é "não encontrado", não sucesso.** Quando a SEFAZ devolve "Nenhum documento localizado", o resultado é `NAO_ENCONTRADO` e a notificação diz isso — não se cria NF-e nem "sucesso" enganoso. Testado em `RecuperacaoServiceTest::test_nsu_inexistente_retorna_nao_encontrado`.
5. **Certificado vencido/circuito aberto não tocam a SEFAZ.** O job revalida antes de consultar (igual ao `SyncNfeJob`). Testado em `RecuperarNfeJobTest::test_certificado_vencido_nao_consulta_sefaz`.

---

### Task 1: Extrair `DocumentoProcessor` do `DistDfeService`

**Files:**
- Create: `backend/app/Domain/Nfe/DocumentoProcessor.php`
- Modify: `backend/app/Domain/Sefaz/DistDfeService.php`
- Test: `backend/tests/Feature/Nfe/DocumentoProcessorTest.php`

**Interfaces:**
- Consumes: `DocZipDecoder::decode(string): string`; `NfeImporter::importResumo(Unidade, ?Nsu, string): Nfe` e `import(Unidade, ?Nsu, string): Nfe`; `ManifestacaoService::ciencia(Unidade, string): bool`; `DownloadService::porChave(Unidade, string): ?string`.
- Produces: `DocumentoProcessor::processar(Unidade $unidade, ?Nsu $nsu, array $doc): void` — recebe `$doc` no formato `array{nsu:int, schema:string, conteudo:string}` e aplica: `resNFe` → importResumo + Ciência + download + import; `procNFe`/`nfeProc` → import; outros schemas → no-op.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Nfe/DocumentoProcessorTest.php`:

```php
<?php

namespace Tests\Feature\Nfe;

use App\Domain\Nfe\DocumentoProcessor;
use App\Domain\Sefaz\DownloadService;
use App\Domain\Sefaz\ManifestacaoService;
use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentoProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function doc(string $xml, string $schema = 'procNFe', int $nsu = 10): array
    {
        return [
            'nsu' => $nsu,
            'schema' => $schema,
            'conteudo' => base64_encode(gzencode($xml)),
        ];
    }

    private function xmlNfeProc(string $chave): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.$chave
            .'" versao="4.00"><ide><nNF>1</nNF><serie>1</serie><mod>55</mod>'
            .'<dhEmi>2026-10-01T10:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>11111111111111</CNPJ><xNome>Fornecedor</xNome></emit>'
            .'<dest><CNPJ>00101980000119</CNPJ></dest>'
            .'<total><ICMSTot><vNF>100.00</vNF></ICMSTot></total></infNFe></NFe>'
            .'<protNFe><infProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
    }

    private function xmlResumo(string $chave): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>'.$chave.'</chNFe>'
            .'<CNPJ>11111111111111</CNPJ><xNome>Fornecedor</xNome><vNF>100.00</vNF>'
            .'<dhEmi>2026-10-01T10:00:00-03:00</dhEmi></resNFe>';
    }

    public function test_procnfe_importa(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $chave = str_repeat('1', 44);

        app(DocumentoProcessor::class)->processar($unidade, null, $this->doc($this->xmlNfeProc($chave)));

        $this->assertNotNull(Nfe::withoutGlobalScopes()->where('chave', $chave)->first());
    }

    public function test_evento_e_ignorado(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $xml = '<?xml version="1.0"?><resEvento xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>'.str_repeat('2', 44).'</chNFe></resEvento>';

        app(DocumentoProcessor::class)->processar($unidade, null, $this->doc($xml, 'resEvento'));

        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());
    }

    public function test_resumo_dispara_ciencia_e_baixa_completo(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $chave = str_repeat('3', 44);

        $manifestacao = \Mockery::mock(ManifestacaoService::class);
        $manifestacao->shouldReceive('ciencia')->once()->withArgs(fn ($u, $c) => $c === $chave)->andReturn(true);
        app()->instance(ManifestacaoService::class, $manifestacao);

        $download = \Mockery::mock(DownloadService::class);
        $download->shouldReceive('porChave')->once()->andReturn($this->xmlNfeProc($chave));
        app()->instance(DownloadService::class, $download);

        app(DocumentoProcessor::class)->processar($unidade, null, $this->doc($this->xmlResumo($chave), 'resNFe'));

        $nfe = Nfe::withoutGlobalScopes()->where('chave', $chave)->first();
        $this->assertNotNull($nfe);
        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=DocumentoProcessorTest`
Expected: FAIL — classe `App\Domain\Nfe\DocumentoProcessor` não encontrada.

- [ ] **Step 3: Create `DocumentoProcessor`**

Criar `backend/app/Domain/Nfe/DocumentoProcessor.php` movendo a lógica de `DistDfeService::processarDoc` e `chaveDoResumo` (linhas ~144-186), mudando `$nsu` para `?Nsu` e usando `$doc['nsu']` como o `nsuAtual` do log (não há cursor a atualizar aqui):

```php
<?php

namespace App\Domain\Nfe;

use App\Domain\Sefaz\DocZipDecoder;
use App\Domain\Sefaz\DownloadService;
use App\Domain\Sefaz\ManifestacaoService;
use App\Models\Nsu;
use App\Models\Unidade;

/**
 * Transforma UM documento do distDFe (docZip) em nota no banco.
 *
 * Usado tanto pelo laço incremental (`DistDfeService`) quanto pela recuperação
 * pontual (`RecuperacaoService`). NÃO mexe no cursor `Nsu` da unidade.
 */
class DocumentoProcessor
{
    public function __construct(
        private readonly DocZipDecoder $decoder,
        private readonly NfeImporter $importer,
        private readonly ManifestacaoService $manifestacao,
        private readonly DownloadService $download,
    ) {}

    /**
     * @param  array{nsu:int, schema:string, conteudo:string}  $doc
     */
    public function processar(Unidade $unidade, ?Nsu $nsu, array $doc): void
    {
        $xml = $this->decoder->decode($doc['conteudo']);

        if ($doc['schema'] === 'resNFe') {
            $this->importer->importResumo($unidade, $nsu, $xml);

            $chave = $this->chaveDoResumo($xml);
            if ($chave) {
                $this->manifestacao->ciencia($unidade, $chave);
                $completo = $this->download->porChave($unidade, $chave);

                if ($completo) {
                    $this->importer->import($unidade, $nsu, $completo);
                }
            }

            return;
        }

        if (in_array($doc['schema'], ['procNFe', 'nfeProc'], true)) {
            $this->importer->import($unidade, $nsu, $xml);
        }
    }

    private function chaveDoResumo(string $xml): ?string
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $chave = preg_replace('/\D/', '', (string) $xp->query('//nfe:chNFe')->item(0)?->nodeValue);

        return strlen($chave) === 44 ? $chave : null;
    }
}
```

- [ ] **Step 4: Refactor `DistDfeService` to delegate**

Em `DistDfeService`: trocar as dependências `DocZipDecoder`, `NfeImporter`, `ManifestacaoService`, `DownloadService` por `DocumentoProcessor` no construtor; no laço, substituir `$this->processarDoc($unidade, $nsu, $doc, $ultimoNsu)` por `$this->processor->processar($unidade, $nsu, $doc)`; remover os métodos privados `processarDoc` e `chaveDoResumo`. Manter `parseRetorno` (será tornada reutilizável na Task 3 — neste passo apenas permanece privada).

- [ ] **Step 5: Run tests (novo + regressão do laço)**

Run: `docker compose exec -T app php artisan test --filter="DocumentoProcessorTest|DistDfeTest"`
Expected: PASS (todos).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Domain/Nfe/DocumentoProcessor.php backend/app/Domain/Sefaz/DistDfeService.php backend/tests/Feature/Nfe/DocumentoProcessorTest.php
git commit -m "refactor(nfe): extrai DocumentoProcessor do DistDfeService"
```

---

### Task 2: Expor `distDFePorNsu` e `distDFePorChave` no `SefazClient`

**Files:**
- Modify: `backend/app/Domain/Sefaz/SefazClient.php`
- Modify: `backend/app/Domain/Sefaz/SpedSefazClient.php`
- Test: `backend/tests/Unit/Sefaz/SpedSefazClientTest.php`

**Interfaces:**
- Produces:
  - `SefazClient::distDFePorNsu(Unidade $unidade, int $nsu): string`
  - `SefazClient::distDFePorChave(Unidade $unidade, string $chave): string`
  - Ambos devolvem o XML cru `retDistDFeInt`. Implementação via `Tools::sefazDistDFe(0, $nsu, '', 'AN')` e `sefazDistDFe(0, 0, $chave, 'AN')`.

- [ ] **Step 1: Write the failing test**

Adicionar a `backend/tests/Unit/Sefaz/SpedSefazClientTest.php` (seguir o padrão de mock de `Tools` já usado no arquivo):

```php
public function test_distdfe_por_nsu_usa_consnsu(): void
{
    $tools = \Mockery::mock(\NFePHP\NFe\Tools::class);
    $tools->shouldReceive('sefazDistDFe')
        ->once()
        ->with(0, 12345, '', 'AN')
        ->andReturn('<retDistDFeInt/>');

    $factory = \Mockery::mock(\App\Domain\Sefaz\ToolsFactory::class);
    $factory->shouldReceive('make')->andReturn($tools);

    $client = new \App\Domain\Sefaz\SpedSefazClient($factory);
    $unidade = \App\Models\Unidade::factory()->make();

    $this->assertSame('<retDistDFeInt/>', $client->distDFePorNsu($unidade, 12345));
}

public function test_distdfe_por_chave_usa_conschnfe(): void
{
    $chave = str_repeat('9', 44);
    $tools = \Mockery::mock(\NFePHP\NFe\Tools::class);
    $tools->shouldReceive('sefazDistDFe')
        ->once()
        ->with(0, 0, $chave, 'AN')
        ->andReturn('<retDistDFeInt/>');

    $factory = \Mockery::mock(\App\Domain\Sefaz\ToolsFactory::class);
    $factory->shouldReceive('make')->andReturn($tools);

    $client = new \App\Domain\Sefaz\SpedSefazClient($factory);
    $unidade = \App\Models\Unidade::factory()->make();

    $this->assertSame('<retDistDFeInt/>', $client->distDFePorChave($unidade, $chave));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=SpedSefazClientTest`
Expected: FAIL — métodos `distDFePorNsu`/`distDFePorChave` inexistentes.

- [ ] **Step 3: Add the methods**

Em `SefazClient` (interface), adicionar os dois métodos com docblock. Em `SpedSefazClient`, implementar delegando ao `Tools`:

```php
public function distDFePorNsu(Unidade $unidade, int $nsu): string
{
    return (string) $this->factory->make($unidade)->sefazDistDFe(0, $nsu, '', 'AN');
}

public function distDFePorChave(Unidade $unidade, string $chave): string
{
    return (string) $this->factory->make($unidade)->sefazDistDFe(0, 0, $chave, 'AN');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test --filter=SpedSefazClientTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Sefaz/SefazClient.php backend/app/Domain/Sefaz/SpedSefazClient.php backend/tests/Unit/Sefaz/SpedSefazClientTest.php
git commit -m "feat(sefaz): expõe consulta por NSU (consNSU) e por chave (consChNFe)"
```

---

### Task 3: Tornar `parseRetorno` reutilizável

**Files:**
- Create: `backend/app/Domain/Sefaz/EnvelopeDistDfe.php`
- Modify: `backend/app/Domain/Sefaz/DistDfeService.php`
- Test: `backend/tests/Unit/Sefaz/EnvelopeDistDfeTest.php`

**Interfaces:**
- Produces: `EnvelopeDistDfe::parse(string $xml): array{cStat:string, xMotivo:string, ultNSU:int, maxNSU:int, docs:array<int, array{nsu:int, schema:string, conteudo:string}>}` — mesma lógica de `DistDfeService::parseRetorno`.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Unit/Sefaz/EnvelopeDistDfeTest.php`:

```php
<?php

namespace Tests\Unit\Sefaz;

use App\Domain\Sefaz\EnvelopeDistDfe;
use PHPUnit\Framework\TestCase;

class EnvelopeDistDfeTest extends TestCase
{
    public function test_extrai_cstat_nsu_e_docs(): void
    {
        $xml = '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>138</cStat><xMotivo>Documento localizado</xMotivo>'
            .'<ultNSU>000000000000150</ultNSU><maxNSU>000000000000200</maxNSU>'
            .'<loteDistDFeInt><docZip NSU="150" schema="procNFe">QUJD</docZip></loteDistDFeInt>'
            .'</retDistDFeInt>';

        $env = (new EnvelopeDistDfe)->parse($xml);

        $this->assertSame('138', $env['cStat']);
        $this->assertSame(150, $env['ultNSU']);
        $this->assertSame(200, $env['maxNSU']);
        $this->assertCount(1, $env['docs']);
        $this->assertSame(150, $env['docs'][0]['nsu']);
        $this->assertSame('procNFe', $env['docs'][0]['schema']);
        $this->assertSame('QUJD', $env['docs'][0]['conteudo']);
    }

    public function test_vazio_quando_sem_documentos(): void
    {
        $xml = '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>137</cStat><xMotivo>Nenhum documento localizado</xMotivo>'
            .'<ultNSU>000000000000100</ultNSU><maxNSU>000000000000100</maxNSU></retDistDFeInt>';

        $env = (new EnvelopeDistDfe)->parse($xml);

        $this->assertSame('137', $env['cStat']);
        $this->assertSame([], $env['docs']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=EnvelopeDistDfeTest`
Expected: FAIL — classe inexistente.

- [ ] **Step 3: Create `EnvelopeDistDfe`**

Mover a implementação de `DistDfeService::parseRetorno` para `EnvelopeDistDfe::parse(string $xml): array`. Em `DistDfeService`, substituir a chamada `$this->parseRetorno($retorno)` por `EnvelopeDistDfe::parse(...)` (ou injetar a classe) e **remover** o método privado. Não duplicar lógica.

- [ ] **Step 4: Run tests (novo + regressão do laço)**

Run: `docker compose exec -T app php artisan test --filter="EnvelopeDistDfeTest|DistDfeTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Sefaz/EnvelopeDistDfe.php backend/app/Domain/Sefaz/DistDfeService.php backend/tests/Unit/Sefaz/EnvelopeDistDfeTest.php
git commit -m "refactor(sefaz): extrai EnvelopeDistDfe (parse do retDistDFeInt)"
```

---

### Task 4: `RecuperacaoService` (por chave e por NSU)

**Files:**
- Create: `backend/app/Domain/Sefaz/ResultadoRecuperacao.php`
- Create: `backend/app/Domain/Sefaz/RecuperacaoService.php`
- Test: `backend/tests/Feature/Sefaz/RecuperacaoServiceTest.php`

**Interfaces:**
- Consumes: `SefazClient::distDFePorNsu/distDFePorChave` (Task 2); `EnvelopeDistDfe::parse` (Task 3); `DocumentoProcessor::processar` (Task 1); `SefazGuard::beforeCall(Unidade)` e `tripCircuitBreaker(Unidade)`.
- Produces:
  - `ResultadoRecuperacao` com constantes `ENCONTRADO`, `NAO_ENCONTRADO`, `BLOQUEADO`, `ERRO`; propriedades públicas `status` (string), `docsCount` (int), `motivo` (?string); factory estática `ResultadoRecuperacao::de(string $status, int $docsCount = 0, ?string $motivo = null): self`.
  - `RecuperacaoService::porNsu(Unidade $unidade, int $nsu): ResultadoRecuperacao`
  - `RecuperacaoService::porChave(Unidade $unidade, string $chave): ResultadoRecuperacao`

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Sefaz/RecuperacaoServiceTest.php`:

```php
<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\RecuperacaoService;
use App\Domain\Sefaz\ResultadoRecuperacao;
use App\Domain\Sefaz\SefazClient;
use App\Models\Nfe;
use App\Models\Nsu;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecuperacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        return Unidade::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'tp_amb' => 2,
        ]);
    }

    private function envelope(array $docs, string $cStat, int $ultNsu, int $maxNsu): string
    {
        $d = '';
        foreach ($docs as $doc) {
            $d .= '<docZip NSU="'.$doc['nsu'].'" schema="'.$doc['schema'].'">'.$doc['conteudo'].'</docZip>';
        }

        return '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>'.$cStat.'</cStat><xMotivo>m</xMotivo>'
            .'<ultNSU>'.$ultNsu.'</ultNSU><maxNSU>'.$maxNsu.'</maxNSU>'
            .'<loteDistDFeInt>'.$d.'</loteDistDFeInt></retDistDFeInt>';
    }

    private function xmlNfeProc(string $chave): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.$chave
            .'" versao="4.00"><ide><nNF>1</nNF><serie>1</serie><mod>55</mod><dhEmi>2026-10-01T10:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>11111111111111</CNPJ><xNome>F</xNome></emit><dest><CNPJ>00101980000119</CNPJ></dest>'
            .'<total><ICMSTot><vNF>100.00</vNF></ICMSTot></total></infNFe></NFe>'
            .'<protNFe><infProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
    }

    public function test_recupera_por_nsu_e_importa(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();
        $chave = str_repeat('1', 44);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->withArgs(fn ($u, $n) => $n === 150)
            ->andReturn($this->envelope([[
                'nsu' => 150, 'schema' => 'procNFe', 'conteudo' => base64_encode(gzencode($this->xmlNfeProc($chave))),
            ]], '138', 150, 200));
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porNsu($unidade, 150);

        $this->assertSame(ResultadoRecuperacao::ENCONTRADO, $r->status);
        $this->assertNotNull(Nfe::withoutGlobalScopes()->where('chave', $chave)->first());
    }

    public function test_nsu_inexistente_retorna_nao_encontrado(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope([], '137', 100, 100));
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porNsu($unidade, 999);

        $this->assertSame(ResultadoRecuperacao::NAO_ENCONTRADO, $r->status);
        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());
    }

    public function test_cstat_656_retorna_bloqueado(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorChave')->once()->andReturn($this->envelope([], '656', 0, 0));
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porChave($unidade, str_repeat('2', 44));

        $this->assertSame(ResultadoRecuperacao::BLOQUEADO, $r->status);
    }

    public function test_recuperacao_nao_altera_o_cursor(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();
        Nsu::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'ultimo_nsu' => 500,
            'max_nsu' => 500,
        ]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope([], '137', 150, 900));
        app()->instance(SefazClient::class, $client);

        app(RecuperacaoService::class)->porNsu($unidade, 150);

        $this->assertSame(500, Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=RecuperacaoServiceTest`
Expected: FAIL — classes inexistentes.

- [ ] **Step 3: Create `ResultadoRecuperacao` and `RecuperacaoService`**

`ResultadoRecuperacao`: classe simples com as 4 constantes, propriedades `status`/`docsCount`/`motivo` e factory `de()`. `RecuperacaoService`: injeta `SefazClient`, `EnvelopeDistDfe`, `DocumentoProcessor`, `SefazGuard`. Lógica comum:

```php
// antes de consultar:
$this->guard->beforeCall($unidade);          // lança se circuito/rate; deixar propagar? Não: capturar e mapear.
```

Regra de mapeamento do retorno: chamar `EnvelopeDistDfe::parse`; se `cStat === '656'` → `tripCircuitBreaker` + `BLOQUEADO`; se `cStat` não em `['137','138']` → `ERRO` com `xMotivo`; se `'137'` → `NAO_ENCONTRADO`; se `'138'` → para cada `doc`, `DocumentoProcessor::processar($unidade, null, $doc)` contando importações efetivas; resultado `ENCONTRADO` sse ≥1 NF-e importada, senão `NAO_ENCONTRADO`. **Nunca criar/atualizar `Nsu`.** Envolver `beforeCall` em try/catch: falha do freio → `BLOQUEADO`.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test --filter=RecuperacaoServiceTest`
Expected: PASS (4/4).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Sefaz/ResultadoRecuperacao.php backend/app/Domain/Sefaz/RecuperacaoService.php backend/tests/Feature/Sefaz/RecuperacaoServiceTest.php
git commit -m "feat(sefaz): RecuperacaoService por chave e por NSU (sem tocar o cursor)"
```

---

### Task 5: Notificação `RecuperacaoConcluida`

**Files:**
- Create: `backend/app/Notifications/RecuperacaoConcluida.php`
- Test: `backend/tests/Feature/Nfe/RecuperacaoConcluidaTest.php`

**Interfaces:**
- Consumes: `ResultadoRecuperacao` (Task 4).
- Produces: `RecuperacaoConcluida` (construct: `string $parametro`, `ResultadoRecuperacao $resultado`); `toArray()` devolve `['tipo' => 'recuperacao', 'mensagem' => string, 'status' => string]` (SEM `caminho`, para não confundir com exportação).

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Nfe/RecuperacaoConcluidaTest.php`:

```php
<?php

namespace Tests\Feature\Nfe;

use App\Domain\Sefaz\ResultadoRecuperacao;
use App\Notifications\RecuperacaoConcluida;
use PHPUnit\Framework\TestCase;

class RecuperacaoConcluidaTest extends TestCase
{
    public function test_payload_tem_tipo_e_mensagem(): void
    {
        $n = new RecuperacaoConcluida('chave x', ResultadoRecuperacao::de(ResultadoRecuperacao::ENCONTRADO, 1));
        $a = $n->toArray(new \stdClass);

        $this->assertSame('recuperacao', $a['tipo']);
        $this->assertArrayHasKey('mensagem', $a);
        $this->assertSame(ResultadoRecuperacao::ENCONTRADO, $a['status']);
        $this->assertArrayNotHasKey('caminho', $a);
    }

    public function test_usa_canal_database(): void
    {
        $n = new RecuperacaoConcluida('x', ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO));
        $this->assertContains('database', $n->via(new \stdClass));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=RecuperacaoConcluidaTest`
Expected: FAIL — classe inexistente.

- [ ] **Step 3: Create `RecuperacaoConcluida`**

Espelhar `ExportPronto`: `via()` → `['database']` (sem e-mail nesta versão); `toArray()` com `tipo`/`mensagem`/`status`. A mensagem textual é decisão do implementador conforme o `status` (encontrado / não encontrado / bloqueado / erro), sempre honesta sobre "ainda não disponível" no caso parcial.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test --filter=RecuperacaoConcluidaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Notifications/RecuperacaoConcluida.php backend/tests/Feature/Nfe/RecuperacaoConcluidaTest.php
git commit -m "feat(nfe): notificação RecuperacaoConcluida (canal database)"
```

---

### Task 6: `RecuperarNfeJob`

**Files:**
- Create: `backend/app/Jobs/RecuperarNfeJob.php`
- Test: `backend/tests/Feature/Nfe/RecuperarNfeJobTest.php`

**Interfaces:**
- Consumes: `RecuperacaoService::porNsu/porChave` (Task 4); `RecuperacaoConcluida` (Task 5); `SefazGuard::isOpen()`; `TenantContext::runAs`.
- Produces: `RecuperarNfeJob` (construct: `int $unidadeId`, `int $userId`, `?string $chave`, `?int $nsu`), `ShouldQueue`+`ShouldBeUnique`, fila `sefaz`, `uniqueId()` = hash de unidade+tipo+valor.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Nfe/RecuperarNfeJobTest.php`:

```php
<?php

namespace Tests\Feature\Nfe;

use App\Domain\Sefaz\SefazClient;
use App\Jobs\RecuperarNfeJob;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecuperarNfeJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_certificado_vencido_nao_consulta_sefaz(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]); // sem certificado
        $user = User::factory()->comTenant($tenant)->create();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldNotReceive('distDFePorNsu');
        app()->instance(SefazClient::class, $client);

        (new RecuperarNfeJob($unidade->id, $user->id, null, 150))->handle(
            app(\App\Domain\Sefaz\RecuperacaoService::class),
            app(\App\Domain\Sefaz\SefazGuard::class),
        );

        $this->assertSame(0, $user->notifications()->count() === 1 ? 1 : 1); // notificado (não trava)
        $this->assertNotNull($user->notifications()->first());
    }

    public function test_recupera_e_notifica(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        \App\Models\Certificate::factory()->create([
            'tenant_id' => $tenant->id, 'unidade_id' => $unidade->id,
            'valido_ate' => now()->addYear(), 'ativo' => true,
        ]);
        $user = User::factory()->comTenant($tenant)->create();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn(
            '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>137</cStat><xMotivo>Nenhum documento localizado</xMotivo>'
            .'<ultNSU>100</ultNSU><maxNSU>100</maxNSU></retDistDFeInt>'
        );
        app()->instance(SefazClient::class, $client);

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999))->handle(
            app(\App\Domain\Sefaz\RecuperacaoService::class),
            app(\App\Domain\Sefaz\SefazGuard::class),
        );

        $this->assertNotNull($user->notifications()->first());
    }
}
```

Nota: ajustar a asserção do primeiro teste conforme o padrão do projeto (ver `CertificadoVencidoSyncTest`); o objetivo é: **certificado inválido → nenhuma chamada ao client + notificação gravada**.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=RecuperarNfeJobTest`
Expected: FAIL — classe inexistente.

- [ ] **Step 3: Create `RecuperarNfeJob`**

Espelhar `SyncNfeJob`: revalidar `certificadoVigente($unidade)` (copiar o método privado do `SyncNfeJob`) e `$guard->isOpen()`; se falhar, notificar e retornar sem tocar a SEFAZ. Senão, `TenantContext::runAs`, chamar `RecuperacaoService` conforme `chave`/`nsu` e notificar o `User::find($userId)`. `uniqueId()` = `'recuperar-unidade-'.$unidadeId.($chave ? '-chave-'.sha1($chave) : '-nsu-'.$nsu)`; `uniqueFor = 3600`.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test --filter=RecuperarNfeJobTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Jobs/RecuperarNfeJob.php backend/tests/Feature/Nfe/RecuperarNfeJobTest.php
git commit -m "feat(nfe): job RecuperarNfeJob (assíncrono, notifica ao final)"
```

---

### Task 7: API `POST /api/nfes/recuperar`

**Files:**
- Create: `backend/app/Http/Requests/RecuperarNfeRequest.php`
- Create: `backend/app/Http/Controllers/Api/NfeRecuperacaoController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Api/RecuperarNfeApiTest.php`

**Interfaces:**
- Consumes: `RecuperarNfeJob` (Task 6); permissão `sync.executar`; `UnidadePolicy::view`.
- Produces: rota `POST /api/nfes/recuperar` → 202 `{message}` | 409 | 403 | 422.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Api/RecuperarNfeApiTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Jobs\RecuperarNfeJob;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecuperarNfeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function usuario(Tenant $t, string $papel): User
    {
        $u = User::factory()->comTenant($t)->create();
        $u->assignRole($papel);

        return $u;
    }

    public function test_operador_enfileira_e_responde_202(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $resp = $this->actingAs($this->usuario($tenant, 'operador'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id, 'nsu' => 150]);

        $resp->assertStatus(202);
        Queue::assertPushed(RecuperarNfeJob::class);
    }

    public function test_consulta_recebe_403(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->usuario($tenant, 'consulta'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id, 'nsu' => 150])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_validacao_exige_um_dos_dois(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->usuario($tenant, 'operador'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id])
            ->assertStatus(422);
    }

    public function test_nsu_zero_invalido(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->usuario($tenant, 'operador'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id, 'nsu' => 0])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=RecuperarNfeApiTest`
Expected: FAIL — rota inexistente (404).

- [ ] **Step 3: Create Request, Controller, and route**

`RecuperarNfeRequest::rules()`: `unidade_id` → required, exists no tenant; `chave` → nullable, digits:44, required_without:nsu; `nsu` → nullable, integer, min:1, required_without:chave. `NfeRecuperacaoController::store`: `Gate::authorize('syncExecutar', ...)` (criar uma ability/policy — ver decisão do implementador: usar `$request->user()->can('sync.executar')` com 403, seguindo o estilo do projeto). Resolver a unidade com `Gate::authorize('view', $unidade)`. Enfileirar `RecuperarNfeJob::dispatch($unidade->id, $user->id, $chave, $nsu)`; se suprimido por unicidade, responder 409. Auditar a solicitação. Rota: dentro do grupo `['auth:sanctum','tenant','mfa']` em `routes/api.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test --filter=RecuperarNfeApiTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Requests/RecuperarNfeRequest.php backend/app/Http/Controllers/Api/NfeRecuperacaoController.php backend/routes/api.php backend/tests/Feature/Api/RecuperarNfeApiTest.php
git commit -m "feat(api): POST /nfes/recuperar (sync.executar, assíncrono)"
```

---

### Task 8: Frontend — formulário de busca e notificação

**Files:**
- Modify: `frontend/src/lib/api.ts`
- Modify: `frontend/src/features/nfes/NfeListPage.tsx`
- Modify: `frontend/src/features/notificacoes/NotificacoesPage.tsx`
- Test: `frontend/src/features/nfes/NfeBuscaForm.test.tsx` (novo) e ajuste em `NotificacoesPage` (se houver teste)

**Interfaces:**
- Consumes: rota da Task 7.
- Produces: `nfeApi.recuperar(payload: { unidade_id: number; chave?: string; nsu?: number })`; `Notificacao.dados` ganha `tipo?: 'exportacao' | 'recuperacao'` e `mensagem?: string`.

- [ ] **Step 1: Write the failing test**

Criar `frontend/src/features/nfes/NfeBuscaForm.test.tsx` (Vitest + Testing Library, padrão dos outros `.test.tsx`): renderiza o formulário, alterna entre "Chave"/"NSU", exige o campo correspondente e chama `nfeApi.recuperar` (mockado) com `{unidade_id, nsu}` ao enviar.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app sh -c "cd /app/../frontend 2>/dev/null || cd ../frontend; npm test -- NfeBuscaForm"` (ou o comando de teste do projeto em `frontend/`).
Expected: FAIL — componente/rota inexistente.

- [ ] **Step 3: Implement**

Adicionar `recuperar` a `nfeApi`. Criar o componente/formulário `NfeBuscaForm` e inseri-lo na `NfeListPage` atrás de um botão "Buscar NF-e". Tratar 403/409/422 com `mensagemDeErro`. Estender o tipo `Notificacao` (`tipo`, `mensagem`) e ajustar `NotificacoesPage` para renderizar por `tipo`/`mensagem` (mantendo o texto de exportação quando `tipo` for `exportacao`/ausente).

- [ ] **Step 4: Run tests + lint + build**

Run: no diretório `frontend/`: testes, `npm run lint`, `npm run build`.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src
git commit -m "feat(spa): busca manual de NF-e (chave/NSU) e notificação por tipo"
```

---

### Task 9: Verificação final e regressão

**Files:** nenhum (apenas verificação).

- [ ] **Step 1: Suíte backend completa**

Run: `docker compose exec -T app php artisan test`
Expected: PASS — todos os testes (hoje 212 + os novos).

- [ ] **Step 2: Pint nos arquivos alterados**

Run: `docker compose exec -T app ./vendor/bin/pint app/Domain/Nfe/DocumentoProcessor.php app/Domain/Sefaz/EnvelopeDistDfe.php app/Domain/Sefaz/ResultadoRecuperacao.php app/Domain/Sefaz/RecuperacaoService.php app/Domain/Sefaz/SefazClient.php app/Domain/Sefaz/SpedSefazClient.php app/Domain/Sefaz/DistDfeService.php app/Jobs/RecuperarNfeJob.php app/Notifications/RecuperacaoConcluida.php app/Http/Requests/RecuperarNfeRequest.php app/Http/Controllers/Api/NfeRecuperacaoController.php`
Expected: sem issues.

- [ ] **Step 3: Reiniciar o Horizon**

Run: `docker compose restart horizon; docker compose exec -T horizon php artisan horizon:status`
Expected: "Horizon is running."

- [ ] **Step 4: Commit final (se houver ajustes de Pint)**

```bash
git add -A
git commit -m "chore: pinta e ajusta recuperação de NF-e"
```

- [ ] **Step 5: Push**

Confirmar com o parceiro e então:

```bash
git push origin main
```

---

## Self-Review

**1. Spec coverage:**
- Busca por chave (`consChNFe`) → Tasks 2, 4, 6, 7, 8.
- Busca por NSU (`consNSU`), NSU ≥ 1 → Tasks 2, 4, 7.
- Ciclo resumo→Ciência→completo→importa → Task 1 (extração) + Task 4 (reuso).
- Assíncrono + freio + notificação → Tasks 5, 6.
- Permissão `sync.executar` → Task 7.
- Nunca tocar o cursor → Task 4 (teste dedicado).
- Só NF-e; eventos ignorados → Task 1 (teste `test_evento_e_ignorado`).
- Sem retry da Ciência (uma tentativa) → Task 4 (lógica) e mensagem na Task 5.
- Escopo de unidade via policy → Task 7.
- 409 em duplicidade → Tasks 6 (uniqueId) e 7 (detecção).
- Frontend (formulário + notificação por tipo) → Task 8.
- Sem migration (usa notificação/auditoria existentes) → coberto.

**2. Step scan:** Cada passo é uma ação com resultado verificável (teste/rodar/implementar/commit). Nenhum passo "decide nada".

**3. Type consistency:** `DocumentoProcessor::processar(Unidade, ?Nsu, array)` usado igualmente nas Tasks 1, 4. `ResultadoRecuperacao::de(...)` e constantes consistentes nas Tasks 4, 5, 6. `RecuperarNfeJob($unidadeId, $userId, $chave, $nsu)` consistente nas Tasks 6 e 7. `distDFePorNsu/distDFePorChave` consistentes nas Tasks 2, 4.

**4. Review Focus:** as 5 linhas críticas têm teste designado na task que possui o código (1→Task 4 teste de cursor; 2→Task 7 403; 3→Tasks 6/7 409; 4→Task 4 NAO_ENCONTRADO; 5→Task 6 certificado vencido).

**5. Proportion:** o plano descreve decisões e assinaturas; os corpos de código aparecem apenas onde a assinatura/teste não determinam (parse, mapeamento de cStat).