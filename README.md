# AI-Manager CMS (Batirama Connect)

Plateforme de gestion d'agents IA locaux avec RAG et apprentissage continu pour le secteur BTP.

## Stack Technique

- **Backend** : Laravel 12, PHP 8.4
- **Base de données** : PostgreSQL 17
- **Cache/Queue** : Redis 7
- **IA** : Ollama (mistral:7b, nomic-embed-text)
- **Recherche vectorielle** : Qdrant 1.16
- **Serveur web** : Caddy 2.10 (reverse proxy, auto-SSL)

---

## Déploiement

### Prérequis

- Docker et Docker Compose v2+
- 8 Go RAM minimum (16 Go recommandé pour Ollama)
- 20 Go d'espace disque

### Installation (Une seule commande)

```bash
# Cloner le projet
git clone <repo-url> batirama-connect
cd batirama-connect

# Lancer l'installation automatique
./install.sh
```

L'installation automatique :
1. Crée le fichier `.env` depuis `.env.example`
2. Démarre les conteneurs Docker
3. Attend que les services soient prêts
4. Exécute les migrations et seeders
5. Initialise Qdrant avec les données de test
6. Télécharge les modèles Ollama

### Déploiement Manuel

```bash
# Copier et configurer l'environnement
cp .env.example .env
nano .env  # Configurer les variables

# Démarrer les services
docker compose up -d

# Exécuter les migrations
docker compose exec app php artisan migrate --force

# Exécuter les seeders
docker compose exec app php artisan db:seed --force

# Initialiser Qdrant
docker compose exec app php artisan qdrant:init --with-test-data
```

### Accès

| Service | URL | Identifiants |
|---------|-----|--------------|
| Application | http://localhost:8080 | - |
| Admin | http://localhost:8080/admin | admin@ai-manager.local / password |
| Qdrant Dashboard | http://localhost:6333/dashboard | - |

---

## Mises à Jour

### Mise à jour rapide (développement)

```bash
make update
```

Cette commande :
- Pull les dernières images Docker
- Exécute les migrations
- Nettoie les caches

### Mise à jour complète (production)

```bash
make update-prod
```

Cette commande :
- Pull les images Docker
- Exécute les migrations
- Recompile les caches (config, routes, views)
- Redémarre les workers

### Mise à jour manuelle

```bash
# Récupérer les derniers changements
git pull origin main

# Mettre à jour les dépendances
docker compose exec app composer install --no-dev --optimize-autoloader

# Exécuter les migrations
docker compose exec app php artisan migrate --force

# Recompiler les caches (production)
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache

# Redémarrer les workers
docker compose restart worker
```

---

## Commandes Artisan

### Qdrant / Indexation

```bash
# Initialiser les collections Qdrant
php artisan qdrant:init

# Initialiser avec données de test
php artisan qdrant:init --with-test-data

# Forcer la recréation des collections
php artisan qdrant:init --force

# Afficher les statistiques Qdrant
php artisan qdrant:stats
php artisan qdrant:stats agent_btp_ouvrages  # Collection spécifique
```

### Indexation des Ouvrages

```bash
# Indexer les ouvrages non indexés
php artisan ouvrages:index

# Indexer avec taille de batch personnalisée
php artisan ouvrages:index --chunk=50

# Forcer la réindexation de tous les ouvrages
php artisan ouvrages:index --force

# Indexer seulement les ouvrages composés
php artisan ouvrages:index --type=compose
```

### Réindexation des Agents

```bash
# Réindexer un agent spécifique
php artisan agent:reindex expert-btp

# Réindexer avec suppression de la collection
php artisan agent:reindex expert-btp --force
```

### Maintenance

```bash
# Purger les anciens logs (90 jours par défaut)
php artisan logs:purge

# Purger avec rétention personnalisée
php artisan logs:purge --days=30

# Mode dry-run (affiche sans supprimer)
php artisan logs:purge --dry-run

# Nettoyer tous les caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Base de données

```bash
# Exécuter les migrations
php artisan migrate

# Rollback de la dernière migration
php artisan migrate:rollback

# Réinitialiser la base (ATTENTION: supprime toutes les données)
php artisan migrate:fresh --seed

# Statut des migrations
php artisan migrate:status
```

---

## Workers et Queues

### Lancer les Workers

#### Via Docker Compose (recommandé)

Les workers sont automatiquement lancés via le service `worker` dans `docker-compose.yml` :

```bash
# Les workers démarrent automatiquement avec
docker compose up -d

# Voir les logs des workers
docker compose logs -f worker

# Redémarrer les workers
docker compose restart worker
```

#### Manuellement (développement)

```bash
# Worker standard
docker compose exec app php artisan queue:work

# Worker avec options
docker compose exec app php artisan queue:work --sleep=3 --tries=3 --max-time=3600

# Worker en arrière-plan
docker compose exec app php artisan queue:work --daemon
```

### Scheduler (Tâches planifiées)

Le scheduler est géré par le service `scheduler` dans Docker Compose :

```bash
# Voir les logs du scheduler
docker compose logs -f scheduler

# Exécuter manuellement le scheduler
docker compose exec app php artisan schedule:run

