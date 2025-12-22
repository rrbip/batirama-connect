#!/bin/bash
set -e

# ===========================================
# AI-Manager CMS - Installation Automatique
# ===========================================
# Usage: ./install.sh [dev|prod]

MODE="${1:-dev}"
COMPOSE_DEV="docker compose -f docker-compose.yml -f docker-compose.dev.yml"
COMPOSE_PROD="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║           AI-Manager CMS - Installation                  ║"
echo "║                Mode: $MODE                               ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# Sélection du compose selon le mode
if [ "$MODE" = "prod" ]; then
    COMPOSE="$COMPOSE_PROD"
else
    COMPOSE="$COMPOSE_DEV"
fi

# ===========================================
# ÉTAPE 1 : Configuration .env
# ===========================================
echo "📝 Configuration de l'environnement..."

if [ ! -f .env ]; then
    cp .env.example .env
    echo "   ✓ Fichier .env créé depuis .env.example"

    # Générer une clé d'application unique
    APP_KEY=$(openssl rand -base64 32)
    sed -i "s|APP_KEY=.*|APP_KEY=base64:$APP_KEY|" .env
    echo "   ✓ Clé d'application générée"

    # Générer un mot de passe DB aléatoire si mode prod
    if [ "$MODE" = "prod" ]; then
        DB_PASS=$(openssl rand -hex 16)
        sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" .env
        echo "   ✓ Mot de passe DB généré"

        # Demander le domaine
        read -p "   Entrez votre nom de domaine (ex: monsite.com): " DOMAIN
        if [ -n "$DOMAIN" ]; then
            sed -i "s|SITE_ADDRESS=.*|SITE_ADDRESS=$DOMAIN|" .env
            echo "   ✓ Domaine configuré: $DOMAIN"
        fi

        # Demander l'email pour SSL
        read -p "   Entrez votre email pour Let's Encrypt: " EMAIL
        if [ -n "$EMAIL" ]; then
            sed -i "s|ACME_EMAIL=.*|ACME_EMAIL=$EMAIL|" .env
            echo "   ✓ Email SSL configuré: $EMAIL"
        fi
    fi
else
    echo "   ✓ Fichier .env existant conservé"
fi

# ===========================================
# ÉTAPE 2 : Construction des images
# ===========================================
echo ""
echo "🔨 Construction des images Docker..."
$COMPOSE build --no-cache
echo "   ✓ Images construites"

# ===========================================
# ÉTAPE 3 : Démarrage des services
# ===========================================
echo ""
echo "🚀 Démarrage des services..."
$COMPOSE up -d
echo "   ✓ Services démarrés"

# ===========================================
# ÉTAPE 4 : Attendre que tout soit prêt
# ===========================================
echo ""
echo "⏳ Attente de l'initialisation (peut prendre quelques minutes)..."

# Attendre que l'app soit healthy
MAX_WAIT=120
WAITED=0
while [ $WAITED -lt $MAX_WAIT ]; do
    if docker compose exec -T app php artisan --version > /dev/null 2>&1; then
        break
    fi
    sleep 5
    WAITED=$((WAITED + 5))
    echo "   ... encore $((MAX_WAIT - WAITED)) secondes max"
done

# ===========================================
# ÉTAPE 5 : Affichage du statut
# ===========================================
echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║                 Installation Terminée !                  ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""
echo "📊 Statut des services :"
docker compose ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"
echo ""

if [ "$MODE" = "dev" ]; then
    echo "🌐 Accès à l'application :"
    echo "   URL:      http://localhost:8080"
    echo "   Admin:    admin@ai-manager.local / password"
    echo ""
    echo "📦 Commandes utiles :"
    echo "   make logs        - Voir les logs"
    echo "   make shell       - Accéder au conteneur"
    echo "   make ollama-list - Voir les modèles IA"
else
    DOMAIN=$(grep SITE_ADDRESS .env | cut -d'=' -f2)
    echo "🌐 Accès à l'application :"
    echo "   URL:      https://$DOMAIN"
    echo "   Admin:    Créez un compte via la CLI"
    echo ""
    echo "⚠️  N'oubliez pas de :"
    echo "   1. Configurer votre DNS pour pointer vers ce serveur"
    echo "   2. Supprimer 'local_certs' du Caddyfile pour activer SSL"
fi

echo ""
echo "📖 Documentation : docs/00_index.md"
echo ""
