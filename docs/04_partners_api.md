# API Partenaires

> **Référence** : [00_index.md](./00_index.md)
> **Statut** : Spécifications validées

---

## Vue d'Ensemble

L'API Partenaires permet aux logiciels BTP externes (ZOOMBAT, EBP, Batigest, etc.) de :

1. **Créer des sessions IA** pour leurs clients
2. **Envoyer des liens** par SMS/Email via Brevo
3. **Récupérer les résultats** (pré-devis, résumé, pièces jointes)
4. **Notifier les conversions** pour le calcul des commissions

```
┌─────────────────────────────────────────────────────────────────┐
│                    BATIRAMA-CONNECT (AI-Manager)                │
│                         API Partenaires                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  POST /api/partners/sessions     → Créer session + envoyer lien│
│  GET  /api/partners/sessions/:id → Récupérer résultat          │
│  POST /api/partners/conversions  → Notifier conversion         │
│                                                                 │
└───────────┬─────────────┬─────────────┬─────────────┬──────────┘
            │             │             │             │
            ▼             ▼             ▼             ▼
       ┌────────┐    ┌────────┐    ┌────────┐    ┌────────┐
       │ZOOMBAT │    │  EBP   │    │Batigest│    │ Autres │
       └────────┘    └────────┘    └────────┘    └────────┘
```

---

## Authentification

Tous les endpoints nécessitent une clé API dans le header `Authorization`.

```http
Authorization: Bearer {partner_api_key}
```

**Format de la clé** : `{prefix}_{random_string}`
- Exemple ZOOMBAT : `zb_a1b2c3d4e5f6g7h8i9j0...`
- Exemple EBP : `ebp_x9y8z7w6v5u4t3s2r1q0...`

**Erreurs d'authentification** :

```json
// 401 Unauthorized
{
    "error": "invalid_api_key",
    "message": "Clé API invalide ou expirée"
}

// 403 Forbidden
{
    "error": "partner_suspended",
    "message": "Compte partenaire suspendu"
}
```

---

## Endpoints

### 1. Créer une Session IA

Crée une session IA et envoie optionnellement un lien au client.

```http
POST /api/partners/sessions
Authorization: Bearer {partner_api_key}
Content-Type: application/json
```

**Request Body :**

```json
{
    "external_ref": "DOSSIER-2024-001",
    "agent_slug": "expert-btp",

    "client": {
        "name": "M. Dupont",
        "phone": "+33612345678",
        "email": "dupont@email.com",
        "address": "12 rue des Lilas, 75020 Paris"
    },

    "send_via": "sms",
    "message_template": "default",

    "metadata": {
        "project_type": "renovation_sdb",
        "source": "demande_site_web"
    },

    "options": {
        "expires_in_hours": 168,
        "max_uses": 1,
        "is_marketplace_lead": false
    }
}
```

**Paramètres :**

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `external_ref` | string | Oui | ID du dossier dans le logiciel partenaire |
| `agent_slug` | string | Non | Agent IA à utiliser (défaut: config partenaire) |
| `client.name` | string | Non | Nom du client |
| `client.phone` | string | Conditionnel | Téléphone (requis si `send_via` inclut "sms") |
| `client.email` | string | Conditionnel | Email (requis si `send_via` inclut "email") |
| `client.address` | string | Non | Adresse du chantier |
| `send_via` | string | Non | "sms", "email", "both", ou "none" (défaut: "none") |
| `message_template` | string | Non | Template de message ("default", "custom") |
| `metadata` | object | Non | Métadonnées libres |
| `options.expires_in_hours` | int | Non | Durée de validité du lien (défaut: 168 = 7j) |
| `options.max_uses` | int | Non | Utilisations max (défaut: 1) |
| `options.is_marketplace_lead` | bool | Non | Si true, commission applicable |

**Response (201 Created) :**

```json
{
    "success": true,
    "data": {
        "session_id": "sess_abc123xyz789",
        "public_url": "https://batirama.com/c/abc123xyz789",
        "token": "abc123xyz789",

        "expires_at": "2024-01-22T10:30:00Z",
        "max_uses": 1,

        "notification": {
            "sent": true,
            "channel": "sms",
            "recipient": "+33612345678",
            "sent_at": "2024-01-15T10:30:05Z"
        }
    }
}
```

**Erreurs possibles :**

```json
// 400 Bad Request
{
    "error": "validation_error",
    "message": "Numéro de téléphone invalide",
    "field": "client.phone"
}

// 404 Not Found
{
    "error": "agent_not_found",
    "message": "Agent 'expert-plomberie' non trouvé"
}

// 429 Too Many Requests
{
    "error": "rate_limited",
    "message": "Limite de requêtes atteinte",
    "retry_after": 60
}
```

---

### 2. Récupérer le Résultat d'une Session

