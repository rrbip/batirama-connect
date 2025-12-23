#!/bin/bash
# ===========================================
# AI-Manager CMS - Script de Déploiement
# ===========================================
# Usage:
#   ./deploy.sh              # Mise à jour standard
#   ./deploy.sh --rebuild    # Rebuild complet des images
#   ./deploy.sh --fresh      # Reset complet (supprime les données)
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
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --rebuild    Rebuild les images Docker (après modification Dockerfile)"
            echo "  --fresh      Reset complet (supprime les données et réinitialise)"
            echo "  -h, --help   Affiche cette aide"
            exit 0
            ;;
        *)
            echo -e "${RED}Option inconnue: $1${NC}"
            exit 1
            ;;
    esac
done

echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║           AI-Manager CMS - Déploiement                   ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

# ===========================================
# ÉTAPE 1: Récupérer les dernières modifications
# ===========================================
echo -e "${YELLOW}📥 Récupération des dernières modifications...${NC}"

# Sauvegarder les modifications locales si présentes
if [ -n "$(git status --porcelain)" ]; then
    echo -e "${YELLOW}   ⚠️  Modifications locales détectées, création d'un stash...${NC}"
    # Utiliser 'git stash save' pour compatibilité avec Git < 2.13
    git stash save "deploy-$(date +%Y%m%d-%H%M%S)" 2>/dev/null || git stash
fi

# Pull les changements
CURRENT_BRANCH=$(git branch --show-current)
git pull origin "$CURRENT_BRANCH"

echo -e "${GREEN}✅ Code mis à jour${NC}"

# ===========================================
# ÉTAPE 2: Mode Fresh (optionnel)
# ===========================================
if [ "$FRESH" = true ]; then
    echo ""
    echo -e "${RED}⚠️  MODE FRESH: Suppression des données...${NC}"
    read -p "Êtes-vous sûr ? (yes/no): " confirm
    if [ "$confirm" = "yes" ]; then
        docker compose down -v
        rm -f storage/.initialized
        echo -e "${GREEN}✅ Données supprimées${NC}"
    else
        echo -e "${YELLOW}Annulé.${NC}"
        exit 0
    fi
fi

# ===========================================
# ÉTAPE 3: Build/Rebuild des images
# ===========================================
echo ""
if [ "$REBUILD" = true ]; then
    echo -e "${YELLOW}🔨 Rebuild des images Docker...${NC}"
    docker compose build --no-cache app
else
    echo -e "${YELLOW}🔨 Build des images Docker (si nécessaire)...${NC}"
    docker compose build app
fi
echo -e "${GREEN}✅ Images prêtes${NC}"

# ===========================================
# ÉTAPE 4: Démarrer les services
# ===========================================
echo ""
echo -e "${YELLOW}🚀 Démarrage des services...${NC}"
docker compose up -d

# Attendre que le container app soit prêt
echo -e "${YELLOW}⏳ Attente du démarrage de l'application...${NC}"
sleep 5

# Vérifier que le container est bien démarré
if ! docker compose ps app | grep -q "running\|Up"; then
    echo -e "${RED}❌ Le container app n'a pas démarré correctement${NC}"
    echo "Logs:"
    docker compose logs app --tail=50
    exit 1
fi

echo -e "${GREEN}✅ Services démarrés${NC}"

# ===========================================
# ÉTAPE 5: Post-déploiement
# ===========================================
echo ""
echo -e "${YELLOW}🔧 Configuration post-déploiement...${NC}"

# Publier les assets Filament (si Filament est installé)
if docker compose exec -T app php -r "exit(class_exists('Filament\FilamentServiceProvider') ? 0 : 1);" 2>/dev/null; then
    echo "   📦 Publication des assets Filament..."
    docker compose exec -T app php artisan filament:assets 2>/dev/null || true
fi

# Vider les caches
echo "   🗑️  Vidage des caches..."
docker compose exec -T app php artisan optimize:clear 2>/dev/null || true

# Exécuter les migrations
echo "   📦 Exécution des migrations..."
docker compose exec -T app php artisan migrate --force

echo -e "${GREEN}✅ Configuration terminée${NC}"

# ===========================================
# ÉTAPE 6: Vérification
# ===========================================
echo ""
echo -e "${YELLOW}🔍 Vérification...${NC}"

# Vérifier l'état des containers
echo ""
docker compose ps

# Tester que l'application répond
APP_URL=$(grep -E "^APP_URL=" .env 2>/dev/null | cut -d'=' -f2 || echo "http://localhost:8080")
echo ""
echo -e "${BLUE}🌐 Application accessible sur: ${APP_URL}${NC}"
echo -e "${BLUE}🔐 Admin panel: ${APP_URL}/admin${NC}"

# ===========================================
# TERMINÉ
# ===========================================
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║              Déploiement terminé avec succès !           ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
