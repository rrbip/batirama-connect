# ===========================================
# AI-Manager CMS - PHP Application
# ===========================================
# Dockerfile optimisé avec cache de couches
# - Les dépendances système/PHP sont en haut (changent rarement)
# - Composer est installé séparément (cache si composer.lock ne change pas)
# - Le code applicatif est copié en dernier (change souvent)
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

# ===========================================
# COUCHE 1: Dépendances système (change rarement)
# ===========================================
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
    # ImageMagick pour manipulation d'images (Vision extraction)
    imagemagick \
    imagemagick-libs \
    # IMAP pour réception des emails de support
    imap-dev \
    openssl-dev \
    $PHPIZE_DEPS

# ===========================================
# COUCHE 2: Extensions PHP (change rarement)
# ===========================================
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

# Installation de l'extension IMAP
# Note: Sur Alpine, imap-dev inclut déjà le support SSL
RUN docker-php-ext-install imap

# Installation de Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Installation de Xdebug (dev uniquement)
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
        pecl install xdebug \
        && docker-php-ext-enable xdebug; \
    fi

# ===========================================
# COUCHE 3: Composer (change rarement)
# ===========================================
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuration PHP personnalisée
COPY docker/app/php.ini $PHP_INI_DIR/php.ini

# Création du répertoire de travail
WORKDIR /var/www/html

# ===========================================
# COUCHE 4: Dépendances Composer (cache si composer.lock ne change pas)
# ===========================================
# Copier UNIQUEMENT les fichiers de dépendances en premier
COPY composer.json composer.lock ./

# Créer les dossiers Laravel nécessaires AVANT composer install
RUN mkdir -p bootstrap/cache \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/framework/cache \
    && mkdir -p storage/logs \
    && mkdir -p resources/views \
    && mkdir -p database/factories \
    && mkdir -p database/seeders

# Installer les dépendances Composer (cette couche est mise en cache
# tant que composer.json et composer.lock ne changent pas)
RUN if [ "$APP_ENV" = "production" ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction --no-scripts; \
    else \
        composer install --optimize-autoloader --no-interaction --no-scripts; \
    fi

# ===========================================
# COUCHE 5: Code applicatif (change souvent)
# ===========================================
# Copier le reste du code source
COPY --chown=www-data:www-data . .

# Exécuter les scripts post-install de Composer (autoload, etc.)
RUN composer dump-autoload --optimize

# Copie du script d'entrypoint
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Permissions finales
RUN chown -R www-data:www-data storage bootstrap/cache resources \
    && chmod -R 775 storage bootstrap/cache

# Healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php-fpm -t || exit 1

EXPOSE 9000

# Entrypoint pour initialisation automatique
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
