<?php

namespace App\Providers;

use App\Models\Certificate;
use App\Models\Nfe;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Domain\Sefaz\SefazClient;
use App\Domain\Sefaz\SpedSefazClient;
use App\Policies\CertificatePolicy;
use App\Policies\NfePolicy;
use App\Policies\SyncRunPolicy;
use App\Policies\TenantPolicy;
use App\Policies\UnidadePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Mapa de modelos -> policies.
     *
     * Toda autorização de recurso passa por aqui; combinada ao global scope
     * por tenant (`BelongsToTenant`), garante isolamento entre entidades.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Nfe::class => NfePolicy::class,
        Unidade::class => UnidadePolicy::class,
        Certificate::class => CertificatePolicy::class,
        SyncRun::class => SyncRunPolicy::class,
        Tenant::class => TenantPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Comunicação com a SEFAZ: implementação real por padrão; testes
        // substituem o binding por um mock (`SefazClient`).
        $this->app->bind(SefazClient::class, SpedSefazClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
