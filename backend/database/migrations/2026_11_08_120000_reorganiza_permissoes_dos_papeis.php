<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Reorganiza as permissões dos papéis (novas: `nfe.manifestar`,
 * `unidade.view`, `certificado.renovar`; `consulta` passa a exportar).
 *
 * Roda o RolesSeeder (idempotente) para que bancos já existentes recebam as
 * novas regras com um simples `php artisan migrate`. O papel local
 * `admin-demo` (seed de demonstração), se existir, acompanha o admin-tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesSeeder())->run();

        $demo = Role::query()->where('name', 'admin-demo')->where('guard_name', 'web')->first();
        $demo?->syncPermissions(RolesSeeder::PAPEIS['admin-tenant']);
    }

    public function down(): void
    {
        // Sem volta automática: as permissões antigas são um subconjunto
        // das novas; para reverter, ajuste o RolesSeeder e rode-o de novo.
    }
};
