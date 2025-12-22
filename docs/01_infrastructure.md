# Infrastructure & Orchestration Docker

> **Référence** : [00_index.md](./00_index.md)
> **Statut** : Spécifications validées

---

## Vue d'Ensemble

L'infrastructure est conteneurisée via Docker Compose avec des configurations distinctes pour le développement et la production.

### Services

| Service | Image | Port Interne | Port Exposé | Description |
|---------|-------|--------------|-------------|-------------|
| `app` | PHP 8.4-FPM | 9000 | - | Application Laravel |
| `web` | Caddy 2.10 | 80, 443 | 80, 443 | Reverse proxy HTTPS |
| `db` | PostgreSQL 17 | 5432 | 5432 (dev) | Base de données |
| `redis` | Redis 7.4 | 6379 | - | Cache & queues (optionnel) |
| `qdrant` | Qdrant 1.16 | 6333, 6334 | 6333 (dev) | Base vectorielle |
| `ollama` | Ollama 0.13 | 11434 | 11434 (dev) | Serveur IA |

---

## Configuration Docker Compose

### Fichier Principal : `docker-compose.yml`

```yaml
version: '3.8'

name: ai-manager-cms

services:
  # ===========================================
  # APPLICATION PHP-FPM
  # ===========================================
  app:
    build:
      context: .
      dockerfile: docker/app/Dockerfile
      args:
        PHP_VERSION: "8.4"
    container_name: aim_app
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - .:/var/www/html
      - ./docker/app/php.ini:/usr/local/etc/php/conf.d/custom.ini:ro
    environment:
      - APP_ENV=${APP_ENV:-local}
      - REDIS_ENABLED=${REDIS_ENABLED:-false}
    depends_on:
      db:
        condition: service_healthy
      qdrant:
        condition: service_healthy
    networks:
      - ai_network
    healthcheck:
      test: ["CMD", "php-fpm", "-t"]
      interval: 30s
      timeout: 10s
      retries: 3

  # ===========================================
  # SERVEUR WEB CADDY
  # ===========================================
  web:
    image: caddy:2.10-alpine
    container_name: aim_web
    restart: unless-stopped
    ports:
      - "${WEB_PORT:-80}:80"
      - "${WEB_SSL_PORT:-443}:443"
    volumes:
      - ./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - .:/var/www/html:ro
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      - app
    networks:
      - ai_network

  # ===========================================
  # BASE DE DONNÉES POSTGRESQL
  # ===========================================
  db:
    image: postgres:17-alpine
    container_name: aim_db
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${DB_DATABASE:-ai_manager}
      POSTGRES_USER: ${DB_USERNAME:-postgres}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-secret}
      PGDATA: /var/lib/postgresql/data/pgdata
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./docker/db/init.sql:/docker-entrypoint-initdb.d/init.sql:ro
    ports:
      - "${DB_EXTERNAL_PORT:-5432}:5432"
    networks:
      - ai_network
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-postgres} -d ${DB_DATABASE:-ai_manager}"]
      interval: 10s
      timeout: 5s
      retries: 5

  # ===========================================
  # REDIS (OPTIONNEL)
  # ===========================================
  redis:
    image: redis:7.4-alpine
    container_name: aim_redis
    restart: unless-stopped
    command: redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru
    volumes:
      - redis_data:/data
    networks:
      - ai_network
    profiles:
      - with-redis
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 3

  # ===========================================
  # QDRANT - BASE VECTORIELLE
  # ===========================================
  qdrant:
    image: qdrant/qdrant:v1.16.2
    container_name: aim_qdrant
    restart: unless-stopped
    environment:
      QDRANT__SERVICE__GRPC_PORT: 6334
      QDRANT__SERVICE__HTTP_PORT: 6333
      QDRANT__STORAGE__STORAGE_PATH: /qdrant/storage
      QDRANT__LOG_LEVEL: INFO
    volumes:
      - qdrant_data:/qdrant/storage
    ports:
      - "${QDRANT_EXTERNAL_PORT:-6333}:6333"
    networks:
      - ai_network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:6333/readyz"]
      interval: 10s
      timeout: 5s
      retries: 5

  # ===========================================
  # OLLAMA - SERVEUR IA
  # ===========================================
  ollama:
    image: ollama/ollama:${OLLAMA_VERSION:-0.13.5}
    container_name: aim_ollama
    restart: unless-stopped
    environment:
      - OLLAMA_HOST=0.0.0.0
      - OLLAMA_KEEP_ALIVE=${OLLAMA_KEEP_ALIVE:-5m}
      - OLLAMA_NUM_PARALLEL=${OLLAMA_NUM_PARALLEL:-2}
    volumes:
      - ollama_data:/root/.ollama
    ports:
      - "${OLLAMA_EXTERNAL_PORT:-11434}:11434"
    networks:
      - ai_network
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
    # Fallback sans GPU (commenté pour environnements sans GPU)
    # deploy: {}

  # ===========================================
  # SCHEDULER (CRON LARAVEL)
  # ===========================================
  scheduler:
    build:
      context: .
      dockerfile: docker/app/Dockerfile
    container_name: aim_scheduler
    restart: unless-stopped
    working_dir: /var/www/html
    command: ["php", "artisan", "schedule:work"]
    volumes:
      - .:/var/www/html
    depends_on:
      - app
      - db
    networks:
      - ai_network

  # ===========================================
  # QUEUE WORKER
  # ===========================================
  queue:
    build:
      context: .
      dockerfile: docker/app/Dockerfile
    container_name: aim_queue
    restart: unless-stopped
    working_dir: /var/www/html
    command: ["php", "artisan", "queue:work", "--sleep=3", "--tries=3", "--max-time=3600"]
    volumes:
      - .:/var/www/html
    depends_on:
      - app
      - db
    networks:
      - ai_network

# ===========================================
# VOLUMES PERSISTANTS
# ===========================================
volumes:
  postgres_data:
    driver: local
  redis_data:
    driver: local
  qdrant_data:
    driver: local
  ollama_data:
    driver: local
  caddy_data:
    driver: local
  caddy_config:
    driver: local

# ===========================================
# RÉSEAU
# ===========================================
networks:
  ai_network:
    driver: bridge
    name: ai_manager_network
```

