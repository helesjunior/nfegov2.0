<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditLogger;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditoria_prefere_o_tenant_do_contexto_ao_do_usuario(): void
    {
        $tenantUsuario = Tenant::factory()->create();
        $tenantContexto = Tenant::factory()->create();
        $super = User::factory()->create(['tenant_id' => null]);

        TenantContext::set($tenantContexto->id);
        $log = app(AuditLogger::class)->log($super, 'teste.acao');
        TenantContext::clear();

        $this->assertSame($tenantContexto->id, (int) $log->tenant_id);
    }

    public function test_auditoria_com_tenant_explicito_vence_tudo(): void
    {
        $explicito = Tenant::factory()->create();
        $super = User::factory()->create(['tenant_id' => null]);

        $log = app(AuditLogger::class)->log($super, 'teste.acao', tenantId: $explicito->id);

        $this->assertSame($explicito->id, (int) $log->tenant_id);
    }

    public function test_auditoria_contexto_vence_tenant_nao_nulo_do_usuario(): void
    {
        $tenantDoUsuario = Tenant::factory()->create();
        $tenantDoContexto = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenantDoUsuario)->create();

        TenantContext::set($tenantDoContexto->id);
        $log = app(AuditLogger::class)->log($u, 'teste.acao');
        TenantContext::clear();

        $this->assertSame($tenantDoContexto->id, (int) $log->tenant_id);
    }

    public function test_auditoria_sem_contexto_cai_no_tenant_do_usuario(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create();

        $log = app(AuditLogger::class)->log($u, 'teste.acao');

        $this->assertSame($tenant->id, (int) $log->tenant_id);
    }
}