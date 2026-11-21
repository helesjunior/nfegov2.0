<?php

namespace App\Support;

/**
 * Estado do login com Google Workspace.
 *
 * O recurso só fica DISPONÍVEL quando as três condições valem ao mesmo tempo:
 * a chave está ligada, há credenciais OAuth e há ao menos um domínio permitido.
 * Qualquer uma faltando = desligado (as rotas respondem 404 e o botão some).
 */
final class GoogleLogin
{
    public static function habilitado(): bool
    {
        return (bool) config('nfegov.google_login.habilitado', false);
    }

    /** @return list<string> */
    public static function dominios(): array
    {
        return array_values((array) config('nfegov.google_login.dominios', []));
    }

    public static function configurado(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && self::dominios() !== [];
    }

    public static function disponivel(): bool
    {
        return self::habilitado() && self::configurado();
    }

    /**
     * Modo exclusivo ligado E em condições de valer: precisa do login Google no ar
     * e do autocadastro 'pendente' (senão ninguém conseguiria ser cadastrado).
     */
    public static function exclusivo(): bool
    {
        return (bool) config('nfegov.google_login.exclusivo', false)
            && self::disponivel()
            && self::autocadastroPendente();
    }

    /** O e-mail é de um dos domínios do Workspace permitidos? */
    public static function emailNoDominio(string $email): bool
    {
        $dominio = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return $dominio !== '' && in_array($dominio, self::dominios(), true);
    }

    /**
     * No modo exclusivo, quem tem e-mail do domínio entra só pelo Google.
     * O super-admin é a exceção (acesso de emergência por senha + MFA).
     */
    public static function senhaBloqueada(\App\Models\User $user): bool
    {
        return self::exclusivo()
            && ! $user->hasRole('super-admin')
            && self::emailNoDominio((string) $user->email);
    }

    /** O 1º login de alguém do domínio cria um cadastro inativo (pendente de aprovação)? */
    public static function autocadastroPendente(): bool
    {
        return config('nfegov.google_login.autocadastro', 'desligado') === 'pendente';
    }
}
