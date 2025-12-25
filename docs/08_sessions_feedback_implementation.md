# Cahier des charges : Gestion des Sessions et Feedback IA

## Vue d'ensemble

Ce document détaille l'implémentation des fonctionnalités de gestion des sessions de conversation IA et du système de validation/correction des réponses dans l'interface d'administration Filament.

**Objectifs :**
- Permettre aux administrateurs de consulter l'historique des conversations
- Valider, corriger ou rejeter les réponses de l'IA
- Alimenter l'apprentissage continu via les corrections

---

## 0. Architecture du système d'apprentissage

### Comment les réponses apprises sont utilisées

Les réponses corrigées et validées (`learned_responses`) sont utilisées comme **contexte enrichi** pour le LLM, pas comme remplacement direct.

**Flow de génération de réponse :**

```
┌─────────────────────────────────────────────────────────────────┐
│                    QUESTION UTILISATEUR                          │
│            "Comment envoyer ma facture par email ?"              │
└───────────────────────────┬─────────────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│               RECHERCHE VECTORIELLE QDRANT                       │
│                                                                 │
│  1. Collection: learned_responses  →  Cas similaires traités    │
│  2. Collection: agent_*_docs       →  Documents indexés         │
└───────────────────────────┬─────────────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│               CONSTRUCTION DU PROMPT                             │
│                                                                 │
│  [SYSTEM PROMPT]                                                │
│  Tu es Support Client pour ZOOMBAT...                           │
│                                                                 │
│  [CAS SIMILAIRES TRAITÉS]  ← Learned Responses                  │
│  ### Cas 1 (similarité: 87%)                                    │
│  Q: Comment envoyer une facture ?                               │
│  Réponse validée: Pour envoyer une facture...                   │
│                                                                 │
│  [CONTEXTE DOCUMENTAIRE]  ← Documents RAG                       │
│  ### Source 1 (pertinence: 85%)                                 │
│  Guide: Pour envoyer un document...                             │
│                                                                 │
│  [HISTORIQUE SESSION]                                           │
│  Utilisateur: Bonjour                                           │
│  Assistant: Bonjour, comment puis-je vous aider ?               │
│                                                                 │
└───────────────────────────┬─────────────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                         LLM (Ollama)                             │
│                                                                 │
│  Le LLM génère une réponse adaptée au contexte actuel           │
│  en s'inspirant des cas similaires et documents                 │
└───────────────────────────┬─────────────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    RÉPONSE CONTEXTUALISÉE                        │
│                                                                 │
│  "Bonjour M. Dupont, pour envoyer votre facture par email..."   │
│  (Adaptée au contexte actuel, pas copiée verbatim)              │
└─────────────────────────────────────────────────────────────────┘
```

**Configuration (config/ai.php) :**

```php
'rag' => [
    'max_results' => 5,                    // Documents RAG max
    'min_score' => 0.6,                    // Score min documents
    'max_learned_responses' => 3,          // Cas similaires max
    'learned_min_score' => 0.75,           // Score min cas similaires
],
```

---

## 1. AiSessionResource - Liste des Sessions

### 1.1 Vue Liste

**Colonnes affichées :**

| Colonne | Type | Description |
|---------|------|-------------|
| ID | Badge | UUID court (8 premiers caractères) |
| Agent | Badge couleur | Nom de l'agent avec icône |
| Utilisateur | Text | Nom utilisateur ou "Visiteur" si public |
| Source | Badge | `admin_test`, `api`, `public_link`, `partner` |
| Messages | Numeric | Nombre de messages dans la session |
| Statut | Badge | `active` (vert), `archived` (gris), `deleted` (rouge) |
| Créé le | DateTime | Date et heure de création |
| Dernière activité | DateTime | Date du dernier message |

**Filtres :**
- Par agent (select)
- Par statut (select : active, archived, deleted)
- Par source (select)
- Par période (date range)
- Sessions avec feedbacks négatifs uniquement (toggle)
- Sessions avec messages non validés (toggle)

**Actions en masse :**
- Archiver les sessions sélectionnées
- Exporter en CSV

