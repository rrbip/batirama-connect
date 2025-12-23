#!/bin/bash
set -e

# ===========================================
# AI-Manager CMS - Entrypoint Script
# ===========================================
# Initialisation 100% automatique au premier démarrage

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║           AI-Manager CMS - Démarrage                     ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# Fichier marqueur pour éviter la réinitialisation
INIT_MARKER="/var/www/html/storage/.initialized"

# ===========================================
# VÉRIFICATION DES DÉPENDANCES
# ===========================================

# Installer composer si vendor n'existe pas (cas du volume mount)
if [ ! -d "/var/www/html/vendor" ]; then
    echo "📦 Installation des dépendances Composer..."
    if [ "$APP_ENV" = "production" ]; then
        composer install --no-dev --optimize-autoloader --no-interaction
    else
        composer install --optimize-autoloader --no-interaction
    fi
    echo "✅ Dépendances installées"
fi

# Créer les dossiers Laravel si nécessaires
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Modèles IA à télécharger automatiquement
OLLAMA_MODELS="${OLLAMA_MODELS:-nomic-embed-text,mistral:7b}"

# ===========================================
# FONCTIONS UTILITAIRES
# ===========================================

wait_for_db() {
    echo "⏳ Attente de PostgreSQL..."
    local max_attempts=30
    local attempt=0

    # Utiliser une connexion PHP directe au lieu de artisan
    until php -r "
        \$host = getenv('DB_HOST') ?: 'db';
        \$port = getenv('DB_PORT') ?: '5432';
        \$dbname = getenv('DB_DATABASE') ?: 'ai_manager';
        \$user = getenv('DB_USERNAME') ?: 'postgres';
        \$pass = getenv('DB_PASSWORD') ?: 'secret';
        try {
            new PDO(\"pgsql:host=\$host;port=\$port;dbname=\$dbname\", \$user, \$pass);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; do
        attempt=$((attempt + 1))
        if [ $attempt -ge $max_attempts ]; then
            echo "❌ PostgreSQL non disponible après ${max_attempts} tentatives"
            echo "   Host: ${DB_HOST:-db}, Port: ${DB_PORT:-5432}, DB: ${DB_DATABASE:-ai_manager}"
            exit 1
        fi
        echo "   Tentative $attempt/$max_attempts..."
        sleep 2
    done
    echo "✅ PostgreSQL connecté"
}

wait_for_qdrant() {
    echo "⏳ Attente de Qdrant..."
    local max_attempts=30
    local attempt=0
    local url="http://${QDRANT_HOST:-qdrant}:${QDRANT_PORT:-6333}/readyz"

    until curl -sf "$url" > /dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ $attempt -ge $max_attempts ]; then
            echo "❌ Qdrant non disponible après ${max_attempts} tentatives"
            exit 1
        fi
        sleep 2
    done
    echo "✅ Qdrant connecté"
}

wait_for_ollama() {
    echo "⏳ Attente d'Ollama..."
    local max_attempts=60
    local attempt=0
    local url="http://${OLLAMA_HOST:-ollama}:${OLLAMA_PORT:-11434}/api/tags"

    until curl -sf "$url" > /dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ $attempt -ge $max_attempts ]; then
            echo "⚠️  Ollama non disponible - les modèles seront téléchargés plus tard"
            return 1
        fi
        sleep 2
    done
    echo "✅ Ollama connecté"
    return 0
}

pull_ollama_models() {
    echo "📥 Téléchargement des modèles IA..."
    local ollama_url="http://${OLLAMA_HOST:-ollama}:${OLLAMA_PORT:-11434}"

    # Convertir la liste en array
    IFS=',' read -ra MODELS <<< "$OLLAMA_MODELS"

    for model in "${MODELS[@]}"; do
        model=$(echo "$model" | xargs)  # Trim whitespace
        echo "   ⏳ Téléchargement de $model..."

        # Vérifier si le modèle existe déjà
        if curl -sf "${ollama_url}/api/show" -d "{\"name\":\"$model\"}" > /dev/null 2>&1; then
            echo "   ✓ $model déjà présent"
        else
            # Télécharger le modèle via l'API Ollama
            if curl -sf "${ollama_url}/api/pull" -d "{\"name\":\"$model\",\"stream\":false}" > /dev/null 2>&1; then
                echo "   ✓ $model téléchargé"
            else
                # Fallback: utiliser la commande ollama directement via docker exec
                if docker exec aim_ollama ollama pull "$model" 2>/dev/null; then
                    echo "   ✓ $model téléchargé"
                else
                    echo "   ⚠️  Échec du téléchargement de $model (sera retéléchargé au besoin)"
                fi
            fi
        fi
    done

    echo "✅ Modèles IA configurés"
}

initialize_app() {
    echo ""
    echo "🔧 Initialisation de l'application..."

    # Générer la clé si elle n'existe pas
    if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
        echo "   🔑 Génération de la clé d'application..."
        php artisan key:generate --force
    fi

    # Exécuter les migrations
    echo "   📦 Exécution des migrations..."
    php artisan migrate --force

    # Exécuter les seeders
    echo "   🌱 Exécution des seeders..."
    php artisan db:seed --force

    # Initialiser Qdrant
    echo "   🧠 Initialisation des collections Qdrant..."
    php artisan qdrant:init --with-test-data

    # Optimisations (ignorer les erreurs de permissions non critiques)
    echo "   ⚡ Optimisation des caches..."
    php artisan config:clear || true
    php artisan cache:clear || true
    php artisan view:clear || true

    # Créer le fichier marqueur
    touch "$INIT_MARKER"

    echo "✅ Application initialisée"
}

# ===========================================
# LOGIQUE PRINCIPALE
# ===========================================

if [ ! -f "$INIT_MARKER" ]; then
    echo "📌 Premier démarrage détecté - Initialisation complète"
    echo ""

    # Attendre les services critiques
    wait_for_db
    wait_for_qdrant

    # Initialiser l'application
    initialize_app

    # Télécharger les modèles IA en arrière-plan
    (
        if wait_for_ollama; then
            pull_ollama_models
        fi
    ) &

else
    echo "📌 Application déjà initialisée"

    # Vérifier les migrations en attente
    PENDING=$(php artisan migrate:status --pending 2>/dev/null | grep -c "Pending" || true)
    if [ "$PENDING" -gt 0 ]; then
        echo "   📦 $PENDING migration(s) en attente..."
        php artisan migrate --force
    fi
fi

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║              AI-Manager CMS - Prêt !                     ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""
echo "📊 Informations :"
echo "   Mode:     ${APP_ENV:-local}"
echo "   Admin:    admin@ai-manager.local / password"
echo ""

# Exécuter la commande passée (php-fpm par défaut)
exec "$@"
