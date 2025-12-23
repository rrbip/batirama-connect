# Guide de Déploiement

> **Référence** : [00_index.md](./00_index.md)
> **Statut** : Mise à jour Décembre 2025

---

## Prérequis

### Matériel Minimum

| Composant | Minimum | Recommandé |
|-----------|---------|------------|
| RAM | 8 Go | 16 Go+ |
| CPU | 2 cores | 4 cores+ |
| Disque | 20 Go | 50 Go+ |
| GPU | Non requis | NVIDIA (optionnel) |

### Logiciels

- Docker Engine 24+
- Docker Compose v2+
- Git

---

## Configuration des Ports

### Serveurs avec Panneau Web (CWP, cPanel, Plesk)

Par défaut, AI-Manager utilise les ports **8080** (HTTP) et **8443** (HTTPS) pour éviter les conflits avec les serveurs web existants (Apache, Nginx).

```bash
# .env - Configuration par défaut
WEB_PORT=8080
WEB_SSL_PORT=8443
```

### Serveurs Dédiés (Ports 80/443 libres)

Si vous avez un serveur dédié sans autre serveur web :

```bash
# .env - Utiliser les ports standards
WEB_PORT=80
WEB_SSL_PORT=443
```

### Tableau des Ports Utilisés

| Service | Port Interne | Port Externe (défaut) | Variable |
|---------|--------------|----------------------|----------|
| Web HTTP | 80 | 8080 | `WEB_PORT` |
| Web HTTPS | 443 | 8443 | `WEB_SSL_PORT` |
| PostgreSQL | 5432 | 5432 | `DB_EXTERNAL_PORT` |
| Qdrant | 6333 | 6333 | `QDRANT_EXTERNAL_PORT` |
| Ollama | 11434 | 11434 | `OLLAMA_EXTERNAL_PORT` |

---

## Configuration GPU pour Ollama

### Mode CPU (par défaut)

Le fichier `docker-compose.yml` utilise Ollama en mode CPU par défaut. Cela fonctionne sur tous les serveurs.

```yaml
# docker-compose.yml - Mode CPU (par défaut)
ollama:
  image: ollama/ollama:${OLLAMA_VERSION:-latest}
  container_name: aim_ollama
  # Pas de configuration GPU
```

### Mode GPU NVIDIA

Pour les serveurs avec carte graphique NVIDIA, créez ou utilisez `docker-compose.gpu.yml` :

```yaml
# docker-compose.gpu.yml
services:
  ollama:
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
```

**Lancement avec GPU :**

```bash
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d
```

### Prérequis GPU

1. **Driver NVIDIA** installé sur le serveur :
   ```bash
   nvidia-smi  # Doit afficher les infos GPU
   ```

2. **NVIDIA Container Toolkit** :
   ```bash
   # CentOS/RHEL
   distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
   curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.repo | sudo tee /etc/yum.repos.d/nvidia-docker.repo
   sudo yum install -y nvidia-container-toolkit
   sudo systemctl restart docker

   # Ubuntu/Debian
   distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
   curl -s -L https://nvidia.github.io/nvidia-docker/gpgkey | sudo apt-key add -
   curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.list | sudo tee /etc/apt/sources.list.d/nvidia-docker.list
   sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit
   sudo systemctl restart docker
   ```

3. **Vérification** :
   ```bash
   docker run --rm --gpus all nvidia/cuda:11.0-base nvidia-smi
   ```

### Choix du Modèle selon le GPU

| GPU | VRAM | Modèle Recommandé |
|-----|------|-------------------|
| RTX 3060 | 12 Go | mistral:7b |
| RTX 3080 | 10 Go | mistral:7b |
| RTX 3090 | 24 Go | mistral-small (24B) |
| RTX 4090 | 24 Go | mistral-small (24B) |
| A10G | 24 Go | mistral-small (24B) |
| A100 | 40/80 Go | llama3.3:70b |

---

## Healthchecks Docker

### Qdrant

L'image Qdrant n'inclut pas `curl`. Le healthcheck utilise `wget` :

```yaml
qdrant:
  healthcheck:
    # Note: wget est utilisé car curl n'est pas disponible dans l'image Qdrant
    test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost:6333/readyz"]
    interval: 10s
    timeout: 5s
    retries: 5
```

### PostgreSQL

```yaml
db:
  healthcheck:
    test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-postgres} -d ${DB_DATABASE:-ai_manager}"]
    interval: 10s
    timeout: 5s
    retries: 5
```

### Application PHP

```yaml
app:
  healthcheck:
    test: ["CMD", "php-fpm", "-t"]
    interval: 30s
    timeout: 10s
    retries: 3
```

---

## Troubleshooting

### Erreur : "curl: executable file not found"