**Actions ligne :**
- Voir la conversation
- Archiver / Restaurer

### 1.2 Vue Détail - Conversation

**Layout en 2 colonnes :**

```
┌─────────────────────────────────────┬──────────────────────────┐
│                                     │                          │
│         Fil de conversation         │    Informations          │
│                                     │                          │
│  ┌─────────────────────────────┐   │  Session                 │
│  │ 👤 Question utilisateur      │   │  - UUID: abc123...       │
│  │ "Comment poser du carrelage" │   │  - Agent: Support Client │
│  │                   14:32      │   │  - Créé: 23/12/2025      │
│  └─────────────────────────────┘   │  - Messages: 4           │
│                                     │  - Statut: active        │
│  ┌─────────────────────────────┐   │                          │
│  │ 🤖 Réponse IA                │   │  Utilisateur             │
│  │ "Pour poser du carrelage..." │   │  - Nom: Jean Dupont      │
│  │                              │   │  - Email: jean@...       │
│  │ Sources: 3 documents         │   │                          │
│  │ mistral:7b | 245 tok | 1.2s  │   │  Métriques               │
│  │                              │   │  - Tokens totaux: 1,234  │
│  │ [✓ Valider] [✏️ Corriger]    │   │  - Temps moyen: 1.8s     │
│  │ [✗ Rejeter]                  │   │  - Satisfaction: 4.2/5   │
│  │                   14:33      │   │                          │
│  └─────────────────────────────┘   │                          │
│                                     │                          │
│  ... autres messages ...            │                          │
│                                     │                          │
└─────────────────────────────────────┴──────────────────────────┘
```

**Éléments du fil de conversation :**

Pour chaque message utilisateur :
- Contenu du message
- Timestamp
- Pièces jointes éventuelles

Pour chaque réponse IA :
- Contenu de la réponse (Markdown rendu)
- Badge de statut de validation (`pending`, `validated`, `learned`, `rejected`)
- Métriques : tokens, temps de génération, modèle + badge "fallback" si modèle de secours utilisé
- Boutons d'action (si statut = `pending`)
- Feedback utilisateur s'il existe (rating, commentaire)
- **Bouton "Voir le contexte envoyé à l'IA"** : ouvre une modale avec la question, la réponse, les sources RAG, l'historique, et un rapport copiable pour analyse

---

## 2. Interface de Validation/Correction

### 2.1 Page dédiée : Réponses à valider

**Accès :** Menu latéral > "Validation IA" (visible pour rôles `validator`, `admin`, `super-admin`)

**Layout :**

```
┌─────────────────────────────────────────────────────────────────┐
│  Réponses à valider                           [Stats: 23 en attente]
├─────────────────────────────────────────────────────────────────┤
│  Filtres: [Agent ▼] [Période ▼] [Avec feedback négatif ☐]       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │ Agent: Support Client                        23/12 14:32  │ │
│  │                                                           │ │
│  │ Question:                                                 │ │
│  │ "Comment calculer la quantité de carrelage nécessaire     │ │
│  │  pour une pièce de 15m² ?"                                │ │
│  │                                                           │ │
│  │ Réponse IA:                                               │ │
│  │ "Pour calculer la quantité de carrelage, il faut..."      │ │
│  │                                                           │ │
│  │ Sources utilisées:                                        │ │
│  │ • Guide pose carrelage (score: 0.87)                      │ │
│  │ • FAQ carrelage (score: 0.72)                             │ │
│  │                                                           │ │
│  │ Feedback utilisateur: 👎 "La réponse ne précise pas..."   │ │
│  │                                                           │ │
│  │ ┌─────────────────────────────────────────────────────┐   │ │
│  │ │  [✓ Valider]  [✏️ Corriger]  [✗ Rejeter]           │   │ │
│  │ └─────────────────────────────────────────────────────┘   │ │
│  └───────────────────────────────────────────────────────────┘ │
│                                                                 │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │ ... carte suivante ...                                    │ │
│  └───────────────────────────────────────────────────────────┘ │
│                                                                 │
│                    [Charger plus]                               │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Modal de Correction

Quand l'utilisateur clique sur "Corriger" :

```
┌─────────────────────────────────────────────────────────────────┐
│  Corriger et apprendre                                    [X]  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Question originale:                                            │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ "Comment calculer la quantité de carrelage nécessaire   │   │
│  │  pour une pièce de 15m² ?"                              │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Réponse originale de l'IA:                                     │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ "Pour calculer la quantité de carrelage, il faut..."    │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Réponse corrigée: *                                            │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ [Éditeur Markdown avec la réponse pré-remplie]          │   │
│  │                                                         │   │
│  │ Pour une pièce de 15m², voici le calcul :               │   │
│  │ 1. Surface de la pièce : 15m²                           │   │
│  │ 2. Ajouter 10% de marge pour les coupes : 15 × 1.10     │   │
│  │ 3. Surface totale nécessaire : 16.5m²                   │   │
│  │ ...                                                     │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ℹ️ Cette correction sera indexée et utilisée comme exemple     │
│     pour les futures questions similaires.                      │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │           [Annuler]        [Enregistrer et apprendre]   │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 2.3 Actions et Workflow