---

## Configuration de Développement

### Fichier : `docker-compose.dev.yml`

```yaml
version: '3.8'

# Override pour le développement local
# Usage: docker compose -f docker-compose.yml -f docker-compose.dev.yml up

services:
  app:
    build:
      args:
        APP_ENV: local
        INSTALL_XDEBUG: "true"
    environment:
      - APP_DEBUG=true
      - XDEBUG_MODE=develop,debug
      - XDEBUG_CONFIG=client_host=host.docker.internal
    extra_hosts:
      - "host.docker.internal:host-gateway"

  web:
    ports:
      - "8080:80"
      - "8443:443"

  db:
    ports:
      - "5432:5432"

  qdrant:
    ports:
      - "6333:6333"
      - "6334:6334"

  ollama:
    # En dev, on peut utiliser un modèle plus léger
    environment:
      - OLLAMA_KEEP_ALIVE=24h  # Garde le modèle en mémoire
    ports:
      - "11434:11434"
    # Sans GPU en dev (CPU only)
    deploy: {}
```

---

## Configuration de Production

### Fichier : `docker-compose.prod.yml`

```yaml
version: '3.8'

# Override pour la production
# Usage: docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

services:
  app:
    build:
      args:
        APP_ENV: production
        INSTALL_XDEBUG: "false"
    environment:
      - APP_DEBUG=false
      - LOG_LEVEL=error
    restart: always
    deploy:
      replicas: 2
      resources:
        limits:
          cpus: '2'
          memory: 2G

  web:
    restart: always
    deploy:
      resources:
        limits:
          cpus: '0.5'
          memory: 256M

  db:
    ports: []  # Pas d'exposition externe en prod
    restart: always
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 4G
    environment:
      POSTGRES_INITDB_ARGS: "--data-checksums"

  redis:
    profiles: []  # Toujours actif en prod
    restart: always
    command: redis-server --appendonly yes --maxmemory 1gb --maxmemory-policy allkeys-lru --requirepass ${REDIS_PASSWORD}

  qdrant:
    ports: []  # Pas d'exposition externe en prod
    restart: always
    environment:
      QDRANT__SERVICE__API_KEY: ${QDRANT_API_KEY}
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 4G

  ollama:
    restart: always
    ports: []  # Pas d'exposition externe en prod
    deploy:
      resources:
        limits:
          cpus: '4'
          memory: 64G
        reservations:
          devices:
            - driver: nvidia
              count: 1
              capabilities: [gpu]

  queue:
    restart: always
    command: ["php", "artisan", "queue:work", "redis", "--sleep=3", "--tries=3", "--max-time=3600"]
    deploy:
      replicas: 3
```

