# Entorno de desarrollo para llm-vcr.
# Todo gratuito: imagen oficial de PHP + Composer oficial.
FROM php:8.4-cli-alpine

# git y unzip los necesita Composer para instalar desde dist.
# libxml2-dev es la cabecera que requiere la extensión dom.
RUN apk add --no-cache git unzip libxml2-dev oniguruma-dev \
    && docker-php-ext-install mbstring dom xml \
    && rm -rf /var/cache/apk/*

# Composer desde su imagen oficial: más fiable y reproducible que curl.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Capa de dependencias cacheada: solo se reinstala si cambia composer.json.
# COMPOSER_ROOT_VERSION evita el aviso al no haber tags de git dentro de la imagen.
ENV COMPOSER_ROOT_VERSION=0.1.0
COPY composer.json ./
RUN composer install --no-interaction --no-scripts --prefer-dist

# El código va después para no invalidar la capa anterior en cada cambio.
COPY . .

RUN composer dump-autoload --optimize

CMD ["php", "-a"]