**Valider (✓)** :
```php
LearningService::validate($message, auth()->id())
```
- Change `validation_status` → `validated`
- Enregistre `validated_by` et `validated_at`
- Pas d'indexation Qdrant
- Notification de succès

**Corriger et apprendre (✏️)** :
```php
LearningService::learn($message, $correctedContent, auth()->id())
```
- Change `validation_status` → `learned`
- Stocke `corrected_content`
- Génère embedding de la question
- Indexe dans collection Qdrant `learned_responses`
- Notification de succès avec lien vers la réponse apprise

**Rejeter (✗)** :
```php
LearningService::reject($message, auth()->id(), $reason)
```
- Ouvre une modale pour saisir la raison (optionnel)
- Change `validation_status` → `rejected`
- Pas d'indexation
- Notification de succès

### 2.4 Contexte sauvegardé pour validation

Chaque réponse IA sauvegarde le **contexte complet** utilisé pour générer la réponse. Cela permet au validateur de voir exactement quelles sources l'IA avait à disposition.

**Structure du champ `rag_context` (JSON) :**

```json
{
  "system_prompt_sent": "Tu es Support Client pour ZOOMBAT...\n\n## CAS SIMILAIRES...\n\n## CONTEXTE DOCUMENTAIRE...",

  "learned_sources": [
    {
      "index": 1,
      "score": 87.5,
      "question": "Comment envoyer une facture par email ?",
      "answer": "Pour envoyer une facture...",
      "message_id": 42
    }
  ],

  "document_sources": [
    {
      "index": 1,
      "id": "doc_123",
      "score": 85.2,
      "content": "Guide: Pour envoyer un document depuis ZOOMBAT...",
      "metadata": {"category": "documentation", "source": "guide_utilisateur.pdf"}
    }
  ],

  "stats": {
    "learned_count": 1,
    "document_count": 2,
    "agent_slug": "support-client",
    "agent_model": "mistral:7b",
    "temperature": 0.7
  }
}
```

**Affichage dans la vue validation :**

Le bouton "Voir le contexte envoyé à l'IA" se trouve **sous chaque réponse de l'assistant** (pas sur le message utilisateur). Cela permet d'inclure la réponse de l'IA dans le contexte affiché.