---

## Dockerfile Application

### Fichier : `docker/app/Dockerfile`

```dockerfile
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

# Configuration PHP pour production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Création du répertoire de travail
WORKDIR /var/www/html

# Copie des fichiers de l'application
COPY --chown=www-data:www-data . .

# Copie du script d'entrypoint
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Installation des dépendances Composer (production)
RUN if [ "$APP_ENV" = "production" ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction; \
    else \
        composer install --optimize-autoloader --no-interaction; \
    fi

# Optimisations Laravel pour production
RUN if [ "$APP_ENV" = "production" ]; then \
        php artisan config:cache \
        && php artisan route:cache \
        && php artisan view:cache; \
    fi

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
```

---

## Script d'Entrypoint (Initialisation Automatique)

### Fichier : `docker/app/entrypoint.sh`

Ce script s'exécute au démarrage du conteneur et initialise automatiquement l'application.

```bash
#!/bin/bash
set -e

# ===========================================
# AI-Manager CMS - Entrypoint Script
# ===========================================
# Ce script initialise automatiquement l'application
# au premier démarrage du conteneur.

echo "🚀 AI-Manager CMS - Initialisation..."

# Fichier marqueur pour éviter la réinitialisation
INIT_MARKER="/var/www/html/storage/.initialized"

# Attendre que PostgreSQL soit prêt
wait_for_db() {
    echo "⏳ Attente de PostgreSQL..."
    until php artisan db:monitor --database=pgsql 2>/dev/null; do
        sleep 2
    done
    echo "✅ PostgreSQL est prêt"
}

# Attendre que Qdrant soit prêt
wait_for_qdrant() {
    echo "⏳ Attente de Qdrant..."
    QDRANT_URL="http://${QDRANT_HOST:-qdrant}:${QDRANT_PORT:-6333}/readyz"
    until curl -sf "$QDRANT_URL" > /dev/null 2>&1; do
        sleep 2
    done
    echo "✅ Qdrant est prêt"
}

# Attendre qu'Ollama soit prêt et télécharger les modèles
wait_for_ollama() {
    echo "⏳ Attente d'Ollama..."
    OLLAMA_URL="http://${OLLAMA_HOST:-ollama}:${OLLAMA_PORT:-11434}/api/tags"
    until curl -sf "$OLLAMA_URL" > /dev/null 2>&1; do
        sleep 2
    done
    echo "✅ Ollama est prêt"
}

# Initialisation de l'application
initialize_app() {
    echo "🔧 Configuration de l'application..."

    # Générer la clé si elle n'existe pas
    if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
        echo "🔑 Génération de la clé d'application..."
        php artisan key:generate --force
    fi

    # Exécuter les migrations
    echo "📦 Exécution des migrations..."
    php artisan migrate --force

    # Exécuter les seeders
    echo "🌱 Exécution des seeders..."
    php artisan db:seed --force

    # Initialiser Qdrant (collections + données de test)
    echo "🧠 Initialisation de Qdrant..."
    php artisan qdrant:init --with-test-data

    # Vider les caches
    echo "🧹 Nettoyage des caches..."
    php artisan config:clear
    php artisan cache:clear
    php artisan view:clear

    # Créer le fichier marqueur
    touch "$INIT_MARKER"
    echo "✅ Initialisation terminée !"
}

# Vérification du premier démarrage
if [ ! -f "$INIT_MARKER" ]; then
    echo "📌 Premier démarrage détecté"

    # Attendre les services
    wait_for_db
    wait_for_qdrant

    # Initialiser l'application
    initialize_app

    # Attendre Ollama en arrière-plan (ne bloque pas le démarrage)
    (wait_for_ollama && echo "🤖 Ollama disponible pour les requêtes IA") &

else
    echo "📌 Application déjà initialisée"

    # Vérifier les migrations en attente
    PENDING=$(php artisan migrate:status --pending 2>/dev/null | grep -c "Pending" || true)
    if [ "$PENDING" -gt 0 ]; then
        echo "📦 $PENDING migration(s) en attente..."
        php artisan migrate --force
    fi
fi

echo "🎉 AI-Manager CMS prêt !"
echo ""
echo "📊 Informations de connexion :"
echo "   - Admin: admin@ai-manager.local / password"
echo "   - URL: http://localhost:8080"
echo ""

# Exécuter la commande passée (php-fpm par défaut)
exec "$@"
```

