#!/bin/bash
# ===========================================
# AI-Manager CMS - Script de Déploiement
# ===========================================
# Usage:
#   ./deploy.sh              # Mise à jour standard
#   ./deploy.sh --rebuild    # Rebuild complet des images
#   ./deploy.sh --fresh      # Reset complet (supprime les données)
#   ./deploy.sh --migrate    # Seulement les migrations
#   ./deploy.sh --skip-git   # Sauter l'étape git
#
# ===========================================

set -e

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Options
REBUILD=false
FRESH=false
MIGRATE_ONLY=false
SKIP_GIT=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --rebuild) REBUILD=true ;;
        --fresh) FRESH=true ;;
        --migrate) MIGRATE_ONLY=true ;;
        --skip-git) SKIP_GIT=true ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo "  --rebuild    Rebuild images Docker"
            echo "  --fresh      Reset complet"
            echo "  --migrate    Migrations seulement"
            echo "  --skip-git   Sauter git pull"
            exit 0
            ;;
    esac
done

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
    docker compose exec -T app php artisan migrate --force
    docker compose exec -T app php artisan optimize:clear
    docker compose exec -T app php artisan filament:assets 2>/dev/null || true
    echo -e "${GREEN}✅ Migrations terminées !${NC}"
    exit 0
fi

# ===========================================
# ÉTAPE 1: Git (optionnel)
# ===========================================
if [ "$SKIP_GIT" = false ]; then
    echo -e "${YELLOW}📥 [1/5] Récupération du code...${NC}"
    
    # Stash modifications locales
    git stash 2>/dev/null || true
    
    # Récupérer la branche (méthode compatible)
    BRANCH=$(git symbolic-ref --short HEAD 2>/dev/null || echo "main")
    echo -e "${YELLOW}   Branche: ${BRANCH}${NC}"
    
    # Pull
    git pull origin "$BRANCH" 2>/dev/null && echo -e "${GREEN}   ✅ Code mis à jour${NC}" || echo -e "${YELLOW}   ⚠️  Git pull échoué${NC}"
else
    echo -e "${YELLOW}📥 [1/5] Git ignoré (--skip-git)${NC}"
fi

# ===========================================
# ÉTAPE 2: Mode Fresh
# ===========================================
if [ "$FRESH" = true ]; then
    echo -e "${RED}⚠️  MODE FRESH: Suppression des données...${NC}"
    read -p "Tapez 'yes': " confirm
    [ "$confirm" = "yes" ] && docker compose down -v || exit 0
fi

# ===========================================
# ÉTAPE 3: Build
# ===========================================
echo ""
echo -e "${YELLOW}🔨 [2/5] Build des images...${NC}"
if [ "$REBUILD" = true ]; then
    docker compose build --no-cache app
else
    docker compose build app 2>/dev/null || true
fi
echo -e "${GREEN}   ✅ Images prêtes${NC}"

# ===========================================
# ÉTAPE 4: Démarrage
# ===========================================
echo ""
echo -e "${YELLOW}🚀 [3/5] Démarrage des services...${NC}"
docker compose up -d

# Attendre le container
echo -e "${YELLOW}⏳ Attente du container...${NC}"
for i in $(seq 1 30); do
    docker compose ps app 2>/dev/null | grep -qE "running|Up|healthy" && break
    sleep 2
done
echo -e "${GREEN}   ✅ Services démarrés${NC}"

# ===========================================
# ÉTAPE 5: Migrations
# ===========================================
echo ""
echo -e "${YELLOW}🗄️  [4/5] Migrations...${NC}"

# Attendre la DB
for i in $(seq 1 30); do
    docker compose exec -T app php artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null && break
    sleep 2
done

docker compose exec -T app php artisan migrate --force
echo -e "${GREEN}   ✅ Migrations OK${NC}"

# ===========================================
# ÉTAPE 6: Finalisation
# ===========================================
echo ""
echo -e "${YELLOW}🔧 [5/5] Finalisation...${NC}"

docker compose exec -T app php artisan filament:assets 2>/dev/null || true
docker compose exec -T app php artisan storage:link 2>/dev/null || true
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache 2>/dev/null || true
docker compose exec -T app php artisan route:cache 2>/dev/null || true
docker compose exec -T app php artisan view:cache 2>/dev/null || true

echo -e "${GREEN}   ✅ Finalisé${NC}"

# ===========================================
# FIN
# ===========================================
echo ""
docker compose ps
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║         ✅ Déploiement terminé avec succès !             ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