```
┌─────────────────────────────────────────────────────────────────┐
│  📋 Contexte envoyé à l'IA                                 [X]  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  0. 💬 Question et Réponse                               [▼]   │
│     Question utilisateur: "Comment envoyer une facture..."      │
│     Réponse de l'IA: "Pour envoyer une facture..."              │
│                                                                 │
│  1. ⚙️ Prompt système                                    [▼]   │
│                                                                 │
│  2. 🕒 Historique de conversation (3 messages)           [▼]   │
│                                                                 │
│  3. 📄 Documents indexés - RAG (2)                       [▼]   │
│                                                                 │
│  4. 🎓 Sources d'apprentissage (1)                       [▼]   │
│                                                                 │
│  5. 💻 Données brutes (JSON)                             [▼]   │
│                                                                 │
│  6. 📋 Rapport pour analyse (copier pour Claude)         [▼]   │
│     [Copier le rapport complet]                                 │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Fonctionnalité de rapport d'analyse :**

La section "6. Rapport pour analyse" permet de copier un rapport complet formaté en Markdown contenant :
- La question utilisateur
- La réponse de l'IA
- Le prompt système complet
- L'historique de conversation
- Les documents RAG utilisés
- Les sources d'apprentissage
- Les informations techniques (modèle, tokens, temps, fallback)

Ce rapport peut être envoyé directement à Claude ou un autre LLM pour analyser pourquoi l'IA n'a pas bien répondu à une question.

Cette transparence permet au validateur de :
- Comprendre pourquoi l'IA a répondu d'une certaine manière
- Identifier si les sources étaient pertinentes
- Décider si une correction est nécessaire
- Analyser les problèmes en copiant le rapport complet vers un LLM d'analyse

---

## 3. Widgets Dashboard

### 3.1 Widget "Réponses à valider"

**Emplacement :** Dashboard principal

```
┌─────────────────────────────────────────────────────────────────┐
│  📋 Réponses à valider                          [Voir tout →]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  23 réponses en attente de validation                          │
│                                                                 │
│  Par agent:                                                     │
│  • Support Client: 15                                           │
│  • Assistant BTP: 8                                             │
│                                                                 │
│  ⚠️ 5 réponses avec feedback négatif                            │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Widget "Statistiques Apprentissage"

```
┌─────────────────────────────────────────────────────────────────┐
│  🧠 Apprentissage IA                                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Ce mois:                                                       │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐        │
│  │   156    │  │   142    │  │    12    │  │    2     │        │
│  │ Validées │  │ Apprises │  │ Rejetées │  │ En att.  │        │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘        │
│                                                                 │
│  Taux d'amélioration: +15% vs mois dernier                     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 4. Implémentation Technique

### 4.1 Fichiers à créer

```
app/Filament/Resources/
├── AiSessionResource.php
├── AiSessionResource/
│   ├── Pages/
│   │   ├── ListAiSessions.php
│   │   ├── ViewAiSession.php
│   │   └── ValidationQueue.php      # Page custom pour validation

app/Filament/Widgets/
├── PendingValidationWidget.php
├── LearningStatsWidget.php

resources/views/filament/
├── resources/ai-session-resource/
│   └── pages/
│       ├── view-ai-session.blade.php    # Vue conversation
│       └── validation-queue.blade.php    # File de validation
```

### 4.2 AiSessionResource.php

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiSessionResource\Pages;
use App\Models\AiSession;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AiSessionResource extends Resource
{
    protected static ?string $model = AiSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Sessions IA';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('uuid')
                    ->label('ID')
                    ->limit(8)
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->badge()
                    ->color(fn ($record) => $record->agent?->color ?? 'gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->default('Visiteur')
                    ->searchable(),

                Tables\Columns\TextColumn::make('external_context.source')
                    ->label('Source')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'admin_test' => 'warning',
                        'api' => 'info',
                        'public_link' => 'success',
                        'partner' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('message_count')
                    ->label('Messages')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pending_validation_count')
                    ->label('À valider')
                    ->numeric()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'success' => 'active',
                        'gray' => 'archived',
                        'danger' => 'deleted',
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('agent')
                    ->relationship('agent', 'name'),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'archived' => 'Archivée',
                        'deleted' => 'Supprimée',
                    ]),

                Tables\Filters\Filter::make('has_pending')
                    ->label('Avec messages à valider')
                    ->query(fn (Builder $query) => $query->whereHas('messages',
                        fn ($q) => $q->where('validation_status', 'pending')
                            ->where('role', 'assistant')
                    )),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Du'),
                        Forms\Components\DatePicker::make('until')->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('archive')
                    ->label('Archiver')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'active')
                    ->action(fn ($record) => $record->update(['status' => 'archived'])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('archive')
                    ->label('Archiver')
                    ->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->update(['status' => 'archived'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiSessions::route('/'),
            'view' => Pages\ViewAiSession::route('/{record}'),
            'validation' => Pages\ValidationQueue::route('/validation'),
        ];
    }
}
```

