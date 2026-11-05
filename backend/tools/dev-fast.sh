#!/usr/bin/env sh
#
# Liga/desliga os caches do Laravel para deixar o dev mais rápido.
#
# Contexto: no desenvolvimento no Windows, o bind mount do código torna o boot
# do Laravel lento. Além do `vendor/` num volume Linux (ver docker-compose.yml),
# cachear config/rotas derruba o restante do overhead de boot.
#
# ATENÇÃO: os caches de config/rotas NÃO podem coexistir com a suíte de testes
# (os testes sobrescrevem o ambiente via phpunit.xml). Por isso este script
# limpa os caches automaticamente quando você vai rodar os testes — ou rode
# `dev-fast.sh clear` antes de `php artisan test`.
#
# Uso (dentro do container `app`):
#   sh tools/dev-fast.sh on       # liga config+rotas+views
#   sh tools/dev-fast.sh off      # limpa (volta ao modo seguro p/ testes)
#
# Equivalente a: docker compose exec -T app sh tools/dev-fast.sh on|off

set -e

artisan() {
    php artisan "$@"
}

case "${1:-on}" in
    on|enable)
        artisan config:cache
        artisan route:cache
        artisan view:cache
        echo "Caches ligados. Lembre-se: rode 'php tools/dev-fast.sh off' antes dos testes."
        ;;
    off|clear|disable)
        artisan optimize:clear
        echo "Caches limpos (modo seguro para testes)."
        ;;
    *)
        echo "Uso: $0 {on|off}" >&2
        exit 2
        ;;
esac