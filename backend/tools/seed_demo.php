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
use App\Services\TwoFactorService;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

(new RolesSeeder())->run();

/**
 * Papel de conveniência para inspeção local da interface.
 *
 * TEMPORÁRIO e apenas para desenvolvimento: replica as permissões do
 * `admin-tenant`, mas NÃO está em `nfegov.mfa.papeis_obrigatorios` — então o
 * middleware `RequireMfa` não bloqueia o acesso sem configurar o TOTP.
 *
 * NUNCA use este papel em produção. Para removê-lo:
 *   Role::findByName('admin-demo', 'web')->delete();
 */
$adminDemo = Role::findOrCreate('admin-demo', 'web');
$adminDemo->syncPermissions([
    'nfe.view',
    'nfe.export',
    'certificado.gerenciar',
    'unidade.gerenciar',
    'usuario.gerenciar',
    'sync.executar',
]);

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
// Vínculo N:N (fonte de verdade); `users.tenant_id` fica por compatibilidade.
$admin->tenants()->syncWithoutDetaching([$tenant->id]);

$operador = User::firstOrCreate(
    ['email' => 'operador@agu.gov.br'],
    ['name' => 'Operador AGU', 'password' => Hash::make('senha123'), 'tenant_id' => $tenant->id],
);
$operador->assignRole('operador');
$operador->tenants()->syncWithoutDetaching([$tenant->id]);

// Admin de demonstração SEM MFA (apenas para inspeção local da interface).
$adminSemMfa = User::firstOrCreate(
    ['email' => 'admin.semmfa@agu.gov.br'],
    ['name' => 'Admin Demo (sem MFA)', 'password' => Hash::make('senha123'), 'tenant_id' => $tenant->id],
);
$adminSemMfa->assignRole('admin-demo');
$adminSemMfa->tenants()->syncWithoutDetaching([$tenant->id]);

// Super-admin de demonstração: `tenant_id = null` e SEM vínculo — enxerga
// "Todas" as entidades pelo seletor. Exige MFA, então já fica confirmado.
$superAdmin = User::firstOrCreate(
    ['email' => 'superadmin@agu.gov.br'],
    ['name' => 'Super Admin (demo)', 'password' => Hash::make('senha123'), 'tenant_id' => null],
);
$superAdmin->assignRole('super-admin');

$twoFactor = app(TwoFactorService::class);
$segredoSuperAdmin = $superAdmin->two_factor_secret;

if (blank($segredoSuperAdmin)) {
    $segredoSuperAdmin = $twoFactor->gerarSegredo();
    $superAdmin->forceFill([
        'two_factor_secret' => $segredoSuperAdmin,
        'two_factor_confirmed_at' => now(),
    ])->save();
}

echo "Pronto!\n";
echo "  admin@agu.gov.br         / senha123  (admin-tenant — exige MFA)\n";
echo "  admin.semmfa@agu.gov.br  / senha123  (admin-demo — SEM MFA, só p/ testes)\n";
echo "  operador@agu.gov.br      / senha123  (operador — sem MFA)\n";
echo "  superadmin@agu.gov.br    / senha123  (super-admin — MFA confirmado, 'Todas')\n";
echo "    segredo TOTP (super-admin): {$segredoSuperAdmin}\n";
echo "  Tenant #{$tenant->id}, Unidade #{$unidade->id}\n";