### Permissions du script

```bash
# Le script doit être exécutable
chmod +x docker/app/entrypoint.sh
```

---

## Configuration Caddy

### Fichier : `docker/caddy/Caddyfile`

```caddyfile
# ===========================================
# AI-Manager CMS - Caddyfile
# ===========================================

# Configuration globale
{
    # Email pour Let's Encrypt (production)
    email {$ACME_EMAIL:admin@example.com}

    # Mode développement : certificats auto-signés
    # Commenter cette ligne en production
    local_certs

    # Logs
    log {
        level INFO
        format console
    }
}

# Site principal
{$SITE_ADDRESS:localhost} {
    # Racine du site
    root * /var/www/html/public

    # Compression
    encode gzip zstd

    # Logs d'accès
    log {
        output file /var/log/caddy/access.log {
            roll_size 10mb
            roll_keep 5
        }
    }

    # Headers de sécurité
    header {
        X-Content-Type-Options "nosniff"
        X-Frame-Options "SAMEORIGIN"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        -Server
    }

    # Assets statiques avec cache long
    @static {
        path *.css *.js *.ico *.gif *.jpg *.jpeg *.png *.svg *.woff *.woff2
    }
    header @static Cache-Control "public, max-age=31536000, immutable"

    # PHP-FPM
    php_fastcgi app:9000 {
        resolve_root_symlink
    }

    # Fichiers statiques
    file_server

    # Fallback Laravel
    try_files {path} {path}/ /index.php?{query}
}

# Health check endpoint (interne)
:8080 {
    respond /health "OK" 200
}
```

---

## Configuration PHP

### Fichier : `docker/app/php.ini`

```ini
; ===========================================
; AI-Manager CMS - Configuration PHP
; ===========================================

[PHP]
; Limites mémoire et temps
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
post_max_size = 100M
upload_max_filesize = 100M

; Gestion des erreurs
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT

; Sessions
session.cookie_httponly = On
session.cookie_secure = On
session.cookie_samesite = Lax
session.use_strict_mode = On

; Timezone
date.timezone = Europe/Paris

[opcache]
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.save_comments = 1
opcache.jit = tracing
opcache.jit_buffer_size = 128M

[curl]
curl.cainfo = /etc/ssl/certs/ca-certificates.crt

[openssl]
openssl.cafile = /etc/ssl/certs/ca-certificates.crt
```

---

## Script d'Initialisation Base de Données

### Fichier : `docker/db/init.sql`

```sql
-- ===========================================
-- AI-Manager CMS - Initialisation PostgreSQL
-- ===========================================

-- Extensions utiles
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";  -- Pour la recherche texte

-- Commentaire
COMMENT ON DATABASE ai_manager IS 'AI-Manager CMS - Base principale';
```

---

## Scripts Utilitaires

### Fichier : `Makefile`

