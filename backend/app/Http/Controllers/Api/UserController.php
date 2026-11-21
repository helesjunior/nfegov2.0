<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Concerns\Auditable;
use App\Models\User;
use App\Support\GoogleLogin;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
            // Os cadastros pendentes (Google) ainda não têm entidade: quem gerencia
            // usuários precisa vê-los para aprovar.
            $query->where(fn ($q) => $q
                ->whereHas('tenants', fn ($t) => $t->where('tenants.id', $contexto))
                ->orWhere('aprovacao_pendente', true));
        }

        return UserResource::collection($query->orderBy('name')->paginate(50));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        // Modo exclusivo do Google: usuário novo nasce pelo login Google (pendente)
        // e é aprovado na lista. Só o super-admin ainda cadastra à mão.
        if (GoogleLogin::exclusivo() && ! $request->user()->hasRole('super-admin')) {
            abort(403, 'O cadastro de usuários é feito pelo login com Google. Aprove quem aguarda na lista.');
        }

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

        // Modo exclusivo: o e-mail é a identidade do Google. Mudá-lo seria
        // reaproveitar a conta para outra pessoa (cadastro por fora do Google).
        if (
            GoogleLogin::exclusivo()
            && ! $request->user()->hasRole('super-admin')
            && (string) $dados['email'] !== (string) $usuario->email
        ) {
            abort(403, 'Com o login Google exclusivo, o e-mail do usuário não pode ser alterado.');
        }

        $this->autorizaVinculos($request, $dados['tenants'], $dados['papel']);

        $eraPendente = (bool) $usuario->aprovacao_pendente;

        // Aprovar um cadastro pendente é escolher a entidade: sem ela o usuário
        // continuaria sem acesso a nada, então a tela precisa avisar em vez de salvar "em vão".
        if ($eraPendente && $dados['tenants'] === []) {
            throw ValidationException::withMessages([
                'tenants' => 'Escolha ao menos uma entidade para aprovar este cadastro.',
            ]);
        }

        DB::transaction(function () use ($usuario, $dados, $eraPendente) {
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

            // Aprovação = vincular a uma entidade: o cadastro deixa de ser pendente e já fica ativo.
            if ($eraPendente) {
                $usuario->forceFill(['aprovacao_pendente' => false, 'ativo' => true])->save();
            }
        });

        if ($eraPendente) {
            $this->audit('usuario.pendente.vinculado', ['email' => $usuario->email, 'papel' => $dados['papel'], 'tenants' => $dados['tenants']], 'usuario', $usuario->id, $request->user(), $request);
        }

        $this->audit('usuario.atualizado', ['email' => $usuario->email, 'papel' => $dados['papel']], 'usuario', $usuario->id, $request->user(), $request);

        return new UserResource($usuario->load('tenants'));
    }

    public function ativo(Request $request, User $usuario): UserResource
    {
        Gate::authorize('desativar', $usuario);

        $dados = $request->validate(['ativo' => ['required', 'boolean']]);

        // Pendente sem entidade não se "ativa" no botão: a aprovação (que escolhe a entidade) já ativa.
        if ($usuario->aprovacao_pendente && $dados['ativo']) {
            throw ValidationException::withMessages([
                'ativo' => 'Este cadastro aguarda aprovação: edite-o, escolha a entidade e aprove.',
            ]);
        }

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