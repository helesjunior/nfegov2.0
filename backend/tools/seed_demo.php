<?php

/**
 * Cria dados de demonstração para inspeção manual da interface.
 * Uso: docker compose exec -T app php tools/seed_demo.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Hash;

(new RolesSeeder())->run();

$tenant = Tenant::firstOrCreate(
    ['cnpj' => '00000000000191'],
    ['nome' => 'Advocacia-Geral da União', 'ativo' => true],
);

$unidade = Unidade::firstOrCreate(
    ['tenant_id' => $tenant->id, 'cnpj' => '11222333000181'],
    ['nome' => 'AGU - Unidade Central', 'nome_resumido' => 'AGU Central', 'uf' => 'DF', 'tp_amb' => 2, 'ativo' => true],
);

$admin = User::firstOrCreate(
    ['email' => 'admin@agu.gov.br'],
    ['name' => 'Administrador AGU', 'password' => Hash::make('senha123'), 'tenant_id' => $tenant->id],
);
$admin->assignRole('admin-tenant');

$operador = User::firstOrCreate(
    ['email' => 'operador@agu.gov.br'],
    ['name' => 'Operador AGU', 'password' => Hash::make('senha123'), 'tenant_id' => $tenant->id],
);
$operador->assignRole('operador');

echo "Pronto!\n";
echo "  admin@agu.gov.br    / senha123  (admin-tenant — exige MFA)\n";
echo "  operador@agu.gov.br / senha123  (operador — sem MFA)\n";
echo "  Tenant #{$tenant->id}, Unidade #{$unidade->id}\n";