### 4.3 ViewAiSession.php - Page de conversation

```php
<?php

namespace App\Filament\Resources\AiSessionResource\Pages;

use App\Filament\Resources\AiSessionResource;
use App\Services\AI\LearningService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAiSession extends ViewRecord
{
    protected static string $resource = AiSessionResource::class;

    protected static string $view = 'filament.resources.ai-session-resource.pages.view-ai-session';

    public function validateMessage(int $messageId): void
    {
        $message = $this->record->messages()->findOrFail($messageId);

        app(LearningService::class)->validate($message, auth()->id());

        Notification::make()
            ->title('Réponse validée')
            ->success()
            ->send();

        $this->refreshFormData(['messages']);
    }

    public function rejectMessage(int $messageId, ?string $reason = null): void
    {
        $message = $this->record->messages()->findOrFail($messageId);

        app(LearningService::class)->reject($message, auth()->id(), $reason);

        Notification::make()
            ->title('Réponse rejetée')
            ->success()
            ->send();

        $this->refreshFormData(['messages']);
    }

    public function learnFromMessage(int $messageId, string $correctedContent): void
    {
        $message = $this->record->messages()->findOrFail($messageId);

        $result = app(LearningService::class)->learn(
            $message,
            $correctedContent,
            auth()->id()
        );

        if ($result) {
            Notification::make()
                ->title('Correction enregistrée')
                ->body('La réponse corrigée a été indexée pour l\'apprentissage.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Erreur')
                ->body('Impossible d\'indexer la correction.')
                ->danger()
                ->send();
        }

        $this->refreshFormData(['messages']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('archive')
                ->label('Archiver')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'active')
                ->action(fn () => $this->record->update(['status' => 'archived'])),
        ];
    }
}
```

### 4.4 ValidationQueue.php - File de validation

```php
<?php

namespace App\Filament\Resources\AiSessionResource\Pages;

use App\Filament\Resources\AiSessionResource;
use App\Models\AiMessage;
use App\Services\AI\LearningService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\Paginator;
use Livewire\WithPagination;

class ValidationQueue extends Page
{
    use WithPagination;

    protected static string $resource = AiSessionResource::class;

    protected static string $view = 'filament.resources.ai-session-resource.pages.validation-queue';

    protected static ?string $title = 'Réponses à valider';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    public ?int $agentFilter = null;

    public bool $negativeOnly = false;

    public function getPendingMessages(): Paginator
    {
        return app(LearningService::class)->getPendingMessages(
            agentId: $this->agentFilter,
            perPage: 10
        );
    }

    public function getStats(): array
    {
        return app(LearningService::class)->getStats($this->agentFilter);
    }

    public function validateMessage(int $messageId): void
    {
        $message = AiMessage::findOrFail($messageId);

        app(LearningService::class)->validate($message, auth()->id());

        Notification::make()
            ->title('Réponse validée')
            ->success()
            ->send();
    }

    public function rejectMessage(int $messageId, ?string $reason = null): void
    {
        $message = AiMessage::findOrFail($messageId);

        app(LearningService::class)->reject($message, auth()->id(), $reason);

        Notification::make()
            ->title('Réponse rejetée')
            ->success()
            ->send();
    }

    public function learnFromMessage(int $messageId, string $correctedContent): void
    {
        $message = AiMessage::findOrFail($messageId);

        $result = app(LearningService::class)->learn(
            $message,
            $correctedContent,
            auth()->id()
        );

        if ($result) {
            Notification::make()
                ->title('Correction enregistrée')
                ->body('La réponse corrigée a été indexée pour l\'apprentissage.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Erreur')
                ->body('Impossible d\'indexer la correction.')
                ->danger()
                ->send();
        }
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AiMessage::where('role', 'assistant')
            ->where('validation_status', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }
}
```