**Symptôme** : Le conteneur Qdrant reste "unhealthy"

**Cause** : L'image Qdrant n'inclut pas curl

**Solution** : Le healthcheck utilise maintenant wget (fix appliqué dans docker-compose.yml)

```bash
# Vérifier que le fix est appliqué
grep -A3 "healthcheck" docker-compose.yml | grep wget
```

### Erreur : "could not select device driver nvidia"

**Symptôme** : Ollama ne démarre pas avec erreur GPU

**Cause** : Le serveur n'a pas de GPU NVIDIA ou le driver n'est pas installé

**Solution** : Utiliser le mode CPU (configuration par défaut)

```bash
# Vérifier le docker-compose.yml
# La section ollama ne doit PAS avoir de section "deploy.resources.reservations.devices"
```

### Erreur : "bootstrap/cache directory must be present"

**Symptôme** : Échec lors de `composer install`

**Cause** : Les répertoires Laravel n'existent pas

**Solution** : Le Dockerfile crée maintenant ces répertoires automatiquement :

```dockerfile
RUN mkdir -p bootstrap/cache \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/framework/cache \
    && mkdir -p storage/logs \
    && mkdir -p resources/views
```

### Erreur : "resources/views directory does not exist"

**Symptôme** : Échec lors de `php artisan view:cache`

**Cause** : Le répertoire views n'existe pas

**Solution** : Le Dockerfile inclut maintenant `mkdir -p resources/views`

### Erreur : Port 80/443 déjà utilisé

**Symptôme** : Le conteneur web ne démarre pas

**Cause** : Apache/Nginx/CWP utilise déjà ces ports

**Solution** : Utiliser les ports par défaut 8080/8443

```bash
# Vérifier .env
grep WEB_PORT .env
# Doit afficher : WEB_PORT=8080
```

### Les conteneurs redémarrent en boucle

**Vérifier les logs** :

```bash
docker compose logs app
docker compose logs db
docker compose logs qdrant
```

**Causes courantes** :
- Base de données non initialisée
- Permissions incorrectes sur storage/
- Variables d'environnement manquantes

**Solution** :

```bash
# Réinitialisation complète
docker compose down -v  # Attention: supprime les données
docker compose up -d
```

### Ollama ne télécharge pas les modèles

**Vérifier la connexion** :

```bash
docker compose exec ollama curl -s http://localhost:11434/api/tags
```

**Télécharger manuellement** :

```bash
docker compose exec ollama ollama pull mistral:7b
docker compose exec ollama ollama pull nomic-embed-text
```

---

## Firewall

### iptables (CentOS sans firewalld)

```bash
# Ouvrir le port 8080
iptables -A INPUT -p tcp --dport 8080 -j ACCEPT
iptables -A INPUT -p tcp --dport 8443 -j ACCEPT

# Sauvegarder
service iptables save
```

### firewalld (CentOS/RHEL)

```bash
firewall-cmd --permanent --add-port=8080/tcp
firewall-cmd --permanent --add-port=8443/tcp
firewall-cmd --reload
```

### ufw (Ubuntu/Debian)

```bash
ufw allow 8080/tcp
ufw allow 8443/tcp
```

---

## Mise à Jour du Projet

### Depuis une branche de développement

```bash
cd ~/batirama-connect

# Arrêter les services
docker compose down

# Récupérer les mises à jour
git pull origin <branche>

# Reconstruire et relancer
docker compose up -d --build

# Exécuter les migrations
docker compose exec app php artisan migrate --force
```

### Depuis la branche principale

```bash
make update  # ou make update-prod pour la production
```

---

## Checklist de Déploiement

### Première Installation

- [ ] Docker et Docker Compose installés
- [ ] Git installé
- [ ] Ports 8080/8443 ouverts dans le firewall
- [ ] Clone du repository
- [ ] Fichier `.env` créé depuis `.env.example`
- [ ] Variables configurées (domaine, email, mots de passe)
- [ ] `./install.sh` exécuté avec succès
- [ ] Tous les conteneurs "healthy"
- [ ] Accès à l'application via navigateur

### Configuration Production

- [ ] `APP_ENV=production` dans `.env`
- [ ] `APP_DEBUG=false` dans `.env`
- [ ] Mot de passe DB sécurisé
- [ ] `WEBHOOK_SECRET` personnalisé
- [ ] Domaine configuré dans Caddyfile
- [ ] `local_certs` supprimé du Caddyfile (pour Let's Encrypt)
- [ ] DNS pointant vers le serveur
- [ ] Certificat SSL actif (Let's Encrypt)
- [ ] Backups configurés pour PostgreSQL

---

## Support

En cas de problème non résolu :

1. Vérifier les logs : `docker compose logs`
2. Consulter la documentation : `docs/`
3. Ouvrir une issue sur GitHub
