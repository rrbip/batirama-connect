#!/bin/bash
# ===========================================
# AI-Manager CMS - Script de Déploiement
# ===========================================
# Usage:
#   ./deploy.sh              # Mise à jour standard
#   ./deploy.sh --rebuild    # Rebuild complet des images
#   ./deploy.sh --fresh      # Reset complet (supprime les données)
#   ./deploy.sh --migrate    # Seulement les migrations
#
# ===========================================

set -e

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Options
REBUILD=false
FRESH=false
MIGRATE_ONLY=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --rebuild)
            REBUILD=true
            shift
            ;;
        --fresh)
            FRESH=true
            shift
            ;;
        --migrate)
            MIGRATE_ONLY=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --rebuild    Rebuild les images Docker (après modification Dockerfile)"
            echo "  --fresh      Reset complet (supprime les données et réinitialise)"
            echo "  --migrate    Exécute seulement les migrations et vide les caches"
            echo "  -h, --help   Affiche cette aide"
            exit 0
            ;;
        *)
            echo -e "${RED}Option inconnue: $1${NC}"
            exit 1
            ;;
    esac
done

# ===========================================
# Fonctions utilitaires
# ===========================================

# Obtenir la branche courante (compatible toutes versions Git)
get_current_branch() {
    git symbolic-ref --short HEAD 2>/dev/null || git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main"
}

wait_for_container() {
    local max_attempts=30
    local attempt=1
    echo -e "${YELLOW}⏳ Attente du container app...${NC}"
    while [ $attempt -le $max_attempts ]; do
        if docker compose ps app 2>/dev/null | grep -qE "running|Up|healthy"; then
            return 0
        fi
        sleep 2
        attempt=$((attempt + 1))
    done
    echo -e "${RED}❌ Container app non démarré${NC}"
    docker compose logs app --tail=20
    return 1
}

wait_for_database() {
    local max_attempts=30
    local attempt=1
    echo -e "${YELLOW}⏳ Attente de la base de données...${NC}"
    while [ $attempt -le $max_attempts ]; do
        if docker compose exec -T app php artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null; then
            return 0
        fi
        sleep 2
        attempt=$((attempt + 1))
    done
    echo -e "${RED}❌ Base de données non accessible${NC}"
    return 1
}

run_migrations() {
    echo -e "${YELLOW}📦 Exécution des migrations...${NC}"
    if docker compose exec -T app php artisan migrate --force; then
        echo -e "${GREEN}   ✅ Migrations OK${NC}"
        return 0
    fi
    echo -e "${RED}   ❌ Migrations échouées${NC}"
    return 1
}

clear_caches() {
    echo -e "${YELLOW}🗑️  Vidage des caches...${NC}"
    docker compose exec -T app php artisan config:clear 2>/dev/null || true
    docker compose exec -T app php artisan cache:clear 2>/dev/null || true
    docker compose exec -T app php artisan view:clear 2>/dev/null || true
    docker compose exec -T app php artisan route:clear 2>/dev/null || true
    docker compose exec -T app php artisan event:clear 2>/dev/null || true
    echo -e "${GREEN}   ✅ Caches vidés${NC}"
}

publish_assets() {
    echo -e "${YELLOW}📦 Publication des assets...${NC}"
    docker compose exec -T app php artisan filament:assets 2>/dev/null || true
    docker compose exec -T app php artisan storage:link 2>/dev/null || true
    echo -e "${GREEN}   ✅ Assets publiés${NC}"
}

optimize_app() {
    echo -e "${YELLOW}⚡ Optimisation...${NC}"
    docker compose exec -T app php artisan config:cache 2>/dev/null || true
    docker compose exec -T app php artisan route:cache 2>/dev/null || true
    docker compose exec -T app php artisan view:cache 2>/dev/null || true
    echo -e "${GREEN}   ✅ Optimisé${NC}"
}

# ===========================================
# DÉBUT DU SCRIPT
# ===========================================

echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║           AI-Manager CMS - Déploiement                   ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

# ===========================================
# MODE MIGRATE ONLY
# ===========================================
if [ "$MIGRATE_ONLY" = true ]; then
    echo -e "${YELLOW}🔧 Mode migrations seulement...${NC}"
    wait_for_container || exit 1
    wait_for_database || exit 1
    clear_caches
    run_migrations || exit 1
    publish_assets
    optimize_app
    echo -e "${GREEN}✅ Migrations terminées !${NC}"
    exit 0
fi

# ===========================================
# ÉTAPE 1: Récupérer les dernières modifications
# ===========================================
echo -e "${YELLOW}📥 [1/5] Récupération du code...${NC}"

# Sauvegarder les modifications locales si présentes
if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
    echo -e "${YELLOW}   ⚠️  Stash des modifications locales...${NC}"
    git stash 2>/dev/null || true
fi

# Récupérer la branche courante
CURRENT_BRANCH=$(get_current_branch)
echo -e "${YELLOW}   Branche: ${CURRENT_BRANCH}${NC}"

# Pull les changements
if git pull origin "$CURRENT_BRANCH" 2>/dev/null; then
    echo -e "${GREEN}   ✅ Code mis à jour${NC}"
else
    echo -e "${YELLOW}   ⚠️  Git pull échoué, on continue...${NC}"
fi

# ===========================================
# ÉTAPE 2: Mode Fresh (optionnel)
# ===========================================
if [ "$FRESH" = true ]; then
    echo ""
    echo -e "${RED}⚠️  MODE FRESH: Suppression des données...${NC}"
    read -p "Tapez 'yes' pour confirmer: " confirm
    if [ "$confirm" = "yes" ]; then
        docker compose down -v
        rm -f storage/.initialized
        echo -e "${GREEN}   ✅ Données supprimées${NC}"
    else
        echo -e "${YELLOW}   Annulé.${NC}"
        exit 0
    fi
fi

# ===========================================
# ÉTAPE 3: Build des images
# ===========================================
echo ""
if [ "$REBUILD" = true ]; then
    echo -e "${YELLOW}🔨 [2/5] Rebuild des images (--no-cache)...${NC}"
    docker compose build --no-cache app
else
    echo -e "${YELLOW}🔨 [2/5] Build des images...${NC}"
    docker compose build app 2>/dev/null || true
fi
echo -e "${GREEN}   ✅ Images prêtes${NC}"

# ===========================================
# ÉTAPE 4: Démarrer les services
# ===========================================
echo ""
echo -e "${YELLOW}🚀 [3/5] Démarrage des services...${NC}"
docker compose up -d

wait_for_container || exit 1
echo -e "${GREEN}   ✅ Services démarrés${NC}"

# ===========================================
# ÉTAPE 5: Base de données et migrations
# ===========================================
echo ""
echo -e "${YELLOW}🗄️  [4/5] Base de données...${NC}"

wait_for_database || exit 1
run_migrations || exit 1

# ===========================================
# ÉTAPE 6: Post-déploiement
# ===========================================
echo ""
echo -e "${YELLOW}🔧 [5/5] Finalisation...${NC}"

publish_assets
clear_caches
optimize_app

# ===========================================
# VÉRIFICATION FINALE
# ===========================================
echo ""
docker compose ps

APP_URL=$(grep -E "^APP_URL=" .env 2>/dev/null | cut -d'=' -f2 | tr -d '"' || echo "http://localhost:8080")

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║         ✅ Déploiement terminé avec succès !             ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${BLUE}🌐 Application: ${APP_URL}${NC}"
echo -e "${BLUE}🔐 Admin panel: ${APP_URL}/admin${NC}"
echo ""
