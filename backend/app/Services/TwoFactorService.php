<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use PragmaRX\Google2FA\Google2FA;

/**
 * Serviço de MFA (TOTP, RFC 6238).
 *
 * Centraliza geração de segredo, verificação de código e a "janela recente"
 * de verificação (`mfa_recent_at`) usada pelo step-up.
 *
 * Observação de segurança: o segredo nunca é devolvido por rotas depois de
 * confirmado; o `User` também o mantém em `$hidden`.
 */
class TwoFactorService
{
    public function __construct(
        private readonly Google2FA $google2fa = new Google2FA(),
    ) {
    }

    /**
     * Gera um segredo TOTP novo (base32).
     */
    public function gerarSegredo(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Código TOTP válido para o segredo AGORA. Usado em testes/verificação.
     */
    public function codigoAtual(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    /**
     * Verifica um código contra o segredo do usuário.
     *
     * Aceita uma pequena janela de tolerância (clock skew) do google2fa.
     */
    public function verify(User $user, string $codigo): bool
    {
        if (blank($user->two_factor_secret)) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey(
            $user->two_factor_secret,
            preg_replace('/\D/', '', $codigo),
        );
    }

    /**
     * Verifica um código contra um segredo avulso (antes de persistir).
     */
    public function verifySecret(string $secret, string $codigo): bool
    {
        return (bool) $this->google2fa->verifyKey(
            $secret,
            preg_replace('/\D/', '', $codigo),
        );
    }

    /**
     * URL otpauth:// para o app autenticador (Google Authenticator, etc.).
     */
    public function qrUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name', 'NFeGov'),
            $user->email,
            $secret,
        );
    }

    /**
     * Marca (ou lê) a última verificação bem-sucedida do usuário.
     */
    public function marcarRecente(User $user, ?Carbon $quando = null): void
    {
        $user->forceFill(['mfa_recent_at' => $quando ?? now()])->save();
    }

    /**
     * Momento da última verificação recente (ou null).
     */
    public function recentAt(User $user): ?Carbon
    {
        return $user->mfa_recent_at;
    }

    /**
     * A verificação é recente o suficiente para o step-up?
     */
    public function recente(User $user, int $minutos): bool
    {
        return $user->mfa_recent_at !== null
            && $user->mfa_recent_at->greaterThanOrEqualTo(now()->subMinutes($minutos));
    }

    /**
     * O usuário tem um MFA confirmado e utilizável?
     */
    /**
     * Chave de sessão que registra que o segundo fator foi informado nesta
     * sessão de login (guarda o id do usuário verificado).
     */
    public const SESSAO_VERIFICADO = 'mfa_verificado_usuario';

    /**
     * Marca a sessão atual como verificada pelo segundo fator.
     */
    public function marcarVerificadoNaSessao(User $user): void
    {
        if (request()->hasSession()) {
            request()->session()->put(self::SESSAO_VERIFICADO, $user->id);
        }
    }

    /**
     * O segundo fator já foi informado nesta sessão de login?
     */
    public function verificadoNaSessao(User $user): bool
    {
        return request()->hasSession()
            && (int) request()->session()->get(self::SESSAO_VERIFICADO) === (int) $user->id;
    }

    /**
     * O usuário tem MFA ativo e ainda precisa informar o código nesta sessão?
     */
    public function verificacaoPendente(User $user): bool
    {
        return $this->confirmado($user) && ! $this->verificadoNaSessao($user);
    }

    public function confirmado(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null
            && filled($user->two_factor_secret);
    }
}