```makefile
# ===========================================
# AI-Manager CMS - Commandes Make
# ===========================================

.PHONY: help install dev prod up down restart logs shell test migrate seed fresh ollama-pull

# Variables
COMPOSE = docker compose
COMPOSE_DEV = $(COMPOSE) -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_PROD = $(COMPOSE) -f docker-compose.yml -f docker-compose.prod.yml
APP_CONTAINER = aim_app

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ===========================================
# INSTALLATION
# ===========================================

install: ## Installation initiale
	cp -n .env.example .env || true
	$(COMPOSE_DEV) build
	$(COMPOSE_DEV) up -d
	$(COMPOSE_DEV) exec app composer install
	$(COMPOSE_DEV) exec app php artisan key:generate
	$(COMPOSE_DEV) exec app php artisan migrate
	$(COMPOSE_DEV) exec app php artisan db:seed
	@echo "Installation terminée ! Accès : http://localhost:8080"

# ===========================================
# ENVIRONNEMENTS
# ===========================================

dev: ## Démarre l'environnement de développement
	$(COMPOSE_DEV) up -d
	@echo "Dev démarré : http://localhost:8080"

prod: ## Démarre l'environnement de production
	$(COMPOSE_PROD) up -d

up: dev ## Alias pour dev

down: ## Arrête tous les conteneurs
	$(COMPOSE) down

restart: ## Redémarre les conteneurs
	$(COMPOSE) restart

# ===========================================
# LOGS & DEBUG
# ===========================================

logs: ## Affiche les logs de tous les services
	$(COMPOSE) logs -f

logs-app: ## Logs de l'application
	$(COMPOSE) logs -f app

logs-ollama: ## Logs d'Ollama
	$(COMPOSE) logs -f ollama

shell: ## Ouvre un shell dans le conteneur app
	$(COMPOSE) exec app sh

# ===========================================
# TESTS
# ===========================================

test: ## Lance les tests PHPUnit
	$(COMPOSE) exec app php artisan test

test-coverage: ## Tests avec couverture de code
	$(COMPOSE) exec app php artisan test --coverage

# ===========================================
# BASE DE DONNÉES
# ===========================================

migrate: ## Exécute les migrations
	$(COMPOSE) exec app php artisan migrate

seed: ## Exécute les seeders
	$(COMPOSE) exec app php artisan db:seed

fresh: ## Reset complet de la BDD
	$(COMPOSE) exec app php artisan migrate:fresh --seed

# ===========================================
# OLLAMA
# ===========================================

ollama-pull: ## Télécharge les modèles IA par défaut
	$(COMPOSE) exec ollama ollama pull nomic-embed-text
	$(COMPOSE) exec ollama ollama pull mistral:7b
	@echo "Modèles téléchargés !"

ollama-pull-prod: ## Télécharge les modèles pour la production
	$(COMPOSE) exec ollama ollama pull nomic-embed-text
	$(COMPOSE) exec ollama ollama pull llama3.3:70b
	$(COMPOSE) exec ollama ollama pull mistral-small
	@echo "Modèles production téléchargés !"

ollama-list: ## Liste les modèles installés
	$(COMPOSE) exec ollama ollama list

# ===========================================
# QDRANT
# ===========================================

qdrant-init: ## Initialise les collections Qdrant
	$(COMPOSE) exec app php artisan qdrant:init

qdrant-status: ## Vérifie le statut de Qdrant
	curl -s http://localhost:6333/readyz | jq

# ===========================================
# CACHE & OPTIMISATION
# ===========================================

cache-clear: ## Vide tous les caches
	$(COMPOSE) exec app php artisan cache:clear
	$(COMPOSE) exec app php artisan config:clear
	$(COMPOSE) exec app php artisan route:clear
	$(COMPOSE) exec app php artisan view:clear

optimize: ## Optimise l'application pour la production
	$(COMPOSE) exec app php artisan config:cache
	$(COMPOSE) exec app php artisan route:cache
	$(COMPOSE) exec app php artisan view:cache
	$(COMPOSE) exec app php artisan icons:cache
```

