<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Versão da aplicação
    |--------------------------------------------------------------------------
    |
    | A versão semântica vive num arquivo único `VERSION` na raiz do
    | repositório — fonte de verdade única, lida tanto pelo backend quanto
    | pelo frontend (via `GET /api/version`). O timestamp da build é gravado
    | em `build.json` (também na raiz) no momento do build; se ainda não
    | existir, `build_at` fica nulo e o frontend exibe só a versão.
    |
    */

    'versao' => [
        'numero' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0',

        // Momento da build (formato `Ymd.His`), gravado pelo script de build.
        'build_at' => (static function (): ?string {
            $arquivo = base_path('build.json');
            if (! is_file($arquivo)) {
                return null;
            }

            $dados = json_decode((string) file_get_contents($arquivo), true);
            $build = is_array($dados) ? ($dados['build_at'] ?? null) : null;

            return is_string($build) && $build !== '' ? $build : null;
        })(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sincronização com a SEFAZ
    |--------------------------------------------------------------------------
    |
    | Parâmetros do ciclo periódico de recepção de NF-e (distDFe). Os limites
    | de laço e o intervalo entre chamadas replicam o comportamento do sistema
    | original (helesjunior/nfegov) e existem para evitar o bloqueio por
    | "Consumo Indevido" (cStat 656) imposto pela SEFAZ.
    |
    */

    'sync' => [
        // Intervalo do agendamento da NF-e, em horas (padrão: de hora em hora).
        'interval_hours' => (int) env('NFEGOV_SYNC_INTERVAL_HOURS', 1),

        // Teto de iterações do laço distDFe no primeiro "catch-up" (NSU zerado).
        'loop_limit_catchup' => (int) env('NFEGOV_SYNC_LOOP_LIMIT_CATCHUP', 150),

        // Teto de iterações do laço distDFe nas consultas subsequentes.
        'loop_limit_normal' => (int) env('NFEGOV_SYNC_LOOP_LIMIT_NORMAL', 100),

        // Pausa (segundos) entre cada consulta distDFe (respiro para a SEFAZ).
        'sleep_seconds' => (int) env('NFEGOV_SYNC_SLEEP_SECONDS', 2),

        // Só de segunda a sexta. A SEFAZ autoriza e distribui NF-e todos os
        // dias; `false` também consulta no fim de semana.
        'weekdays_only' => (bool) env('NFEGOV_SYNC_WEEKDAYS_ONLY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Freio global contra bloqueio da SEFAZ (multitenant)
    |--------------------------------------------------------------------------
    |
    | Em instalação central, todos os tenants compartilham o mesmo IP de saída.
    | O limite da SEFAZ é por IP, então o freio precisa ser global (não por
    | tenant) para que um órgão não derrube o acesso dos demais.
    |
    */

    'sefaz' => [
        // Máximo de consultas distDFe por minuto no servidor inteiro.
        'rate_per_minute' => (int) env('NFEGOV_SEFAZ_RATE_PER_MINUTE', 30),

        // Layout (schemes) e versão do leiaute da NF-e usados pelo sped-nfe.
        'schemes' => env('NFEGOV_SEFAZ_SCHEMES', 'PL_009_V4'),
        'versao' => env('NFEGOV_SEFAZ_VERSAO', '4.00'),

        // Ao receber cStat 656, a fila de sync pausa por este tempo (minutos).
        'circuit_breaker_minutes' => (int) env('NFEGOV_SEFAZ_CIRCUIT_BREAKER_MINUTES', 60),

        // Janela (minutos) em que as unidades são escalonadas para não criar picos.
        'slot_window_minutes' => (int) env('NFEGOV_SEFAZ_SLOT_WINDOW_MINUTES', 180),

        // Nome da fila dedicada à comunicação com a SEFAZ.
        'queue' => env('NFEGOV_SEFAZ_QUEUE', 'sefaz'),

        // Concorrência máxima de workers na fila da SEFAZ (mesmo IP).
        'workers' => (int) env('NFEGOV_SEFAZ_WORKERS', 2),

        // Freio anti-bloqueio por unidade (réplica dos limites do legado).
        // Máximo de iterações (consultas distDFe) por execução de sync.
        'max_iteracoes' => (int) env('NFEGOV_SEFAZ_MAX_ITERACOES', 150),
        // Máximo de documentos processados por execução.
        'max_docs' => (int) env('NFEGOV_SEFAZ_MAX_DOCS', 100),
        // Pausa (segundos) entre consultas, para não martelar a SEFAZ.
        'sleep_segundos' => (int) env('NFEGOV_SEFAZ_SLEEP', 2),

        // Regra da SEFAZ: depois de ver a fila vazia (ultNSU = maxNSU), esperar
        // 1 hora antes de consultar de novo, senão volta cStat 656. 61 = folga.
        'espera_fila_vazia_minutos' => (int) env('NFEGOV_SEFAZ_ESPERA_FILA_VAZIA_MINUTES', 61),

        // Quando a execução para no teto (iterações/documentos) e a fila ainda
        // tem documentos, a próxima parte começa depois destes segundos, em vez
        // de esperar a próxima rodada do agendador.
        'continuacao_segundos' => (int) env('NFEGOV_SEFAZ_CONTINUACAO_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | NFS-e — Ambiente de Dados Nacional (ADN)
    |--------------------------------------------------------------------------
    |
    | Recepção das NFS-e pela API de distribuição de contribuintes do ADN
    | (`GET /DFe/{NSU}`), com mTLS e o mesmo certificado e-CNPJ da unidade.
    | Lotes de até 50 documentos; fila vazia volta como HTTP 404. O ADN não tem
    | cStat 656, mas o freio continua: teto por minuto e circuit breaker
    | próprios, que abrem em HTTP 429/503 ou falha de conexão.
    |
    */

    'adn' => [
        // Liga/desliga a recepção de NFS-e na instalação (não afeta a NF-e).
        'habilitado' => (bool) env('NFEGOV_ADN_HABILITADO', true),

        // URL base por ambiente da unidade (`tp_amb`: 1 produção, 2 produção restrita).
        'base_url' => [
            1 => env('NFEGOV_ADN_URL_PRODUCAO', 'https://adn.nfse.gov.br/contribuintes'),
            2 => env('NFEGOV_ADN_URL_HOMOLOGACAO', 'https://adn.producaorestrita.nfse.gov.br/contribuintes'),
        ],

        // SEFIN Nacional: recebe o pedido de registro de evento (manifestação).
        'sefin_url' => [
            1 => env('NFEGOV_SEFIN_URL_PRODUCAO', 'https://sefin.nfse.gov.br/SefinNacional'),
            2 => env('NFEGOV_SEFIN_URL_HOMOLOGACAO', 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional'),
        ],

        'queue' => env('NFEGOV_ADN_QUEUE', 'adn'),
        'timeout' => (int) env('NFEGOV_ADN_TIMEOUT', 30),
        'rate_per_minute' => (int) env('NFEGOV_ADN_RATE_PER_MINUTE', 30),
        'circuit_breaker_minutes' => (int) env('NFEGOV_ADN_CIRCUIT_BREAKER_MINUTES', 30),
        'slot_window_minutes' => (int) env('NFEGOV_ADN_SLOT_WINDOW_MINUTES', 180),

        // Por execução de uma unidade. A carga do histórico (que o ADN entrega
        // desde o primeiro documento do CNPJ) se divide em várias execuções.
        'max_iteracoes' => (int) env('NFEGOV_ADN_MAX_ITERACOES', 20),
        'max_docs' => (int) env('NFEGOV_ADN_MAX_DOCS', 1000),
        'sleep_segundos' => (int) env('NFEGOV_ADN_SLEEP', 1),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Certificado digital e-CNPJ
    |--------------------------------------------------------------------------
    */

    'certificado' => [
        // Marcos (em dias) em que o admin do tenant é alertado por e-mail.
        'alert_days' => array_map('intval', explode(',', (string) env('NFEGOV_CERT_ALERT_DAYS', '60,30,15,7,1'))),

        // Validade da janela de MFA recente (step-up) para rotação de certificado.
        'step_up_minutes' => (int) env('NFEGOV_CERT_STEP_UP_MINUTES', 15),

        // Tamanho máximo do arquivo PFX/P12 em KB (padrão 5 MB).
        'max_kb' => (int) env('NFEGOV_CERT_MAX_KB', 5120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Armazenamento dos XMLs fiscais
    |--------------------------------------------------------------------------
    |
    | Os XMLs são documentos de guarda legal: nunca são descartados, nunca
    | são sobrescritos e ficam fora do banco (apenas o caminho é indexado).
    |
    */

    'storage' => [
        // Disco (config/filesystems.php) onde os XMLs serão gravados.
        'disk' => env('NFEGOV_XML_DISK', 'local'),

        // Raiz, dentro do disco, onde os XMLs ficam.
        'xml_root' => env('NFEGOV_XML_ROOT', 'tenants'),

        // Disco e raiz onde os PFX (cifrados) dos certificados ficam.
        'cert_disk' => env('NFEGOV_CERT_DISK', 'local'),
        'cert_root' => env('NFEGOV_CERT_ROOT', 'certificados'),
    ],

    'mfa' => [
        // Papéis que são OBRIGADOS a configurar/confirmar o segundo fator.
        'papeis_obrigatorios' => ['super-admin', 'admin-tenant'],

        // Janela (em minutos) de "MFA recente" exigida no step-up de
        // operações sensíveis (ex.: rotação de certificado).
        'step_up_minutos' => (int) env('NFEGOV_MFA_STEP_UP_MINUTOS', 15),
    ],

];
