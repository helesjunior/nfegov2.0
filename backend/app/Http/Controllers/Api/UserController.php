<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Concerns\Auditable;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD de Usuários. Vínculo N:N (tenant_user) é a fonte de verdade.
 */
class UserController extends Controller
{
    use Auditable;

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $query = User::withoutGlobalScopes()->with('tenants');

        // Respeita o seletor: contexto nulo (super-admin "Todas") ⇒ todos.
        $contexto = TenantContext::id();
        if ($contexto !== null) {
            $query->whereHas('tenants', fn ($q) => $q->where('tenants.id', $contexto));
        }

        return UserResource::collection($query->orderBy('name')->paginate(50));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $dados = $request->validated();
        $this->autorizaVinculos($request, $dados['tenants'], $dados['papel']);

        $user = DB::transaction(function () use ($dados) {
            $user = User::create([
                'name' => $dados['name'],
                'email' => $dados['email'],
                'password' => $dados['password'],
                'ativo' => true,
                'tenant_id' => $dados['tenants'][0] ?? null,
            ]);
            $user->syncRoles([$dados['papel']]);
            $user->tenants()->sync($dados['tenants']);

            return $user;
        });

        $this->audit('usuario.criado', ['email' => $user->email, 'papel' => $dados['papel']], 'usuario', $user->id, $request->user(), $request);

        return (new UserResource($user->load('tenants')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $usuario): UserResource
    {
        // `{usuario}` já chega resolvido sem o global scope de tenant (route
        // binding em AppServiceProvider); a autorização fica na policy.
        Gate::authorize('update', $usuario);

        $dados = $request->validated();

        // Anti-lockout/escalada: ninguém altera papel/ativos/vínculos da própria
        // conta por aqui (evita auto-promoção). 403 para deixar claro que é
        // proibido, não um erro de preenchimento.
        if ($request->user()->id === $usuario->id) {
            abort(403, 'Não é possível alterar a própria conta por aqui.');
        }

        $this->autorizaVinculos($request, $dados['tenants'], $dados['papel']);

        DB::transaction(function () use ($usuario, $dados) {
            $usuario->fill([
                'name' => $dados['name'],
                'email' => $dados['email'],
                'tenant_id' => $dados['tenants'][0] ?? null,
            ]);
            if (! empty($dados['password'])) {
                $usuario->password = $dados['password'];
            }
            $usuario->save();

            $usuario->syncRoles([$dados['papel']]);
            $usuario->tenants()->sync($dados['tenants']);
        });

        $this->audit('usuario.atualizado', ['email' => $usuario->email, 'papel' => $dados['papel']], 'usuario', $usuario->id, $request->user(), $request);

        return new UserResource($usuario->load('tenants'));
    }

    public function ativo(Request $request, User $usuario): UserResource
    {
        Gate::authorize('desativar', $usuario);

        $dados = $request->validate(['ativo' => ['required', 'boolean']]);
        $usuario->update(['ativo' => $dados['ativo']]);

        $this->audit('usuario.ativo.alterado', ['ativo' => $usuario->ativo], 'usuario', $usuario->id, $request->user(), $request);

        return new UserResource($usuario->load('tenants'));
    }

    /**
     * admin-tenant só vincula aos seus tenants e nunca cria/atribui super-admin.
     */
    private function autorizaVinculos(Request $request, array $tenants, string $papel): void
    {
        $auth = $request->user();

        if ($auth->hasRole('super-admin')) {
            return;
        }

        if ($papel === 'super-admin') {
            abort(403, 'Somente super-admin cria super-admin.');
        }

        $meus = $auth->tenants()->pluck('tenants.id')->map(fn ($i) => (int) $i)->all();
        foreach ($tenants as $t) {
            if (! in_array((int) $t, $meus, true)) {
                abort(403, 'Vínculo com entidade não permitida.');
            }
        }
    }
}