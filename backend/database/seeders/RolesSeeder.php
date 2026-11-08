<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria papéis e permissões do sistema (idempotente: pode rodar de novo).
 *
 * Papéis:
 * - super-admin  : tudo, em todas as entidades (inclui o CRUD de Entidades).
 * - admin-tenant : tudo nas entidades a que está vinculado, menos Entidades
 *                  (unidades, certificados, usuários, NF-e e NFS-e).
 * - operador     : tudo de NF-e/NFS-e e Sincronizações; vê Unidades, mas não
 *                  inclui/edita/desativa; só troca o certificado VENCIDO.
 *                  Não acessa Usuários nem Entidades.
 * - consulta     : tudo de NF-e/NFS-e (XML, PDF, exportações, busca), exceto
 *                  registrar/rejeitar a manifestação do tomador da NFS-e.
 *                  Não acessa Unidades, Usuários nem Entidades.
 *
 * Permissões:
 * - nfe.view              : consultar NF-e/NFS-e, painel e sincronizações.
 * - nfe.export            : baixar XML, gerar DANFE/DANFSe e exportar.
 * - nfe.manifestar        : registrar/rejeitar a manifestação do tomador (NFS-e).
 * - sync.executar         : buscar nota manualmente (por chave/NSU).
 * - unidade.view          : acessar a tela de Unidades e ver o certificado.
 * - unidade.gerenciar     : incluir, editar e ativar/desativar unidades.
 * - certificado.gerenciar : enviar/trocar o certificado a qualquer momento.
 * - certificado.renovar   : trocar o certificado só quando ele está vencido.
 * - usuario.gerenciar     : CRUD de usuários das entidades vinculadas.
 * - auditoria.view        : auditoria global (plataforma).
 */
class RolesSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSOES = [
        'nfe.view',
        'nfe.export',
        'nfe.manifestar',
        'sync.executar',
        'unidade.view',
        'unidade.gerenciar',
        'certificado.gerenciar',
        'certificado.renovar',
        'usuario.gerenciar',
        'auditoria.view',
    ];

    /** @var array<string, list<string>> */
    public const PAPEIS = [
        'admin-tenant' => [
            'nfe.view',
            'nfe.export',
            'nfe.manifestar',
            'sync.executar',
            'unidade.view',
            'unidade.gerenciar',
            'certificado.gerenciar',
            'certificado.renovar',
            'usuario.gerenciar',
        ],
        'operador' => [
            'nfe.view',
            'nfe.export',
            'nfe.manifestar',
            'sync.executar',
            'unidade.view',
            'certificado.renovar',
        ],
        'consulta' => [
            'nfe.view',
            'nfe.export',
            'sync.executar',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSOES as $nome) {
            Permission::findOrCreate($nome, 'web');
        }

        // super-admin: tudo.
        Role::findOrCreate('super-admin', 'web')->syncPermissions(self::PERMISSOES);

        foreach (self::PAPEIS as $papel => $permissoes) {
            Role::findOrCreate($papel, 'web')->syncPermissions($permissoes);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
