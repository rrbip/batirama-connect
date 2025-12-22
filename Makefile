# ===========================================
# AI-Manager CMS - Commandes Make
# ===========================================

.PHONY: help install update dev prod up down restart logs shell test migrate seed fresh ollama-pull

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
# MISES À JOUR
# ===========================================

update: ## Met à jour l'application (pull + rebuild + migrate)
	@echo "📥 Récupération du code..."
	git pull origin $$(git branch --show-current)
	@echo "🔨 Reconstruction de l'image app..."
	$(COMPOSE) build app
	@echo "🚀 Redémarrage des services..."
	$(COMPOSE) up -d
	@echo "📦 Exécution des migrations..."
	$(COMPOSE) exec app php artisan migrate --force
	@echo "🧹 Nettoyage des caches..."
	$(COMPOSE) exec app php artisan config:clear
	$(COMPOSE) exec app php artisan cache:clear
	$(COMPOSE) exec app php artisan view:clear
	@echo "✅ Mise à jour terminée !"

update-prod: ## Met à jour en production (avec optimisations)
	@echo "📥 Récupération du code..."
	git pull origin main
	@echo "🔨 Reconstruction de l'image app..."
	$(COMPOSE_PROD) build app
	@echo "🚀 Redémarrage des services..."
	$(COMPOSE_PROD) up -d
	@echo "📦 Exécution des migrations..."
	$(COMPOSE_PROD) exec app php artisan migrate --force
	@echo "⚡ Optimisation pour la production..."
	$(COMPOSE_PROD) exec app php artisan config:cache
	$(COMPOSE_PROD) exec app php artisan route:cache
	$(COMPOSE_PROD) exec app php artisan view:cache
	@echo "✅ Mise à jour production terminée !"

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
