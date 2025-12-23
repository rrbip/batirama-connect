# Guide de Déploiement

> **Référence** : [00_index.md](./00_index.md)
> **Statut** : Mise à jour Décembre 2025

---

## Architecture de l'Application

AI-Manager CMS est un **backend API** pour les agents IA du secteur BTP. Il n'y a pas d'interface d'administration web (pas de panneau Filament).

### Points d'accès

| Endpoint | Description |
|----------|-------------|
| `/` | Page de statut JSON (santé de l'API) |
| `/api/health` | Health check pour les load balancers |
| `/api/v1/partners/*` | API partenaires (authentifiée) |
| `/api/c/{token}/*` | Chat public (via token) |

### Exemple de réponse sur `/`

```json
{
  "name": "AI-Manager CMS",
  "version": "1.0.0",
  "status": "running",
  "api": {
    "health": "/api/health",
    "documentation": "/api/docs"
  },
  "timestamp": "2025-12-23T14:00:00+00:00"
}
```

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
| PostgreSQL | 5432 | 5432 | `DB_EXTERNAL_PORT` |
| Qdrant | 6333 | 6333 | `QDRANT_EXTERNAL_PORT` |
| Ollama | 11434 | 11434 | `OLLAMA_EXTERNAL_PORT` |

---

## Intégration avec CWP (CentOS WebPanel)

### Architecture Recommandée

Avec CWP, l'architecture recommandée est :

```
┌─────────────────────────────────────────────────────────────┐
│                      SERVEUR CWP                            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   Internet                                                  │
│      │                                                      │
│      ▼                                                      │
│   ┌─────────────────────┐                                   │
│   │  Apache (CWP)       │ ◄── Ports 80/443                  │
│   │  + Let's Encrypt    │     SSL géré par CWP              │
│   └──────────┬──────────┘                                   │
│              │ ProxyPass                                    │
│              ▼                                              │
│   ┌─────────────────────┐                                   │
│   │  Docker (Caddy)     │ ◄── Port 8080                     │
│   │  + Laravel App      │     HTTP uniquement               │
│   └─────────────────────┘                                   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Avantages** :
- CWP gère automatiquement les certificats SSL (Let's Encrypt)
- La configuration survit aux mises à jour CWP
- Pas de conflit de ports

### Étape 1 : Créer le Domaine dans CWP

1. Connectez-vous au panneau CWP (port 2031)
2. Allez dans **Domains → Add Domain**
3. Ajoutez votre domaine (ex: `ai.bipinfopro.com`)
4. **Important** : Ne créez PAS de compte utilisateur, utilisez le compte root

### Étape 2 : Activer SSL avec AutoSSL

1. Dans CWP, allez dans **WebServer Settings → SSL Certificates**
2. Ou utilisez **AutoSSL** dans le menu de gauche
3. Sélectionnez votre domaine et activez Let's Encrypt
4. Attendez que le certificat soit généré (quelques minutes)

### Étape 3 : Configurer le Reverse Proxy

CWP permet d'ajouter des directives personnalisées qui **ne seront pas écrasées**.

**Méthode 1 : Via l'interface CWP (recommandé)**

1. Allez dans **WebServer Settings → Apache vHosts**
2. Trouvez votre domaine dans la liste
3. Cliquez sur **Edit** ou l'icône de configuration
4. Dans la section "Custom Directives" ou "Additional Apache Directives", ajoutez :

```apache
# Proxy vers Docker
ProxyPreserveHost On
ProxyPass / http://127.0.0.1:8080/
ProxyPassReverse / http://127.0.0.1:8080/

# Timeouts pour les requêtes IA longues
ProxyTimeout 300
```

5. Sauvegardez et redémarrez Apache

**Méthode 2 : Fichier include personnalisé**

Si l'interface ne permet pas d'ajouter des directives, créez un fichier include :

```bash
# Créer le répertoire pour les includes personnalisés
mkdir -p /usr/local/apache/conf/userdata/std/2_4/root/ai.bipinfopro.com

# Créer le fichier de configuration
cat > /usr/local/apache/conf/userdata/std/2_4/root/ai.bipinfopro.com/proxy.conf << 'EOF'
ProxyPreserveHost On
ProxyPass / http://127.0.0.1:8080/
ProxyPassReverse / http://127.0.0.1:8080/
ProxyTimeout 300
EOF

# Reconstruire les vhosts
/scripts/rebuildhttpdconf
# ou
cwp_restart_httpd
```

**Méthode 3 : Configuration via cwpcli**

```bash
# Si disponible
cwpcli domain proxy-add ai.bipinfopro.com 127.0.0.1:8080
```

### Étape 4 : Redémarrer les Services

```bash
# Redémarrer Apache
systemctl restart httpd

# Vérifier la configuration
httpd -t

# Vérifier que le proxy fonctionne
curl -I http://127.0.0.1:8080
curl -I https://ai.bipinfopro.com
```

### Vérification

```bash
# 1. Docker doit écouter sur 8080
docker compose ps
# aim_web doit montrer 0.0.0.0:8080->80/tcp

# 2. Apache doit avoir les modules proxy
httpd -M | grep proxy
# Doit afficher : proxy_module, proxy_http_module

# 3. Test direct du conteneur
curl http://127.0.0.1:8080

# 4. Test via le domaine
curl -I https://ai.bipinfopro.com
```

### Configuration Laravel pour le Proxy

Dans votre fichier `.env` Laravel, configurez les proxies de confiance :

```env
# Faire confiance au proxy Apache
TRUSTED_PROXIES=127.0.0.1
```

Le Caddyfile est déjà configuré avec `trusted_proxies private_ranges` pour transmettre correctement les headers.

### Notes Importantes

1. **Ne modifiez PAS directement** `/usr/local/apache/conf/httpd.conf` - CWP l'écrasera
2. **Utilisez les fichiers userdata** pour les configurations personnalisées
3. **Les certificats SSL** sont gérés par CWP/AutoSSL, pas par Caddy
4. **Le port 8080** doit être accessible uniquement en local (localhost)

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

L'image Qdrant minimale n'inclut ni `curl` ni `wget`. Le healthcheck est désactivé et l'application gère la connexion avec retry dans l'entrypoint.

```yaml
qdrant:
  # Pas de healthcheck - l'image n'a pas curl/wget
  # L'application utilise service_started au lieu de service_healthy
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

### Erreur : "curl: executable file not found" ou "wget: not found"

**Symptôme** : Le conteneur Qdrant reste "unhealthy"

**Cause** : L'image Qdrant minimale n'inclut ni curl ni wget

**Solution** : Le healthcheck a été supprimé du docker-compose.yml. L'application utilise `service_started` au lieu de `service_healthy` pour Qdrant.

```yaml
# docker-compose.yml - Configuration actuelle
qdrant:
  # Pas de healthcheck

app:
  depends_on:
    qdrant:
      condition: service_started  # Pas service_healthy
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

### Erreur : "No application encryption key has been specified"

**Symptôme** : Erreur 500 avec le message "No application encryption key"

**Cause** : La variable `APP_KEY` n'est pas définie ou mal formatée dans le fichier `.env`

**Solution** :

```bash
# Vérifier si APP_KEY existe
grep APP_KEY .env

# Supprimer les anciennes entrées APP_KEY (si plusieurs)
sed -i '/^APP_KEY/d' .env

# Générer et ajouter une nouvelle clé
NEW_KEY=$(docker compose exec -T app php artisan key:generate --show 2>/dev/null)
echo "APP_KEY=$NEW_KEY" >> .env

# Redémarrer les conteneurs
docker compose down && docker compose up -d
```

### Erreur : "Permission denied" sur storage/logs

**Symptôme** : Erreur 500 avec "Failed to open stream: Permission denied"

**Cause** : Les permissions du dossier `storage/` ne correspondent pas à l'utilisateur www-data du conteneur

**Solution** :

```bash
# Corriger les permissions manuellement
docker compose exec app chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
docker compose exec app chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
```

**Note** : L'entrypoint corrige automatiquement les permissions au démarrage, mais si vous montez un volume depuis l'hôte, les permissions peuvent être écrasées.

### Erreur : "Class not found" ou vendor manquant

**Symptôme** : Erreur PHP "Class 'XXX' not found" au démarrage

**Cause** : Le volume monté écrase le dossier `vendor/` créé lors du build Docker

**Solution** : L'entrypoint détecte automatiquement ce cas et lance `composer install`. Si le problème persiste :

```bash
# Installer manuellement les dépendances
docker compose exec app composer install

# Ou reconstruire complètement
docker compose down
docker compose build --no-cache app
docker compose up -d
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
- APP_KEY manquante

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
- [ ] Port 8080 accessible (localement si derrière CWP)
- [ ] Clone du repository
- [ ] Fichier `.env` créé depuis `.env.example`
- [ ] Variables configurées (mots de passe, APP_KEY)
- [ ] `./install.sh` exécuté avec succès
- [ ] Tous les conteneurs démarrés
- [ ] Accès à l'API via `curl http://localhost:8080` (retourne JSON)
- [ ] Health check OK via `curl http://localhost:8080/api/health`

### Configuration Production avec CWP

- [ ] `APP_ENV=production` dans `.env`
- [ ] `APP_DEBUG=false` dans `.env`
- [ ] Mot de passe DB sécurisé
- [ ] `WEBHOOK_SECRET` personnalisé
- [ ] Domaine créé dans CWP
- [ ] SSL activé via AutoSSL/Let's Encrypt dans CWP
- [ ] Proxy Apache configuré vers 127.0.0.1:8080
- [ ] `TRUSTED_PROXIES=127.0.0.1` dans `.env`
- [ ] DNS pointant vers le serveur
- [ ] Test HTTPS fonctionnel
- [ ] Backups configurés pour PostgreSQL

### Configuration Production (Serveur Dédié sans CWP)

- [ ] `APP_ENV=production` dans `.env`
- [ ] `APP_DEBUG=false` dans `.env`
- [ ] `WEB_PORT=80` dans `.env`
- [ ] `ACME_EMAIL=votre@email.com` dans `.env`
- [ ] DNS pointant vers le serveur
- [ ] Certificat SSL automatique via Caddy
- [ ] Backups configurés pour PostgreSQL

---

## Support

En cas de problème non résolu :

1. Vérifier les logs : `docker compose logs`
2. Consulter la documentation : `docs/`
3. Ouvrir une issue sur GitHub