Récupère le résultat de la conversation IA (pré-devis, résumé, pièces jointes).

```http
GET /api/partners/sessions/{session_id}
Authorization: Bearer {partner_api_key}
```

**Response selon le niveau d'accès du partenaire :**

#### Niveau `summary` (défaut pour partenaires externes)

```json
{
    "success": true,
    "data": {
        "session_id": "sess_abc123xyz789",
        "external_ref": "DOSSIER-2024-001",
        "status": "completed",

        "result": {
            "project_name": "Rénovation salle de bain",

            "summary": "Rénovation complète SDB 8m². Client souhaite douche italienne 120x90, meuble double vasque, WC suspendu. Gamme milieu de gamme. Évacuation existante à déplacer. Délai souhaité : mars 2025.",

            "quote": {
                "estimated_total": 8500.00,
                "currency": "EUR",
                "lines": [
                    {
                        "designation": "Dépose sanitaires existants",
                        "description": "Dépose baignoire, lavabo, WC et évacuation",
                        "unit": "ens",
                        "quantity": 1,
                        "unit_price": 350.00,
                        "total": 350.00,
                        "ouvrage_code": "DEM-SAN-001"
                    },
                    {
                        "designation": "Création douche italienne 120x90",
                        "description": "Receveur extra-plat, paroi vitrée, colonne de douche",
                        "unit": "ens",
                        "quantity": 1,
                        "unit_price": 2800.00,
                        "total": 2800.00,
                        "ouvrage_code": "PLB-DOU-003"
                    },
                    {
                        "designation": "Meuble double vasque 120cm",
                        "description": "Meuble suspendu avec plan vasque et miroir",
                        "unit": "ens",
                        "quantity": 1,
                        "unit_price": 1200.00,
                        "total": 1200.00,
                        "ouvrage_code": "PLB-MEU-002"
                    }
                ],
                "notes": "Prix indicatifs. Visite technique recommandée pour confirmation."
            },

            "attachments": [
                {
                    "id": "att_001",
                    "type": "image",
                    "name": "photo_sdb_actuelle.jpg",
                    "url": "https://cdn.batirama.com/sessions/abc123/photo1.jpg",
                    "mime_type": "image/jpeg",
                    "size": 245000
                },
                {
                    "id": "att_002",
                    "type": "image",
                    "name": "photo_angle.jpg",
                    "url": "https://cdn.batirama.com/sessions/abc123/photo2.jpg",
                    "mime_type": "image/jpeg",
                    "size": 312000
                }
            ],

            "client": {
                "name": "M. Dupont",
                "phone": "+33612345678",
                "email": "dupont@email.com",
                "address": "12 rue des Lilas, 75020 Paris"
            }
        },

        "created_at": "2024-01-15T10:30:00Z",
        "completed_at": "2024-01-15T10:45:00Z"
    }
}
```

#### Niveau `full` (ZOOMBAT - accès complet)

Inclut tout le niveau `summary` plus :

```json
{
    "success": true,
    "data": {
        "session_id": "sess_abc123xyz789",
        "external_ref": "DOSSIER-2024-001",
        "status": "completed",

        "result": {
            // ... même contenu que summary ...
        },

        "conversation": [
            {
                "role": "assistant",
                "content": "Bonjour ! Je suis l'assistant BTP de Batirama. Je vais vous aider à définir votre projet de rénovation. Pouvez-vous me décrire les travaux que vous souhaitez réaliser ?",
                "timestamp": "2024-01-15T10:30:00Z"
            },
            {
                "role": "user",
                "content": "Je voudrais refaire ma salle de bain complètement",
                "timestamp": "2024-01-15T10:31:00Z"
            },
            {
                "role": "assistant",
                "content": "Parfait ! Pour vous proposer un devis adapté, j'ai besoin de quelques informations :\n\n1. Quelles sont les dimensions de votre salle de bain ?\n2. Quels équipements souhaitez-vous (douche, baignoire, double vasque...) ?\n3. Quel niveau de gamme visez-vous (standard, milieu de gamme, haut de gamme) ?",
                "timestamp": "2024-01-15T10:31:05Z"
            },
            {
                "role": "user",
                "content": "Elle fait environ 8m². Je voudrais une douche italienne, un meuble double vasque et un WC suspendu. Milieu de gamme.",
                "timestamp": "2024-01-15T10:32:00Z",
                "attachments": ["att_001", "att_002"]
            }
            // ... suite de la conversation ...
        ],

        "metadata": {
            "duration_seconds": 900,
            "messages_count": 12,
            "model_used": "mistral:7b",
            "tokens_total": 4500,
            "rag_queries": [
                "douche italienne 120x90",
                "meuble double vasque suspendu",
                "WC suspendu"
            ]
        },

        "created_at": "2024-01-15T10:30:00Z",
        "completed_at": "2024-01-15T10:45:00Z"
    }
}
```

