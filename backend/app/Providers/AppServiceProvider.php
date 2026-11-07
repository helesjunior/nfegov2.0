<?php

namespace App\Providers;

use App\Models\Certificate;
use App\Models\Nfe;
use App\Models\Nfse;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use App\Domain\Nfse\AdnClient;
use App\Domain\Nfse\HttpAdnClient;
use App\Domain\Nfse\HttpSefinClient;
use App\Domain\Nfse\SefinClient;
use App\Domain\Sefaz\SefazClient;
use App\Domain\Sefaz\SpedSefazClient;
use App\Policies\CertificatePolicy;
use App\Policies\NfePolicy;
use App\Policies\NfsePolicy;
use App\Policies\SyncRunPolicy;
use App\Policies\TenantPolicy;
use App\Policies\UnidadePolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

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
        Nfse::class => NfsePolicy::class,
        Unidade::class => UnidadePolicy::class,
        Certificate::class => CertificatePolicy::class,
        SyncRun::class => SyncRunPolicy::class,
        Tenant::class => TenantPolicy::class,
        User::class => UserPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Comunicação com a SEFAZ: implementação real por padrão; testes
        // substituem o binding por um mock (`SefazClient`).
        $this->app->bind(SefazClient::class, SpedSefazClient::class);

        // NFS-e: API de contribuintes do ADN (mTLS). Testes usam um fake.
        $this->app->bind(AdnClient::class, HttpAdnClient::class);

        // SEFIN Nacional: registro da manifestação do tomador (mTLS).
        $this->app->bind(SefinClient::class, HttpSefinClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // `{usuario}` do CRUD de usuários resolve SEM o global scope de tenant:
        // o escopo de acesso é N:N e é validado na UserPolicy (que permite ao
        // super-admin alcançar qualquer usuário e barra o admin-tenant alheio).
        // Id não numérico nunca chega ao banco (evita 500 do pgsql / 22P02):
        // devolve 404, preservando a autorização (não vaza existência).
        Route::bind('usuario', function (string $valor) {
            abort_unless(ctype_digit($valor), 404);

            return User::withoutGlobalScopes()->findOrFail($valor);
        });
    }
}
