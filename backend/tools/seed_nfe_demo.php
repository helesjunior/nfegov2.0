<?php

/**
 * Insere uma NF-e de demonstração (sem XML) para inspeção das telas.
 * Uso: docker compose exec -T app php tools/seed_nfe_demo.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Nfe;
use App\Models\NfeItem;
use App\Models\Unidade;

$unidade = Unidade::first();

if (! $unidade) {
    echo "Nenhuma unidade encontrada. Rode tools/seed_demo.php primeiro.\n";
    exit(1);
}

$chave = '35200111222333000181550010000010001000010019';

$nfe = Nfe::updateOrCreate(
    ['tenant_id' => $unidade->tenant_id, 'chave' => $chave],
    [
        'unidade_id' => $unidade->id,
        'numero' => '100',
        'serie' => '1',
        'modelo' => '55',
        'emitente_cnpj' => '11222333000181',
        'emitente_nome' => 'FORNECEDOR EXEMPLO LTDA',
        'dest_cnpj' => '00000000000191',
        'valor_total' => 1500.00,
        'natureza_operacao' => 'Venda de mercadoria',
        'data_emissao' => now()->subDays(3),
        'cstat' => '100',
        'estado' => 'completo',
        'emitente_proprio' => false,
    ],
);

$nfe->itens()->delete();

foreach ([
    ['Caneta esferográfica azul', '5102', 'UN', 100, 1.50],
    ['Papel A4 (resma)', '5102', 'UN', 10, 25.00],
    ['Cadeira giratória', '5102', 'UN', 5, 500.00],
] as $i => [$descricao, $cfop, $un, $qtd, $vlr]) {
    NfeItem::create([
        'tenant_id' => $unidade->tenant_id,
        'nfe_id' => $nfe->id,
        'sequencial' => $i + 1,
        'descricao' => $descricao,
        'cfop' => $cfop,
        'unidade_medida' => $un,
        'quantidade' => $qtd,
        'valor_unitario' => $vlr,
        'valor_total' => $qtd * $vlr,
    ]);
}

echo "NF-e de demonstração criada.\n";
echo "  Chave: {$chave}\n";
echo "  Emitente: FORNECEDOR EXEMPLO LTDA\n";
echo "  Valor: R$ 1.500,00\n";