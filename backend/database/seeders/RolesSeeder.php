<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria papéis e permissões do sistema.
 *
 * Papéis:
 * - super-admin   : administra a plataforma (multi-tenant), tudo liberado.
 * - admin-tenant  : administra a própria entidade (unidades, certificados, usuários).
 * - operador      : opera o dia a dia (consulta/exporta NF-e, dispara sync).
 * - consulta      : somente leitura de NF-e.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissoes = [
            'nfe.view',
            'nfe.export',
            'certificado.gerenciar',
            'unidade.gerenciar',
            'usuario.gerenciar',
            'sync.executar',
            'auditoria.view',
        ];

        foreach ($permissoes as $nome) {
            Permission::findOrCreate($nome, 'web');
        }

        $superAdmin = Role::findOrCreate('super-admin', 'web');
        $adminTenant = Role::findOrCreate('admin-tenant', 'web');
        $operador = Role::findOrCreate('operador', 'web');
        $consulta = Role::findOrCreate('consulta', 'web');

        // super-admin: tudo.
        $superAdmin->syncPermissions($permissoes);

        // admin-tenant: gestão da própria entidade, sem auditoria global.
        $adminTenant->syncPermissions([
            'nfe.view',
            'nfe.export',
            'certificado.gerenciar',
            'unidade.gerenciar',
            'usuario.gerenciar',
            'sync.executar',
        ]);

        // operador: operação diária.
        $operador->syncPermissions([
            'nfe.view',
            'nfe.export',
            'sync.executar',
        ]);

        // consulta: somente leitura.
        $consulta->syncPermissions([
            'nfe.view',
        ]);
    }
}