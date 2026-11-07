# Implementação — Retentativa automática da recuperação manual de NF-e

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quando uma recuperação manual de NF-e falha por motivo retentável (não encontrada ou erro), o sistema reagenda sozinho até 3 vezes (30 min → 2h → 6h), esperando o freio da SEFAZ quando bloqueado, e só notifica o usuário com erro após esgotar as tentativas.

**Architecture:** O `RecuperarNfeJob` ganha um contador `tentativa`. Ao terminar sem sucesso retentável e ainda haver tentativas, ele despacha um novo `RecuperarNfeJob` com `delay()` — cada execução é um job novo, sem colidir com o `ShouldBeUnique`. O `SyncNfeJob` só é disparado no desfecho final. Os intervalos vivem em `config/nfegov.php`. O `SefazGuard` expõe o tempo restante do freio para o cálculo do delay em caso de `bloqueado`.

**Tech Stack:** Laravel 13, Horizon (fila Redis), PHPUnit, `Cache` (Redis).

**Spec:** `docs/superpowers/specs/2026-11-07-recuperacao-retentativa-automatica-design.md`

## Global Constraints

- Idioma de código e comentários: **português do Brasil** (seguir o estilo do projeto).
- Sem novas dependências.
- Toda chamada à SEFAZ continua passando pelos freios (`SefazGuard`) — nada de burlar rate limiter/circuit breaker.
- Desfechos retentáveis: `nao_encontrado` e `erro`. `bloqueado` reagenda **esperando o freio** e **não consome tentativa**. `encontrado` nunca reagenda.
- Backoff das tentativas automáticas (após a original): `[30 min, 2h, 6h]` — 3 tentativas, configurável via `NFEGOV_RECUPERACAO_BACKOFF_MINUTES`.
- `SyncNfeJob` dispara **apenas** no desfecho final (sucesso ou tentativas esgotadas).
- `uniqueFor` do job: `24h` (maior que o maior delay, 6h).

## Review Focus

Inputs/condições que o código vai encontrar e que merecem teste, mais prováveis primeiro:

1. **`bloqueado` na última tentativa** — o usuário não pode receber "tentativas esgotadas" como se fosse falha definitiva; a mensagem deve falar de freio de consumo.
2. **Fila de delay sobrevivendo ao `Cache::forget` da idempotência** — liberar a chave ao fim de cada execução não pode permitir um segundo job duplicado em paralelo ao reagendado.
3. **`SyncNfeJob` duplicado** — reagendar não pode disparar o sync; só o desfecho final dispara.
4. **`tempo_restante_do_freio` no passado/zero** — delay nunca pode ser negativo nem zero.
5. **Config ausente/valor inválido** — `recuperacao.backoff_minutes` vazio não pode estourar índice inexistente.

---

### Task 1: Intervalos de backoff na config

**Files:**
- Modify: `backend/config/nfegov.php`

**Interfaces:**
- Produces: `config('nfegov.recuperacao.backoff_minutes')` → `array<int>` (minutos), e `config('nfegov.recuperacao.circuit_breaker_minutes')` não aqui (usa `sefaz.circuit_breaker_minutes` já existente).

- [ ] **Step 1: Adicionar o bloco de config**

No `backend/config/nfegov.php`, adicionar um bloco `'recuperacao'` (pode ficar logo após o bloco `'sefaz'`), com este conteúdo:

```php
    /*
    |--------------------------------------------------------------------------
    | Recuperação manual de NF-e — retentativa automática
    |--------------------------------------------------------------------------
    |
    | Quando uma busca manual (chave/NSU) falha por motivo retentável
    | (`nao_encontrado` ou `erro`), o job reagenda sozinho respeitando estes
    | intervalos (minutos), um por tentativa automática, nesta ordem. Para
    | `bloqueado`, o delay é o maior entre o intervalo e o tempo restante do
    | freio (circuit breaker) — a tentativa não é consumida nesse caso.
    |
    */

    'recuperacao' => [
        'backoff_minutes' => array_map(
            'intval',
            explode(',', (string) env('NFEGOV_RECUPERACAO_BACKOFF_MINUTES', '30,120,360')),
        ),
    ],
```

- [ ] **Step 2: Verificar que a config carrega**

