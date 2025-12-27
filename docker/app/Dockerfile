# ===========================================
# AI-Manager CMS - PHP Application
# ===========================================
ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm-alpine

# Arguments de build
ARG APP_ENV=production
ARG INSTALL_XDEBUG=false

# Labels
LABEL maintainer="Batirama Connect"
LABEL description="AI-Manager CMS Application"

# Variables d'environnement
ENV APP_ENV=${APP_ENV}
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/composer

# Installation des dépendances système
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    postgresql-dev \
    linux-headers \
    bash \
    poppler-utils \
    # OCR Tesseract pour extraction texte d'images
    tesseract-ocr \
    tesseract-ocr-data-fra \
    tesseract-ocr-data-eng \
    $PHPIZE_DEPS

# Configuration et installation des extensions PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        intl \
        bcmath \
        opcache \
        pcntl

# Installation de Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Installation de Xdebug (dev uniquement)
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
        pecl install xdebug \
        && docker-php-ext-enable xdebug; \
    fi

# Installation de Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuration PHP personnalisée (remplace la config par défaut)
COPY docker/app/php.ini $PHP_INI_DIR/php.ini

# Création du répertoire de travail
WORKDIR /var/www/html

# Copie des fichiers de l'application
COPY --chown=www-data:www-data . .

# Création des dossiers Laravel nécessaires AVANT composer install
# Ces dossiers sont requis par Laravel pour le cache et les sessions.
# Ils doivent exister avant l'exécution de composer install car
# certains scripts post-install de Composer peuvent en avoir besoin.
# Le dossier resources/views est également créé car il est requis
# pour les commandes artisan view:cache en production.
RUN mkdir -p bootstrap/cache \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/framework/cache \
    && mkdir -p storage/logs \
    && mkdir -p resources/views \
    && chown -R www-data:www-data bootstrap storage resources \
    && chmod -R 775 bootstrap storage

# Copie du script d'entrypoint
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Installation des dépendances Composer (production)
RUN if [ "$APP_ENV" = "production" ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction; \
    else \
        composer install --optimize-autoloader --no-interaction; \
    fi

# Note: Les optimisations Laravel (config:cache, route:cache, view:cache)
# sont exécutées par l'entrypoint au démarrage du conteneur

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php-fpm -t || exit 1

EXPOSE 9000

# Entrypoint pour initialisation automatique
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
