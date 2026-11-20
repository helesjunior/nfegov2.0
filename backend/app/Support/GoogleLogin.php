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
}