# Lister les tâches planifiées
docker compose exec app php artisan schedule:list
```

### Queues disponibles

| Queue | Usage |
|-------|-------|
| `default` | Tâches générales |
| `webhooks` | Envoi des webhooks partenaires |
| `indexing` | Indexation des documents dans Qdrant |

```bash
# Traiter une queue spécifique
docker compose exec app php artisan queue:work --queue=webhooks

# Traiter plusieurs queues (priorité)
docker compose exec app php artisan queue:work --queue=webhooks,indexing,default
```

### Supervision avec Horizon (optionnel)

```bash
# Lancer Horizon
docker compose exec app php artisan horizon

# Dashboard Horizon
# Accessible sur http://localhost:8080/horizon
```

---

## Commandes Make

```bash
# Développement
make dev          # Démarrer en mode développement
make stop         # Arrêter les services
make restart      # Redémarrer les services
make logs         # Voir les logs
make shell        # Ouvrir un shell dans le conteneur app

# Base de données
make migrate      # Exécuter les migrations
make seed         # Exécuter les seeders
make fresh        # Reset complet de la base

# Tests
make test         # Lancer les tests
make test-cov     # Tests avec couverture

# Qdrant
make qdrant-init  # Initialiser Qdrant

# Cache
make cache-clear  # Nettoyer les caches
make cache-warm   # Recompiler les caches

# Mise à jour
make update       # Mise à jour développement
make update-prod  # Mise à jour production
```

---

## Structure des Services Docker

```
┌─────────────────────────────────────────────────────────┐
│                    docker-compose.yml                    │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐    │
│  │   app   │  │   web   │  │   db    │  │  redis  │    │
│  │ PHP-FPM │  │  Caddy  │  │PostgreSQL│ │  Redis  │    │
│  └────┬────┘  └────┬────┘  └────┬────┘  └────┬────┘    │
│       │            │            │            │          │
│  ┌────┴────┐  ┌────┴────┐  ┌────┴────┐                 │
│  │ worker  │  │scheduler│  │ qdrant  │  ┌─────────┐    │
│  │  Queue  │  │  Cron   │  │ Vector  │  │ ollama  │    │
│  └─────────┘  └─────────┘  └─────────┘  │   LLM   │    │
│                                          └─────────┘    │
└─────────────────────────────────────────────────────────┘
```

---

## Environnement

### Variables importantes (.env)

```bash
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-domaine.com

# Base de données
DB_CONNECTION=pgsql
DB_HOST=db
DB_DATABASE=ai_manager
DB_USERNAME=ai_manager
DB_PASSWORD=secret_password

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=null

# Ollama
OLLAMA_HOST=ollama
OLLAMA_PORT=11434
OLLAMA_DEFAULT_MODEL=mistral:7b
OLLAMA_EMBEDDING_MODEL=nomic-embed-text

# Qdrant
QDRANT_HOST=qdrant
QDRANT_PORT=6333
QDRANT_VECTOR_SIZE=768
```

---

## Troubleshooting

### Qdrant reste "unhealthy"

Le conteneur Qdrant n'inclut pas `curl`. Le healthcheck utilise `wget` :

```bash
# Vérifier que le fix est appliqué
grep wget docker-compose.yml
# Doit afficher la ligne avec wget --spider
```

### Erreur GPU pour Ollama

Si vous voyez "could not select device driver nvidia" :

```bash
# Cette erreur signifie que le serveur n'a pas de GPU NVIDIA
# Le docker-compose.yml par défaut fonctionne en mode CPU
# Aucune action requise sauf si vous voulez utiliser un GPU
```

Pour activer le GPU sur un serveur équipé, voir `docs/05_deployment_guide.md`.

### Ports 80/443 déjà utilisés

Par défaut, l'application utilise les ports 8080/8443 :

```bash
# Vérifier .env
grep WEB_PORT .env

# Changer les ports si nécessaire
WEB_PORT=8080
WEB_SSL_PORT=8443
```

### Les workers ne traitent pas les jobs

```bash
# Vérifier les jobs en attente
docker compose exec app php artisan queue:monitor

# Voir les jobs échoués
docker compose exec app php artisan queue:failed

# Relancer les jobs échoués
docker compose exec app php artisan queue:retry all

# Vider la queue
docker compose exec app php artisan queue:clear
```

### Ollama ne répond pas

```bash
# Vérifier le statut
docker compose exec ollama ollama list

# Télécharger un modèle manuellement
docker compose exec ollama ollama pull mistral:7b

# Voir les logs
docker compose logs ollama
```

### Qdrant ne fonctionne pas

```bash
# Vérifier la santé
curl http://localhost:6333/health

# Réinitialiser les collections
docker compose exec app php artisan qdrant:init --force
```

### Erreurs de permissions

```bash
# Fixer les permissions storage
docker compose exec app chmod -R 775 storage bootstrap/cache
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
```

### Documentation Complète

Pour plus de détails sur le déploiement et la configuration, voir :
- `docs/05_deployment_guide.md` - Guide complet avec GPU, ports, firewall

---

## Licence

Propriétaire - Batirama Connect
