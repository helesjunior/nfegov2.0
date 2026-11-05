<?php

namespace Tests;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // Limpa o tenant ativo para evitar vazamento de contexto estático entre testes.
        TenantContext::clear();

        parent::tearDown();
    }
}
