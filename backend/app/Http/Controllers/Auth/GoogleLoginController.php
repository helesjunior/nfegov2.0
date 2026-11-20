<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Concerns\Auditable;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\GoogleLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Login com Google Workspace (opcional; ver `config/nfegov.php`).
 *
 * O Google só AUTENTICA quem é a pessoa. Quem pode entrar continua sendo
 * decidido pelo NFeGov: o usuário precisa já existir e estar ativo (não há
 * criação automática de conta) e o e-mail precisa ser de um domínio permitido.
 *
 * Depois do login, a sessão segue o mesmo caminho do login por senha: o
 * segundo fator (TOTP) do NFeGov continua sendo exigido pelos mesmos papéis —
 * a flag de MFA da sessão é zerada aqui, e a SPA manda o usuário para `/mfa`.
 *
 * Os motivos detalhados de uma negativa ficam só na auditoria; para a tela
 * vai um código genérico, que não revela se o e-mail existe no sistema.
 */
class GoogleLoginController extends Controller
{
    use Auditable;

    public function redirect(): RedirectResponse
    {
        abort_unless(GoogleLogin::disponivel(), 404);

        $parametros = ['prompt' => 'select_account'];

        // Com um único domínio, o Google já filtra o seletor de contas.
        if (count(GoogleLogin::dominios()) === 1) {
            $parametros['hd'] = GoogleLogin::dominios()[0];
        }

        return Socialite::driver('google')->with($parametros)->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(GoogleLogin::disponivel(), 404);

        if ($request->filled('error')) {
            return $this->negar($request, 'google_cancelado', 'cancelado_no_google');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // `state` inválido/expirado, código reutilizado, falha de rede etc.
            Log::warning('Login Google: falha ao trocar o código.', ['erro' => class_basename($e)]);

            return $this->negar($request, 'google_falhou', 'falha_na_troca_do_codigo');
        }

        $bruto = (array) ($google->user ?? []);
        $sub = (string) $google->getId();
        $email = strtolower(trim((string) $google->getEmail()));
        // O domínio vem do que o Google DEVOLVEU (claim `hd`), nunca do que foi pedido.
        $dominio = strtolower((string) ($bruto['hd'] ?? ''));
        $emailVerificado = filter_var($bruto['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (
            $sub === '' || $email === '' || ! $emailVerificado || $dominio === ''
            || ! in_array($dominio, GoogleLogin::dominios(), true)
            || ! str_ends_with($email, '@' . $dominio)
        ) {
            return $this->negar($request, 'google_nao_autorizado', 'dominio_ou_email_invalido', $email);
        }

        $user = User::withoutGlobalScopes()->where('google_id', $sub)->first()
            ?? User::withoutGlobalScopes()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            return $this->negar($request, 'google_nao_autorizado', 'usuario_nao_cadastrado', $email);
        }

        // E-mail já amarrado a OUTRA conta Google: não deixa assumir.
        if ($user->google_id !== null && ! hash_equals((string) $user->google_id, $sub)) {
            return $this->negar($request, 'google_nao_autorizado', 'conta_google_divergente', $email, $user);
        }

        if (! (bool) $user->ativo) {
            return $this->negar($request, 'google_nao_autorizado', 'usuario_inativo', $email, $user);
        }

        if ($user->google_id === null) {
            $user->forceFill(['google_id' => $sub])->save();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // Login novo: o segundo fator ainda não foi informado nesta sessão.
        $request->session()->forget(TwoFactorService::SESSAO_VERIFICADO);

        $this->audit('login.google', ['dominio' => $dominio], 'usuario', $user->id, $user, $request);

        return redirect('/');
    }

    /**
     * Volta para a tela de login com um código genérico e registra o motivo real.
     */
    private function negar(
        Request $request,
        string $codigo,
        string $motivo,
        ?string $email = null,
        ?User $user = null,
    ): RedirectResponse {
        $this->audit(
            'login.google.negado',
            array_filter(['motivo' => $motivo, 'email' => $email]),
            $user ? 'usuario' : null,
            $user?->id,
            $user,
            $request,
        );

        return redirect('/login?erro=' . $codigo);
    }
}