Run: `docker compose exec -T app php artisan tinker --execute="echo json_encode(config('nfegov.recuperacao.backoff_minutes'));"`
Expected: `[30,120,360]`

- [ ] **Step 3: Commit**

```bash
git add backend/config/nfegov.php
git commit -m "feat(recuperacao): intervalos de backoff configuraveis"
```

---

### Task 2: `SefazGuard::minutosRestantesDoFreio()`

**Files:**
- Modify: `backend/app/Domain/Sefaz/SefazGuard.php`
- Test: `backend/tests/Feature/Sefaz/SefazGuardTest.php` (criar)

**Interfaces:**
- Consumes: `SefazGuard::tripCircuitBreaker(Unidade)`, `isOpen()` (já existem).
- Produces: `SefazGuard::minutosRestantesDoFreio(): int` — minutos restantes até o freio fechar; `0` quando fechado. Nunca negativo.

- [ ] **Step 1: Escrever o teste (RED)**

Criar `backend/tests/Feature/Sefaz/SefazGuardTest.php`:

```php
<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\SefazGuard;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tempo restante do freio global (usado para reagendar a recuperação).
 */
class SefazGuardTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        return Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    }

    public function test_sem_freio_o_tempo_restante_e_zero(): void
    {
        $this->assertSame(0, app(SefazGuard::class)->minutosRestantesDoFreio());
    }

    public function test_com_freio_aberto_o_tempo_restante_e_positivo(): void
    {
        $guard = app(SefazGuard::class);
        $guard->tripCircuitBreaker($this->unidade());

        $restante = $guard->minutosRestantesDoFreio();

        // Circuit breaker padrão = 60 min; usar intervalo amplo evita flake.
        $this->assertGreaterThan(0, $restante);
        $this->assertLessThanOrEqual(60, $restante);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -T app php artisan test --filter=SefazGuardTest`
Expected: FAIL — `Call to undefined method ...::minutosRestantesDoFreio()`.

- [ ] **Step 3: Implementar**

Em `SefazGuard`, adicionar o método. O freio é gravado em `Cache` com TTL; o tempo restante = `agora + ttl - agora`. Ler o valor guardado e calcular pelo `aberto_em` + `circuit_breaker_minutes`:

