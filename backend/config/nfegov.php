<?php

return [

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
        // Intervalo do agendamento, em horas (o Sistema roda a cada 3 horas).
        'interval_hours' => (int) env('NFEGOV_SYNC_INTERVAL_HOURS', 3),

        // Teto de iterações do laço distDFe no primeiro "catch-up" (NSU zerado).
        'loop_limit_catchup' => (int) env('NFEGOV_SYNC_LOOP_LIMIT_CATCHUP', 150),

        // Teto de iterações do laço distDFe nas consultas subsequentes.
        'loop_limit_normal' => (int) env('NFEGOV_SYNC_LOOP_LIMIT_NORMAL', 100),

        // Pausa (segundos) entre cada consulta distDFe (respiro para a SEFAZ).
        'sleep_seconds' => (int) env('NFEGOV_SYNC_SLEEP_SECONDS', 2),

        // Dias úteis apenas (o fisco não atualiza fim de semana).
        'weekdays_only' => (bool) env('NFEGOV_SYNC_WEEKDAYS_ONLY', true),
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

        // Ao receber cStat 656, a fila de sync pausa por este tempo (minutos).
        'circuit_breaker_minutes' => (int) env('NFEGOV_SEFAZ_CIRCUIT_BREAKER_MINUTES', 60),

        // Janela (minutos) em que as unidades são escalonadas para não criar picos.
        'slot_window_minutes' => (int) env('NFEGOV_SEFAZ_SLOT_WINDOW_MINUTES', 180),

        // Nome da fila dedicada à comunicação com a SEFAZ.
        'queue' => env('NFEGOV_SEFAZ_QUEUE', 'sefaz'),

        // Concorrência máxima de workers na fila da SEFAZ (mesmo IP).
        'workers' => (int) env('NFEGOV_SEFAZ_WORKERS', 2),
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