**Statuts possibles :**

| Statut | Description |
|--------|-------------|
| `pending` | Lien créé, client n'a pas encore accédé |
| `in_progress` | Conversation en cours |
| `completed` | Conversation terminée, résultat disponible |
| `expired` | Lien expiré sans utilisation |
| `cancelled` | Session annulée |

**Erreurs possibles :**

```json
// 404 Not Found
{
    "error": "session_not_found",
    "message": "Session 'sess_xxx' non trouvée"
}

// 403 Forbidden
{
    "error": "access_denied",
    "message": "Cette session appartient à un autre partenaire"
}
```

---

### 3. Notifier une Conversion

Appelé par le partenaire quand un devis est validé/signé dans son logiciel.

```http
POST /api/partners/conversions
Authorization: Bearer {partner_api_key}
Content-Type: application/json
```

**Request Body :**

```json
{
    "session_id": "sess_abc123xyz789",
    "status": "accepted",
    "final_amount": 8750.00,
    "quote_ref": "DEVIS-ZB-2024-456",
    "signed_at": "2024-01-20T14:30:00Z",
    "notes": "Travaux prévus début mars"
}
```

**Paramètres :**

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `session_id` | string | Oui | ID de la session AI-Manager |
| `status` | string | Oui | "quoted", "accepted", "rejected", "completed" |
| `final_amount` | decimal | Conditionnel | Montant final (requis si accepted/completed) |
| `quote_ref` | string | Non | Référence du devis dans le logiciel partenaire |
| `signed_at` | datetime | Non | Date de signature |
| `notes` | string | Non | Notes libres |

**Statuts de conversion :**

| Statut | Description | Commission |
|--------|-------------|------------|
| `quoted` | Devis envoyé au client | Non |
| `accepted` | Devis accepté/signé | Calculée |
| `rejected` | Devis refusé | Non |
| `completed` | Travaux terminés | Confirmée |

**Response (200 OK) :**

```json
{
    "success": true,
    "data": {
        "session_id": "sess_abc123xyz789",
        "conversion_status": "accepted",
        "final_amount": 8750.00,

        "commission": {
            "applicable": true,
            "rate": 5.0,
            "amount": 437.50,
            "status": "pending"
        }
    }
}
```

**Si pas de commission applicable (scénario 1 - artisan envoie lien) :**

```json
{
    "success": true,
    "data": {
        "session_id": "sess_abc123xyz789",
        "conversion_status": "accepted",
        "final_amount": 8750.00,

        "commission": {
            "applicable": false,
            "reason": "Session créée par artisan (non marketplace)"
        }
    }
}
```

---

## Webhooks (Callbacks vers le Partenaire)

AI-Manager peut notifier le partenaire quand certains événements se produisent.

### Configuration

Le webhook est configuré dans la table `partners` via le champ `webhook_url`.

### Événements

| Événement | Description | Déclencheur |
|-----------|-------------|-------------|
| `session.completed` | Conversation terminée | L'IA a généré un pré-devis |
| `session.expired` | Lien expiré | Délai dépassé sans utilisation |

### Format du Webhook

```http
POST {partner_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256={signature}
X-Webhook-Event: session.completed
```

**Body :**

```json
{
    "event": "session.completed",
    "timestamp": "2024-01-15T10:45:00Z",

    "data": {
        "session_id": "sess_abc123xyz789",
        "external_ref": "DOSSIER-2024-001",
        "status": "completed",

        "result": {
            "project_name": "Rénovation salle de bain",
            "estimated_total": 8500.00,
            "has_attachments": true,
            "attachments_count": 2
        }
    }
}
```

### Vérification de Signature

```php
// Vérifier la signature du webhook
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'];

$expected = 'sha256=' . hash_hmac('sha256', $payload, $webhook_secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

### Retry Policy

- 3 tentatives en cas d'échec
- Délais : 1min, 5min, 30min
- Timeout : 30 secondes
- Codes de succès : 2xx

---

## Scénarios d'Utilisation

### Scénario 1 : Artisan envoie un lien à son client

```
1. Artisan dans ZOOMBAT clique "Demander devis IA"

2. ZOOMBAT → POST /api/partners/sessions
   {
       "external_ref": "CLIENT-2024-042",
       "agent_slug": "expert-btp",
       "client": {"phone": "+33612345678"},
       "send_via": "sms",
       "options": {"is_marketplace_lead": false}
   }

3. Client reçoit SMS avec lien

4. Client discute avec l'IA, envoie photos

5. AI-Manager → Webhook session.completed → ZOOMBAT

6. ZOOMBAT → GET /api/partners/sessions/{session_id}
   → Récupère pré-devis + photos + résumé

7. Artisan ajuste et envoie devis final au client