---

## Vérification de Santé au Démarrage

L'application vérifie automatiquement la connectivité des services au démarrage :

### Service Provider : `app/Providers/HealthCheckServiceProvider.php`

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AI\QdrantService;
use App\Services\AI\OllamaService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class HealthCheckServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            $this->checkServices();
        }
    }

    private function checkServices(): void
    {
        // Vérification Qdrant
        try {
            $qdrant = app(QdrantService::class);
            $qdrant->ensureCollectionsExist();
            Log::info('Qdrant: Collections vérifiées');
        } catch (\Exception $e) {
            Log::warning('Qdrant: Service non disponible - ' . $e->getMessage());
        }

        // Vérification Ollama
        try {
            $ollama = app(OllamaService::class);
            $models = $ollama->listModels();
            Log::info('Ollama: ' . count($models) . ' modèle(s) disponible(s)');
        } catch (\Exception $e) {
            Log::warning('Ollama: Service non disponible - ' . $e->getMessage());
        }
    }
}
```

---

## Configuration Ollama Externe

Pour utiliser un serveur Ollama externe (GPU dédié, cloud, etc.) :

### Variables d'environnement

```env
# Serveur Ollama externe
OLLAMA_HOST=192.168.1.100
OLLAMA_PORT=11434

# Ou par agent (dans la table agents)
# Champ ollama_host permet de surcharger par agent
```

### Configuration par Agent

```php
// Dans la table agents, champ optionnel
[
    'name' => 'Expert BTP',
    'ollama_host' => '192.168.1.200',  // Serveur GPU dédié
    'ollama_port' => 11434,
    'model' => 'llama3.3:70b',
]
```

---

## Déploiement AWS (Production)

### Architecture Recommandée

```
┌─────────────────────────────────────────────────────────────┐
│                         AWS VPC                              │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─────────────┐     ┌─────────────────────────────────┐    │
│  │    ALB      │────▶│  ECS Fargate / EC2              │    │
│  │ (HTTPS)     │     │  ┌─────┐ ┌─────┐ ┌─────┐       │    │
│  └─────────────┘     │  │ App │ │ App │ │Queue│       │    │
│                      │  └─────┘ └─────┘ └─────┘       │    │
│                      └─────────────────────────────────┘    │
│                                  │                          │
│         ┌────────────────────────┼────────────────┐         │
│         ▼                        ▼                ▼         │
│  ┌─────────────┐          ┌─────────────┐  ┌─────────────┐  │
│  │ RDS Postgres│          │ElastiCache  │  │    EC2      │  │
│  │  (Multi-AZ) │          │  (Redis)    │  │ GPU (Ollama)│  │
│  └─────────────┘          └─────────────┘  └─────────────┘  │
│                                                    │        │
│                                             ┌──────▼──────┐ │
│                                             │   EBS/EFS   │ │
│                                             │(Qdrant Data)│ │
│                                             └─────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### Instance Recommandée pour Ollama

| Modèle IA | Instance AWS | GPU | RAM |
|-----------|--------------|-----|-----|
| mistral:7b | g4dn.xlarge | T4 | 16GB |
| mistral-small | g5.2xlarge | A10G | 32GB |
| llama3.3:70b | g5.12xlarge | 4x A10G | 192GB |

---

## Checklist de Déploiement

### Développement

- [ ] Copier `.env.example` vers `.env`
- [ ] Exécuter `make install`
- [ ] Télécharger les modèles : `make ollama-pull`
- [ ] Vérifier Qdrant : `make qdrant-status`
- [ ] Lancer les tests : `make test`

### Production

- [ ] Configurer les secrets (`.env` sécurisé)
- [ ] Configurer le domaine dans Caddyfile
- [ ] Activer Redis (`REDIS_ENABLED=true`)
- [ ] Configurer les backups PostgreSQL
- [ ] Télécharger les modèles prod : `make ollama-pull-prod`
- [ ] Configurer les alertes de monitoring
- [ ] Vérifier les certificats SSL
- [ ] Tester le failover si Multi-AZ
