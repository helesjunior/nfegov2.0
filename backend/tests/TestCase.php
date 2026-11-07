<?php

namespace Tests;

use App\Services\TwoFactorService;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Autentica como um login COMPLETO (senha + segundo fator).
     *
     * A sessão já sai marcada como verificada pelo MFA, como acontece depois
     * de `/api/mfa/verify`. Para testar a sessão SEM o segundo fator, use
     * `actingAsSemMfa()`.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->withSession([TwoFactorService::SESSAO_VERIFICADO => $user->getAuthIdentifier()]);

        return parent::actingAs($user, $guard);
    }

    /**
     * Autentica apenas com a senha: o MFA ainda não foi informado na sessão.
     */
    public function actingAsSemMfa(Authenticatable $user, $guard = null): static
    {
        return parent::actingAs($user, $guard);
    }

    protected function tearDown(): void
    {
        // Limpa o tenant ativo para evitar vazamento de contexto estático entre testes.
        TenantContext::clear();

        parent::tearDown();
    }
}