8. Client signe → ZOOMBAT → POST /api/partners/conversions
   {
       "session_id": "sess_xxx",
       "status": "accepted",
       "final_amount": 8750.00
   }
   → Pas de commission (is_marketplace_lead: false)
```

### Scénario 2 : Lead Marketplace avec commission

```
1. Client accède à l'IA publique sur batirama.com

2. IA qualifie le projet et génère pré-devis

3. AI-Manager recherche artisan disponible
   - Compétences ✓
   - Proximité ✓
   - Disponibilité ✓

4. AI-Manager → POST interne vers ZOOMBAT de l'artisan
   {
       "external_ref": "LEAD-2024-0123",
       "options": {"is_marketplace_lead": true}
   }

5. Artisan reçoit notification dans ZOOMBAT

6. Artisan finalise devis, client signe

7. ZOOMBAT → POST /api/partners/conversions
   {
       "session_id": "sess_xxx",
       "status": "accepted",
       "final_amount": 8750.00
   }
   → Commission 5% = 437.50€
```

---

## Limites et Quotas

| Limite | Valeur | Description |
|--------|--------|-------------|
| Rate limit | 100/min | Requêtes par minute par partenaire |
| Sessions/jour | 1000 | Nouvelles sessions par jour |
| Taille pièces jointes | 10 MB | Par fichier |
| Rétention sessions | 90 jours | Données conservées |

---

## Codes d'Erreur

| Code | HTTP | Description |
|------|------|-------------|
| `invalid_api_key` | 401 | Clé API invalide |
| `partner_suspended` | 403 | Compte suspendu |
| `access_denied` | 403 | Accès non autorisé à cette ressource |
| `validation_error` | 400 | Erreur de validation des données |
| `session_not_found` | 404 | Session non trouvée |
| `agent_not_found` | 404 | Agent IA non trouvé |
| `rate_limited` | 429 | Limite de requêtes atteinte |
| `server_error` | 500 | Erreur interne |

---

## SDK et Exemples

### PHP (Laravel)

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BatiramaConnectClient
{
    private string $baseUrl = 'https://api.batirama.com';
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function createSession(array $data): array
    {
        $response = Http::withToken($this->apiKey)
            ->post("{$this->baseUrl}/api/partners/sessions", $data);

        return $response->json();
    }

    public function getSession(string $sessionId): array
    {
        $response = Http::withToken($this->apiKey)
            ->get("{$this->baseUrl}/api/partners/sessions/{$sessionId}");

        return $response->json();
    }

    public function notifyConversion(string $sessionId, string $status, float $amount): array
    {
        $response = Http::withToken($this->apiKey)
            ->post("{$this->baseUrl}/api/partners/conversions", [
                'session_id' => $sessionId,
                'status' => $status,
                'final_amount' => $amount,
            ]);

        return $response->json();
    }
}

// Utilisation
$client = new BatiramaConnectClient(config('services.batirama.api_key'));

// Créer une session et envoyer un SMS
$result = $client->createSession([
    'external_ref' => 'DOSSIER-2024-001',
    'client' => [
        'name' => 'M. Dupont',
        'phone' => '+33612345678',
    ],
    'send_via' => 'sms',
]);

$sessionId = $result['data']['session_id'];

// Plus tard, récupérer le résultat
$session = $client->getSession($sessionId);

if ($session['data']['status'] === 'completed') {
    $quote = $session['data']['result']['quote'];
    // Importer le pré-devis dans ZOOMBAT
}
```

### JavaScript (Node.js)

```javascript
const axios = require('axios');

class BatiramaConnectClient {
    constructor(apiKey) {
        this.client = axios.create({
            baseURL: 'https://api.batirama.com',
            headers: {
                'Authorization': `Bearer ${apiKey}`,
                'Content-Type': 'application/json'
            }
        });
    }

    async createSession(data) {
        const response = await this.client.post('/api/partners/sessions', data);
        return response.data;
    }

    async getSession(sessionId) {
        const response = await this.client.get(`/api/partners/sessions/${sessionId}`);
        return response.data;
    }

    async notifyConversion(sessionId, status, amount) {
        const response = await this.client.post('/api/partners/conversions', {
            session_id: sessionId,
            status: status,
            final_amount: amount
        });
        return response.data;
    }
}

// Utilisation
const client = new BatiramaConnectClient(process.env.BATIRAMA_API_KEY);

async function example() {
    // Créer session
    const result = await client.createSession({
        external_ref: 'DOSSIER-2024-001',
        client: { phone: '+33612345678' },
        send_via: 'sms'
    });

    console.log('Lien envoyé:', result.data.public_url);
}
```

---

## Changelog

| Version | Date | Changements |
|---------|------|-------------|
| 1.0.0 | 2025-01 | Version initiale |