### 4.5 Modèle AiSession - Attributs calculés

Ajouter dans `app/Models/AiSession.php` :

```php
/**
 * Nombre de messages en attente de validation
 */
public function getPendingValidationCountAttribute(): int
{
    return $this->messages()
        ->where('role', 'assistant')
        ->where('validation_status', 'pending')
        ->count();
}

/**
 * Relation avec les messages
 */
public function messages(): HasMany
{
    return $this->hasMany(AiMessage::class, 'session_id');
}
```

### 4.6 Navigation Filament

Ajouter un lien direct dans le menu pour la validation :

```php
// Dans un ServiceProvider ou AdminPanelProvider

use Filament\Navigation\NavigationItem;

->navigationItems([
    NavigationItem::make('Validation IA')
        ->icon('heroicon-o-clipboard-document-check')
        ->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.ai-sessions.validation'))
        ->url(fn () => AiSessionResource::getUrl('validation'))
        ->badge(fn () => AiMessage::where('role', 'assistant')
            ->where('validation_status', 'pending')
            ->count() ?: null)
        ->badgeColor('warning')
        ->group('Intelligence Artificielle'),
])
```

---

## 5. Permissions

### 5.1 Permissions à ajouter

| Permission | Slug | Description |
|------------|------|-------------|
| Voir sessions | `ai-sessions.view` | Voir la liste des sessions |
| Voir conversation | `ai-sessions.view-messages` | Voir le détail d'une conversation |
| Valider réponses | `ai-sessions.validate` | Valider/rejeter des réponses |
| Corriger réponses | `ai-sessions.learn` | Corriger et déclencher l'apprentissage |
| Archiver sessions | `ai-sessions.archive` | Archiver des sessions |

### 5.2 Attribution par rôle

| Rôle | Permissions |
|------|-------------|
| Super Admin | Toutes |
| Admin | Toutes |
| Validateur | view, view-messages, validate, learn |
| Viewer | view, view-messages |

---

## 6. Dépendances

### 6.1 Services requis

- `LearningService` - Déjà documenté dans `03_ai_core_logic.md`
- `EmbeddingService` - Pour générer les embeddings des questions
- `QdrantService` - Pour indexer les réponses apprises

### 6.2 Tables requises

- `ai_sessions` - ✅ Existe
- `ai_messages` - ✅ Existe (avec champs validation_status, corrected_content)
- `ai_feedbacks` - ✅ Existe

### 6.3 Collection Qdrant

- `learned_responses` - À créer si non existante

---

## 7. Plan d'implémentation

### Phase 1 : AiSessionResource basique
1. Créer `AiSessionResource.php` avec table list
2. Créer page `ListAiSessions.php`
3. Ajouter filtres et actions

### Phase 2 : Vue conversation
1. Créer page `ViewAiSession.php`
2. Créer template blade `view-ai-session.blade.php`
3. Afficher messages avec statuts de validation

### Phase 3 : Interface de validation
1. Créer page `ValidationQueue.php`
2. Créer template blade `validation-queue.blade.php`
3. Implémenter les actions validate/learn/reject
4. Ajouter modal de correction

### Phase 4 : Widgets dashboard
1. Créer `PendingValidationWidget.php`
2. Créer `LearningStatsWidget.php`
3. Intégrer au dashboard

### Phase 5 : Permissions
1. Ajouter les permissions au seeder
2. Configurer les policies Filament
3. Tester les accès par rôle

---

## 8. Tests à prévoir

- [ ] Affichage liste sessions avec filtres
- [ ] Navigation vers détail conversation
- [ ] Validation d'une réponse → statut change à `validated`
- [ ] Rejet d'une réponse → statut change à `rejected`
- [ ] Correction d'une réponse → statut change à `learned` + indexation Qdrant
- [ ] Badge de navigation se met à jour
- [ ] Permissions : validateur peut valider mais pas archiver
- [ ] Permissions : viewer peut voir mais pas valider
