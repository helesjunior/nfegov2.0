# Imagem base PHP 8.4 FPM (Laravel 13 exige PHP >= 8.4)
FROM php:8.4-fpm

# Dependências de sistema exigidas pelo Laravel e pela nfephp-org/sped-nfe
# (certificado via openssl, SOAP da SEFAZ, manipulação de XML/ZIP e conexão PostgreSQL)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        zip \
        libpq-dev \
        libzip-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        zip \
        soap \
        gd \
        mbstring \
        intl \
        bcmath \
        exif \
        pcntl \
        opcache \
    && rm -rf /var/lib/apt/lists/*

# Composer (versão 2)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Provider `legacy` do OpenSSL 3 — OBRIGATÓRIO para ler certificados A1 da
# ICP-Brasil (PKCS#12 com RC2-40/3DES). Sem ele, `openssl_pkcs12_read` falha
# com "digital envelope routines::unsupported", que o app reporta (de forma
# enganosa) como "senha incorreta ou arquivo inválido".
COPY docker/openssl.cnf /usr/local/etc/openssl/openssl.cnf
ENV OPENSSL_CONF=/usr/local/etc/openssl/openssl.cnf

# Extensão Redis (phpredis) para fila/cache/sessão via Redis
RUN pecl install redis && docker-php-ext-enable redis

WORKDIR /var/www/html

# Usuário não-root (UID/GID alinhados com o host para evitar problemas de permissão)
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app 2>/dev/null || true \
    && useradd -u ${UID} -g ${GID} -m app 2>/dev/null || true

USER app

EXPOSE 9000
CMD ["php-fpm"]