```php
    /**
     * Minutos restantes até o freio fechar. `0` quando fechado.
     *
     * Usado para adiar a próxima tentativa da recuperação manual: se o freio
     * está aberto, não adianta reagendar antes de ele fechar.
     */
    public function minutosRestantesDoFreio(): int
    {
        $dados = Cache::get(self::CHAVE_CIRCUITO);

        if (! is_array($dados) || ! isset($dados['aberto_em'])) {
            return 0;
        }

        $minutos = (int) config('nfegov.sefaz.circuit_breaker_minutes', 60);
        $fechaEm = \Illuminate\Support\Carbon::parse($dados['aberto_em'])->addMinutes($minutos);

        return max(0, (int) ceil(now()->diffInSeconds($fechaEm, false) / 60));
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose exec -T app php artisan test --filter=SefazGuardTest`
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Sefaz/SefazGuard.php backend/tests/Feature/Sefaz/SefazGuardTest.php
git commit -m "feat(recuperacao): expoe tempo restante do freio no SefazGuard"
```

---

### Task 3: Parâmetro `tentativa` no `RecuperarNfeJob` + reagendamento

**Files:**
- Modify: `backend/app/Jobs/RecuperarNfeJob.php`
- Test: `backend/tests/Feature/Nfe/RecuperarNfeJobTest.php` (estender)

**Interfaces:**
- Consumes: `ResultadoRecuperacao::de(...)` (constantes `ENCONTRADO`/`NAO_ENCONTRADO`/`BLOQUEADO`/`ERRO`), `SefazGuard::minutosRestantesDoFreio()`, `config('nfegov.recuperacao.backoff_minutes')`.
- Produces: construtor `RecuperarNfeJob(int $unidadeId, int $userId, ?string $chave = null, ?int $nsu = null, int $tentativa = 1)`; métodos `deveReagendar(ResultadoRecuperacao): bool` e `delayDoReagendamento(ResultadoRecuperacao, SefazGuard): int` (segundos).

- [ ] **Step 1: Escrever os testes (RED)**

Adicionar a `RecuperarNfeJobTest` (o `setUp` já faz `Queue::fake()`):

```php
    public function test_nao_encontrado_reagenda_com_backoff(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope('137'));
        app()->instance(SefazClient::class, $client);

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 1))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        // Reagenda como tentativa 2, com um delay agendado (1º backoff).
        Queue::assertPushed(
            RecuperarNfeJob::class,
            fn ($job) => $job->tentativa === 2 && $job->delay instanceof \DateTimeInterface,
        );
        // E NÃO dispara o sync (só no desfecho final).
        Queue::assertNotPushed(SyncNfeJob::class);
    }

    public function test_encontrado_nao_reagenda_e_dispara_sync(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        // Testa o caminho de sucesso com um service mockado que devolve
        // ENCONTRADO (evita depender do parse do XML do envelope).
        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::ENCONTRADO, 1));

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 1))->handle(
            $service,
            app(SefazGuard::class),
        );

        Queue::assertNotPushed(RecuperarNfeJob::class);
        Queue::assertPushed(SyncNfeJob::class);
    }

    public function test_terceira_tentativa_falha_nao_reagenda_e_notifica(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'sem nota'));

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 3))->handle(
            $service,
            app(SefazGuard::class),
        );

        Queue::assertNotPushed(RecuperarNfeJob::class);
        Queue::assertPushed(SyncNfeJob::class);
        $this->assertNotNull($user->notifications()->first());
    }

    public function test_bloqueado_espera_o_freio_e_nao_consome_tentativa(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        // Freio aberto: o reagendamento deve esperar o freio (>= 1 min).
        app(SefazGuard::class)->tripCircuitBreaker($unidade);

        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::BLOQUEADO, 0, '656'));

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 2))->handle(
            $service,
            app(SefazGuard::class),
        );

        // Reagenda mantendo a MESMA tentativa (não consumiu) e com delay.
        Queue::assertPushed(
            RecuperarNfeJob::class,
            fn ($job) => $job->tentativa === 2 && $job->delay instanceof \DateTimeInterface,
        );
    }
```

Adicionar ao topo do arquivo: `use App\Domain\Sefaz\ResultadoRecuperacao;`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -T app php artisan test --filter=RecuperarNfeJobTest`
Expected: FAIL — o construtor ainda não aceita `tentativa` / não reagenda.

- [ ] **Step 3: Implementar no `RecuperarNfeJob`**

No construtor, adicionar o parâmetro e o campo:

```php
    public function __construct(
        public readonly int $unidadeId,
        public readonly int $userId,
        public readonly ?string $chave = null,
        public readonly ?int $nsu = null,
        public readonly int $tentativa = 1,
    ) {
        $this->onQueue(config('nfegov.sefaz.queue', 'sefaz'));
    }
```

Trocar `public int $uniqueFor = 3600;` por `public int $uniqueFor = 86400;` (24h).

Adicionar os métodos de decisão:

```php
    /**
     * Desfechos que valem uma nova tentativa automática.
     */
    public function deveReagendar(ResultadoRecuperacao $resultado): bool
    {
        return in_array($resultado->status, [
            ResultadoRecuperacao::NAO_ENCONTRADO,
            ResultadoRecuperacao::ERRO,
            ResultadoRecuperacao::BLOQUEADO,
        ], true);
    }

    /**
     * Segundos até a próxima tentativa. Para `bloqueado`, espera o freio;
     * nos demais, usa o intervalo progressivo da config.
     */
    public function delayDoReagendamento(ResultadoRecuperacao $resultado, SefazGuard $guard): int
    {
        $backoff = array_values((array) config('nfegov.recuperacao.backoff_minutes', [30, 120, 360]));
        $indice = max(0, $this->tentativa - 1);
        $minutos = $backoff[$indice] ?? end($backoff) ?: 30;

        if ($resultado->status === ResultadoRecuperacao::BLOQUEADO) {
            $minutos = max($minutos, $guard->minutosRestantesDoFreio());
        }

        return max(60, $minutos * 60);
    }

    /**
     * Há tentativas automáticas restantes?
     */
    private function temTentativasRestantes(): bool
    {
        return $this->tentativa < count((array) config('nfegov.recuperacao.backoff_minutes', [30, 120, 360]));
    }
```

No `executar()`, substituir o bloco final (a partir do `$resultado = ...`) por:

```php
            $resultado = $this->chave !== null
                ? $recuperacao->porChave($unidade, $this->chave)
                : $recuperacao->porNsu($unidade, (int) $this->nsu);

            // Desfecho retentável e ainda há tentativas: reagenda sem notificar.
            // `bloqueado` mantém a MESMA tentativa (não consome) e espera o freio.
            if ($this->deveReagendar($resultado) && $this->temTentativasRestantes()) {
                $proxima = $resultado->status === ResultadoRecuperacao::BLOQUEADO
                    ? $this->tentativa
                    : $this->tentativa + 1;

                self::dispatch($this->unidadeId, $this->userId, $this->chave, $this->nsu, $proxima)
                    ->delay(now()->addSeconds($this->delayDoReagendamento($resultado, $guard)));

                return;
            }

            // Desfecho final: consome a fila pelo caminho NORMAL e notifica.
            SyncNfeJob::dispatch($unidade->id);

            $user?->notify(new RecuperacaoConcluida($parametro, $resultado));
```

> Nota: quando `bloqueado` na última tentativa, cai no desfecho final e notifica (mensagem de freio — ver Task 4).

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose exec -T app php artisan test --filter=RecuperarNfeJobTest`
Expected: PASS em todos.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Jobs/RecuperarNfeJob.php backend/tests/Feature/Nfe/RecuperarNfeJobTest.php
git commit -m "feat(recuperacao): reagenda automaticamente ate 3 tentativas"
```

---

### Task 4: Mensagem de tentativas esgotadas na notificação

**Files:**
- Modify: `backend/app/Notifications/RecuperacaoConcluida.php`
- Test: `backend/tests/Feature/Nfe/RecuperacaoConcluidaTest.php` (estender)

**Interfaces:**
- Consumes: `ResultadoRecuperacao`.
- Produces: construtor `RecuperacaoConcluida(string $parametro, ResultadoRecuperacao $resultado, int $tentativas = 1)`.

- [ ] **Step 1: Escrever o teste (RED)**

Adicionar a `RecuperacaoConcluidaTest` (é `PHPUnit\Framework\TestCase`, sem DB):

```php
    public function test_mensagem_esgotada_apos_varias_tentativas(): void
    {
        $n = new RecuperacaoConcluida(
            'chave x',
            ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'sem nota'),
            4,
        );

        $msg = $n->toArray(new \stdClass)['mensagem'];

        $this->assertStringContainsString('tentativa', $msg);
        $this->assertStringContainsString('sem nota', $msg);
    }

    public function test_bloqueado_esgotado_fala_de_freio(): void
    {
        $n = new RecuperacaoConcluida(
            'chave x',
            ResultadoRecuperacao::de(ResultadoRecuperacao::BLOQUEADO, 0, '656'),
            4,
        );

        $msg = $n->toArray(new \stdClass)['mensagem'];

        $this->assertStringContainsString('indisponível', $msg);
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -T app php artisan test --filter=RecuperacaoConcluidaTest`
Expected: FAIL — construtor não aceita o 3º argumento.

- [ ] **Step 3: Implementar**

No construtor:

```php
    public function __construct(
        public readonly string $parametro,
        public readonly ResultadoRecuperacao $resultado,
        public readonly int $tentativas = 1,
    ) {}
```

Em `mensagem()`, prefixar os casos `NAO_ENCONTRADO` e `ERRO` (quando `$this->tentativas > 1`) com o aviso de esgotamento. Trocar o `match` por:

```php
    private function mensagem(): string
    {
        $esgotado = $this->tentativas > 1
            ? sprintf('Após %d tentativas automáticas, ', $this->tentativas)
            : '';

        return match ($this->resultado->status) {
            ResultadoRecuperacao::ENCONTRADO => sprintf(
                'Busca de %s concluída: %d nota(s) recuperada(s) e disponível(is) para consulta.',
                $this->parametro,
                $this->resultado->docsCount,
            ),
            ResultadoRecuperacao::NAO_ENCONTRADO => sprintf(
                '%sbusca de %s não teve sucesso: %s '
                .'Se a nota é recente, o emitente pode ainda não tê-la disponibilizado à SEFAZ; '
                .'caso contrário, confirme o destinatário com o emitente.',
                $esgotado,
                $this->parametro,
                $this->resultado->motivo ?? 'a SEFAZ não devolveu nenhum documento.',
            ),
            ResultadoRecuperacao::BLOQUEADO => sprintf(
                'Busca de %s não realizada: a SEFAZ está temporariamente indisponível (freio de consumo). Tente novamente mais tarde.',
                $this->parametro,
            ),
            default => sprintf(
                '%sbusca de %s falhou: %s',
                $esgotado,
                $this->parametro,
                $this->resultado->motivo ?? 'erro inesperado.',
            ),
        };
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose exec -T app php artisan test --filter=RecuperacaoConcluidaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Notifications/RecuperacaoConcluida.php backend/tests/Feature/Nfe/RecuperacaoConcluidaTest.php
git commit -m "feat(recuperacao): mensagem de tentativas esgotadas"
```

---

### Task 5: Passar `tentativas` na notificação final + suíte completa

**Files:**
- Modify: `backend/app/Jobs/RecuperarNfeJob.php`

**Interfaces:**
- Consumes: `RecuperacaoConcluida::__construct(string, ResultadoRecuperacao, int)`.

- [ ] **Step 1: Ajustar a notificação final**

Em `RecuperarNfeJob::executar()`, no desfecho final, passar o total de tentativas executadas:

```php
            $user?->notify(new RecuperacaoConcluida($parametro, $resultado, $this->tentativa));
```

(As duas notificações de bloqueio antecipado — certificado vencido e circuito aberto no início do `executar` — continuam com `tentativas` implícito `1`, pois não são retentáveis.)

- [ ] **Step 2: Rodar a suíte completa**

Run: `docker compose exec -T app php artisan test`
Expected: PASS — todos os testes verdes (incluindo os novos).

- [ ] **Step 3: Pint**

Run: `docker compose exec -T app ./vendor/bin/pint app/Jobs/RecuperarNfeJob.php app/Domain/Sefaz/SefazGuard.php app/Notifications/RecuperacaoConcluida.php config/nfegov.php tests/Feature/Nfe/RecuperarNfeJobTest.php tests/Feature/Nfe/RecuperacaoConcluidaTest.php tests/Feature/Sefaz/SefazGuardTest.php`
Expected: sem issues pendentes.

- [ ] **Step 4: Commit**

```bash
git add backend/app/Jobs/RecuperarNfeJob.php
git commit -m "feat(recuperacao): notifica quantidade de tentativas executadas"
```

---

### Task 6: Documentação

**Files:**
- Modify: `README.md`
- Modify: `backend/.env.example`

**Interfaces:**
- Produces: documentação do comportamento e da variável `NFEGOV_RECUPERACAO_BACKOFF_MINUTES`.

- [ ] **Step 1: Documentar no README**

Na seção **"Manifestação do destinatário"** (ou próxima à recuperação manual), adicionar um parágrafo:

```markdown
**Retentativa automática.** Quando uma busca manual (por chave ou NSU) não
encontra a nota (`nao_encontrado`) ou erra (`erro`), o sistema reagenda sozinho
— sem o usuário redigitar a chave — em intervalos progressivos (padrão
`30 min → 2h → 6h`, 3 tentativas). Se a SEFAZ estiver com o freio de consumo
acionado (`bloqueado`), a próxima tentativa espera o freio fechar e **não**
consome uma das três tentativas. Só após esgotá-las o usuário é notificado com
o erro.
```

- [ ] **Step 2: Documentar a variável no `.env.example`**

Adicionar em `backend/.env.example`, junto às demais `NFEGOV_*`:

```dotenv
# Intervalos (minutos) entre as tentativas automáticas da recuperação manual
# de NF-e. Um valor por tentativa, separados por vírgula.
NFEGOV_RECUPERACAO_BACKOFF_MINUTES=30,120,360
```

- [ ] **Step 3: Commit**

```bash
git add README.md backend/.env.example
git commit -m "docs(recuperacao): retentativa automatica e variavel de backoff"
```