# Document de Travail : Détection Multi-Questions et Apprentissage Granulaire

## 1. Contexte et Problématique

### 1.1 Situation actuelle

Le système de support client actuel traite chaque message utilisateur comme **une seule question** et génère **une seule réponse**. L'apprentissage se fait donc sur la paire complète (message complet → réponse complète).

**Exemple problématique :**
```
Utilisateur : "Bonjour, j'ai un nouveau client, la société 'Alpha Design'.
Comment je l'ajoute et comment je lui fais un devis de 1500€ pour de la prestation de service ?"
```

Actuellement, l'IA génère une réponse unique qui traite les deux questions. Lors de l'apprentissage, cette paire est indexée comme un tout, ce qui pose plusieurs problèmes :

1. **Réutilisabilité faible** : Si un utilisateur pose uniquement "Comment créer un client ?", la similarité avec la question indexée sera faible
2. **Apprentissage imprécis** : On ne peut pas valider/corriger une partie de la réponse sans affecter l'autre
3. **Granularité perdue** : Les connaissances atomiques sont noyées dans des blocs monolithiques

### 1.2 Objectif

Permettre à l'IA de :
1. **Détecter** qu'un message contient plusieurs questions distinctes
2. **Structurer** sa réponse en blocs identifiables (un par question)
3. **Afficher** des boutons d'apprentissage individuels dans le back-office

## 2. Architecture Proposée

### 2.1 Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────────┐
│                       Message Utilisateur                        │
│  "Comment ajouter un client + Comment faire un devis ?"         │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    PHASE 1 : DÉTECTION                          │
│                                                                  │
│  Prompt enrichi demandant d'identifier les questions distinctes │
│  → Retourne un JSON structuré avec les questions identifiées    │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    PHASE 2 : RÉPONSE STRUCTURÉE                 │
│                                                                  │
│  L'IA génère une réponse avec des délimiteurs par question :    │
│                                                                  │
│  [Q1: Comment ajouter un client ?]                              │
│  Pour ajouter un client, allez dans...                          │
│  [/Q1]                                                          │
│                                                                  │
│  [Q2: Comment faire un devis de prestation ?]                   │
│  Pour créer un devis de prestation de service...                │
│  [/Q2]                                                          │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    PHASE 3 : PARSING                            │
│                                                                  │
│  MultiQuestionParser extrait les blocs Q/R individuels          │
│  → Stocke les segments dans rag_context ou colonne dédiée       │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    PHASE 4 : AFFICHAGE ADMIN                    │
│                                                                  │
│  La vue ViewAiSession affiche :                                 │
│  - La réponse complète (formatée proprement)                    │
│  - N boutons "Valider/Corriger" (un par segment Q/R)            │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Format de Réponse Structurée

L'IA utilisera un format avec délimiteurs pour ses réponses multi-questions :

```markdown
[QUESTION_BLOCK id="1" question="Comment ajouter un nouveau client ?"]
Pour ajouter un nouveau client dans le système :
1. Rendez-vous dans le menu **Clients** > **Nouveau client**
2. Remplissez le formulaire avec les informations de la société
3. Cliquez sur **Enregistrer**
[/QUESTION_BLOCK]

[QUESTION_BLOCK id="2" question="Comment créer un devis de prestation de service ?"]
Pour créer un devis de prestation de service de 1500€ :
1. Allez dans **Devis** > **Nouveau devis**
2. Sélectionnez le client concerné
3. Choisissez le type "Prestation de service"
4. Entrez le montant de 1500€ HT
5. Validez le devis
[/QUESTION_BLOCK]
```

**Affichage côté utilisateur (nettoyé) :**
Les délimiteurs sont retirés pour l'affichage final, mais la structure en sections est préservée avec des titres.

## 3. Modifications Techniques

### 3.1 Enrichissement du System Prompt

**Fichier :** `app/Models/Agent.php`

Ajouter une nouvelle méthode `getMultiQuestionInstructions()` :

```php
public function getMultiQuestionInstructions(): string
{
    if (!$this->multi_question_detection_enabled) {
        return '';
    }

    return <<<'MULTI_Q'

## DÉTECTION ET TRAITEMENT DES QUESTIONS MULTIPLES

Quand un message utilisateur contient PLUSIEURS questions distinctes, tu DOIS :

1. **Identifier** chaque question séparément
2. **Structurer** ta réponse avec un bloc par question
3. **Utiliser** le format suivant pour chaque question :

```
[QUESTION_BLOCK id="N" question="La question reformulée clairement"]
Ta réponse à cette question spécifique...
[/QUESTION_BLOCK]
```

### Règles :
- Numérote les blocs séquentiellement (1, 2, 3...)
- Reformule chaque question de manière claire et autonome dans l'attribut "question"
- Chaque bloc doit être une réponse COMPLÈTE et AUTONOME (utilisable seule)
- Si le message ne contient qu'UNE question, réponds normalement SANS les délimiteurs

### Exemple :

**Message utilisateur :** "Comment ajouter un client et comment faire un devis ?"

**Ta réponse :**
[QUESTION_BLOCK id="1" question="Comment ajouter un nouveau client ?"]
Pour ajouter un client, rendez-vous dans le menu Clients > Nouveau client...
[/QUESTION_BLOCK]

[QUESTION_BLOCK id="2" question="Comment créer un devis ?"]
Pour créer un devis, allez dans Devis > Nouveau devis...
[/QUESTION_BLOCK]

MULTI_Q;
}
```

### 3.2 Service de Parsing

**Nouveau fichier :** `app/Services/AI/MultiQuestionParser.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

class MultiQuestionParser
{
    private const BLOCK_PATTERN = '/\[QUESTION_BLOCK\s+id="(\d+)"\s+question="([^"]+)"\](.*?)\[\/QUESTION_BLOCK\]/s';

    /**
     * Parse une réponse IA pour extraire les blocs Q/R.
     *
     * @return array{
     *   is_multi_question: bool,
     *   blocks: array<int, array{id: int, question: string, answer: string}>,
     *   raw_content: string,
     *   display_content: string
     * }
     */
    public function parse(string $content): array
    {
        $matches = [];
        preg_match_all(self::BLOCK_PATTERN, $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return [
                'is_multi_question' => false,
                'blocks' => [],
                'raw_content' => $content,
                'display_content' => $content,
            ];
        }

        $blocks = [];
        foreach ($matches as $match) {
            $blocks[] = [
                'id' => (int) $match[1],
                'question' => trim($match[2]),
                'answer' => trim($match[3]),
            ];
        }

        // Générer le contenu d'affichage (sans les délimiteurs techniques)
        $displayContent = $this->formatForDisplay($blocks);

        return [
            'is_multi_question' => count($blocks) > 1,
            'blocks' => $blocks,
            'raw_content' => $content,
            'display_content' => $displayContent,
        ];
    }

    /**
     * Formate les blocs pour l'affichage utilisateur.
     */
    private function formatForDisplay(array $blocks): string
    {
        if (count($blocks) === 1) {
            return $blocks[0]['answer'];
        }

        $parts = [];
        foreach ($blocks as $index => $block) {
            $num = $index + 1;
            $parts[] = "### {$num}. {$block['question']}\n\n{$block['answer']}";
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Vérifie si un contenu contient des blocs multi-questions.
     */
    public function hasMultipleQuestions(string $content): bool
    {
        preg_match_all(self::BLOCK_PATTERN, $content, $matches);
        return count($matches[0]) > 1;
    }
}
```

### 3.3 Modification du ProcessAiMessageJob

**Fichier :** `app/Jobs/AI/ProcessAiMessageJob.php`

Après la génération de la réponse IA, parser le contenu :

```php
// Après avoir reçu la réponse du LLM
$parser = app(MultiQuestionParser::class);
$parsed = $parser->parse($response);

// Stocker les informations parsées dans rag_context
$ragContext['multi_question'] = [
    'is_multi' => $parsed['is_multi_question'],
    'blocks' => $parsed['blocks'],
];

// Stocker le contenu d'affichage (nettoyé) comme contenu principal
$message->update([
    'content' => $parsed['display_content'],
    'rag_context' => $ragContext,
]);
```

### 3.4 Modification de la Vue d'Apprentissage

**Fichier :** `resources/views/filament/resources/ai-session-resource/pages/view-ai-session.blade.php`

Modifier la section des boutons de validation pour gérer les multi-questions :

```blade
{{-- Boutons de validation (si en attente) --}}
@if($message['is_pending_validation'])
    @php
        $multiQ = $message['rag_context']['multi_question'] ?? null;
        $isMultiQuestion = $multiQ['is_multi'] ?? false;
        $blocks = $multiQ['blocks'] ?? [];
    @endphp

    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
        @if($isMultiQuestion && count($blocks) > 1)
            {{-- Mode Multi-Questions : un bloc d'apprentissage par question --}}
            <div class="space-y-4">
                <div class="flex items-center gap-2 text-xs text-gray-500 mb-2">
                    <x-heroicon-o-queue-list class="w-4 h-4" />
                    <span>{{ count($blocks) }} questions détectées - Validez chaque réponse individuellement</span>
                </div>

                @foreach($blocks as $blockIndex => $block)
                    <div class="p-3 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700"
                         x-data="{
                             showForm: false,
                             question: @js($block['question']),
                             answer: @js($block['answer']),
                             validated: false,
                             requiresHandoff: false
                         }">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1">
                                <div class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Question {{ $blockIndex + 1 }}
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ \Illuminate\Support\Str::limit($block['question'], 100) }}
                                </div>
                            </div>
                            <div class="flex items-center gap-2" x-show="!validated">
                                <x-filament::button
                                    size="xs"
                                    color="success"
                                    icon="heroicon-o-check"
                                    x-on:click="showForm = !showForm"
                                >
                                    Valider
                                </x-filament::button>
                            </div>
                            <x-filament::badge x-show="validated" color="success" size="sm">
                                Validé
                            </x-filament::badge>
                        </div>

                        {{-- Formulaire de validation/correction par bloc --}}
                        <div x-show="showForm" x-cloak class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Question (modifiable)
                                </label>
                                <textarea
                                    x-model="question"
                                    rows="2"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm"
                                ></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Réponse (modifiable)
                                </label>
                                <textarea
                                    x-model="answer"
                                    rows="4"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm"
                                ></textarea>
                            </div>
                            <div>
                                <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        x-model="requiresHandoff"
                                        class="rounded border-gray-300 dark:border-gray-600 text-warning-600 focus:ring-warning-500"
                                    />
                                    <span>Nécessite toujours un suivi humain</span>
                                </label>
                            </div>
                            <div class="flex gap-2">
                                <x-filament::button
                                    size="xs"
                                    color="success"
                                    icon="heroicon-o-check"
                                    x-on:click="
                                        $wire.learnMultiQuestionBlock(
                                            {{ $message['original_id'] }},
                                            {{ $blockIndex }},
                                            question,
                                            answer,
                                            requiresHandoff
                                        );
                                        validated = true;
                                        showForm = false;
                                    "
                                >
                                    Enregistrer ce bloc
                                </x-filament::button>
                                <x-filament::button
                                    size="xs"
                                    color="gray"
                                    x-on:click="showForm = false"
                                >
                                    Annuler
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- Mode Question Simple : comportement actuel --}}
            {{-- ... code existant ... --}}
        @endif
    </div>
@endif
```

### 3.5 Nouvelle Méthode dans ViewAiSession.php

**Fichier :** `app/Filament/Resources/AiSessionResource/Pages/ViewAiSession.php`

```php
/**
 * Apprend un bloc spécifique d'une réponse multi-questions.
 */
public function learnMultiQuestionBlock(
    int $messageId,
    int $blockIndex,
    string $question,
    string $answer,
    bool $requiresHandoff = false
): void {
    $message = AiMessage::findOrFail($messageId);

    if ($message->session_id !== $this->record->id) {
        return;
    }

    if (empty(trim($question)) || empty(trim($answer))) {
        Notification::make()
            ->title('Erreur')
            ->body('La question et la réponse ne peuvent pas être vides.')
            ->danger()
            ->send();
        return;
    }

    try {
        $result = app(LearningService::class)->indexLearnedResponse(
            question: trim($question),
            answer: trim($answer),
            agentId: $this->record->agent_id,
            agentSlug: $this->record->agent->slug,
            messageId: $messageId,
            validatorId: auth()->id(),
            requiresHandoff: $requiresHandoff
        );

        if ($result) {
            // Mettre à jour le statut du bloc dans rag_context
            $ragContext = $message->rag_context ?? [];
            $ragContext['multi_question']['blocks'][$blockIndex]['learned'] = true;
            $ragContext['multi_question']['blocks'][$blockIndex]['learned_at'] = now()->toIso8601String();
            $ragContext['multi_question']['blocks'][$blockIndex]['learned_by'] = auth()->id();
            $message->update(['rag_context' => $ragContext]);

            // Vérifier si tous les blocs sont validés
            $allLearned = collect($ragContext['multi_question']['blocks'] ?? [])
                ->every(fn ($b) => ($b['learned'] ?? false) === true);

            if ($allLearned) {
                $message->update([
                    'validation_status' => 'learned',
                    'validated_by' => auth()->id(),
                    'validated_at' => now(),
                ]);
            }

            Notification::make()
                ->title('Bloc appris')
                ->body("Q: " . \Illuminate\Support\Str::limit($question, 40))
                ->success()
                ->send();
        }
    } catch (\Throwable $e) {
        Notification::make()
            ->title('Erreur')
            ->body($e->getMessage())
            ->danger()
            ->send();
    }
}
```

## 4. Migration de Base de Données

**Nouvelle colonne optionnelle (ou utilisation de rag_context existant)**

Le format actuel de `rag_context` (JSON) peut accueillir les données multi-questions sans migration :

```json
{
    "stats": { ... },
    "document_sources": [ ... ],
    "learned_sources": [ ... ],
    "multi_question": {
        "is_multi": true,
        "blocks": [
            {
                "id": 1,
                "question": "Comment ajouter un client ?",
                "answer": "Pour ajouter un client...",
                "learned": true,
                "learned_at": "2025-01-03T10:00:00Z",
                "learned_by": 5
            },
            {
                "id": 2,
                "question": "Comment créer un devis ?",
                "answer": "Pour créer un devis...",
                "learned": false
            }
        ]
    }
}
```

## 5. Configuration par Agent

**Nouvelle colonne dans `agents` table :**

```php
// Migration
Schema::table('agents', function (Blueprint $table) {
    $table->boolean('multi_question_detection_enabled')->default(false);
    $table->integer('max_questions_per_message')->default(5);
});
```

**Interface Filament :**

Ajouter dans le formulaire Agent une section "Détection Multi-Questions" :
- Toggle : Activer la détection multi-questions
- Number : Nombre max de questions détectables (1-10)

## 6. Tests Recommandés

### 6.1 Tests Unitaires

```php
// tests/Unit/Services/AI/MultiQuestionParserTest.php

public function test_parses_single_question_response(): void
{
    $parser = new MultiQuestionParser();
    $content = "Voici comment faire...";

    $result = $parser->parse($content);

    $this->assertFalse($result['is_multi_question']);
    $this->assertEmpty($result['blocks']);
}

public function test_parses_multi_question_response(): void
{
    $parser = new MultiQuestionParser();
    $content = <<<'CONTENT'
[QUESTION_BLOCK id="1" question="Comment ajouter un client ?"]
Pour ajouter un client, allez dans...
[/QUESTION_BLOCK]

[QUESTION_BLOCK id="2" question="Comment créer un devis ?"]
Pour créer un devis...
[/QUESTION_BLOCK]
CONTENT;

    $result = $parser->parse($content);

    $this->assertTrue($result['is_multi_question']);
    $this->assertCount(2, $result['blocks']);
    $this->assertEquals("Comment ajouter un client ?", $result['blocks'][0]['question']);
}
```

### 6.2 Tests d'Intégration

- Tester le flow complet : message multi-questions → parsing → affichage → apprentissage individuel
- Vérifier que chaque bloc est bien indexé séparément dans Qdrant
- Vérifier que la recherche de similarité fonctionne sur les questions individuelles

## 7. Plan d'Implémentation

### Phase 1 : Infrastructure (2-3 jours)
- [ ] Créer `MultiQuestionParser.php`
- [ ] Tests unitaires du parser
- [ ] Migration pour les colonnes agent

### Phase 2 : Intégration IA (2-3 jours)
- [ ] Modifier `Agent::getMultiQuestionInstructions()`
- [ ] Intégrer dans `PromptBuilder::buildChatMessages()`
- [ ] Modifier `ProcessAiMessageJob` pour parser les réponses

### Phase 3 : Interface Admin (2-3 jours)
- [ ] Modifier le template Blade pour l'affichage multi-blocs
- [ ] Ajouter `learnMultiQuestionBlock()` dans ViewAiSession
- [ ] Ajouter les styles et interactions Alpine.js

### Phase 4 : Tests et Polish (1-2 jours)
- [ ] Tests d'intégration complets
- [ ] Ajustements du prompt selon les résultats
- [ ] Documentation utilisateur

## 8. Considérations UX

### 8.1 Affichage Utilisateur (Chat Public)

Pour l'utilisateur final, les délimiteurs techniques sont retirés. La réponse s'affiche avec :
- Des sections numérotées
- Des titres clairs (les questions reformulées)
- Une séparation visuelle entre les blocs

### 8.2 Affichage Admin (Back-office)

Pour l'admin, l'interface affiche :
- Un indicateur "N questions détectées"
- Chaque bloc dans une card séparée avec :
  - La question (modifiable)
  - La réponse (modifiable)
  - Bouton "Valider ce bloc"
  - Badge "Validé" après validation

### 8.3 Feedback Visuel

- Barre de progression : "2/3 blocs validés"
- Validation automatique du message parent quand tous les blocs sont validés
- Notification de succès par bloc

---

# PARTIE 2 : Mode Strict Assisté avec Handoff Humain

## 9. Contexte et Problématique

### 9.1 Comportement Actuel

Le mode **strict** (`strict_mode = true`) ajoute des contraintes fortes au prompt :

```
- Ne réponds QU'avec les informations présentes dans le contexte fourni
- Si l'information demandée n'est pas dans le contexte, indique clairement :
  "Je n'ai pas cette information dans ma base de connaissances"
```

**Problème observé :** Chaque LLM interprète ces instructions différemment :

| LLM | Comportement en mode strict sans contexte |
|-----|-------------------------------------------|
| **Mistral** | Tente quand même de fournir une réponse utile |
| **Gemini** | Refuse systématiquement, propose le support humain |
| **GPT-4** | Comportement intermédiaire, dépend du prompt |
| **Claude** | Respecte strictement, mais peut suggérer des pistes |

### 9.2 Cas d'Usage Problématique

Quand le **mode handoff humain** est activé :

1. L'IA génère une réponse (potentiellement un refus type "Je n'ai pas cette info")
2. La réponse N'EST PAS affichée au client (en attente de validation)
3. L'agent humain voit la réponse dans le back-office

**Le problème :** Si l'IA refuse de répondre (mode strict + pas de contexte), l'agent humain n'a AUCUNE piste. Il doit rédiger sa réponse de zéro.

**L'opportunité :** Puisque la réponse passe par un humain avant d'atteindre le client, l'IA pourrait proposer une réponse "best effort" basée sur ses connaissances générales, clairement marquée comme non-documentée.

### 9.3 Objectif

Créer un mode **"Strict Assisté"** qui :
1. Maintient la rigueur du mode strict pour les réponses directes au client
2. Permet à l'IA de faire des **propositions** quand un humain valide la réponse
3. Marque clairement les propositions comme "non-documentées" pour l'agent

## 10. Architecture Proposée

### 10.1 Logique de Décision

```
┌─────────────────────────────────────────────────────────────────┐
│                    Configuration Agent                           │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
                    ┌───────────────┐
                    │  strict_mode  │
                    │   = true ?    │
                    └───────┬───────┘
                            │
              ┌─────────────┴─────────────┐
              │                           │
              ▼                           ▼
        [strict=true]               [strict=false]
              │                           │
              ▼                           │
    ┌─────────────────┐                   │
    │ human_support   │                   │
    │  _enabled ?     │                   │
    └────────┬────────┘                   │
             │                            │
   ┌─────────┴─────────┐                  │
   │                   │                  │
   ▼                   ▼                  │
[handoff=true]    [handoff=false]         │
   │                   │                  │
   ▼                   ▼                  │
┌──────────┐      ┌──────────┐           │
│ MODE     │      │ MODE     │           │
│ STRICT   │      │ STRICT   │           │
│ ASSISTÉ  │      │ PUR      │           │
└──────────┘      └──────────┘           │
                                          │
                                          ▼
                                    ┌──────────┐
                                    │ MODE     │
                                    │ LIBRE    │
                                    └──────────┘
```

### 10.2 Comportement par Mode

| Mode | Contexte RAG disponible | Comportement IA | Affichage Admin |
|------|------------------------|-----------------|-----------------|
| **Strict Pur** | Oui | Répond avec les sources | Normal |
| **Strict Pur** | Non | Refuse, propose support | Normal |
| **Strict Assisté** | Oui | Répond avec les sources | Badge "Documenté" |
| **Strict Assisté** | Non | Propose une réponse générale | Badge "Suggestion IA" ⚠️ |
| **Libre** | Oui/Non | Répond librement | Normal |

## 11. Modifications Techniques

### 11.1 Modification du PromptBuilder

**Fichier :** `app/Services/AI/PromptBuilder.php`

Modifier la méthode `buildChatMessages()` pour passer les flags nécessaires :

```php
public function buildChatMessages(
    Agent $agent,
    string $userMessage,
    array $ragResults = [],
    ?AiSession $session = null,
    array $learnedResponses = []
): array {
    // ...existing code...

    // Déterminer le mode de réponse
    $hasContext = !empty($ragResults) || !empty($learnedResponses);
    $isStrictAssisted = $agent->strict_mode && $agent->human_support_enabled;

    // Ajouter les garde-fous adaptés au contexte
    if ($agent->strict_mode) {
        if ($isStrictAssisted) {
            // Mode Strict Assisté : permettre les suggestions
            $systemContent .= $this->getStrictAssistedGuardrails($hasContext);
        } else {
            // Mode Strict Pur : comportement actuel
            $systemContent .= $agent->getStrictModeGuardrails();
        }
    }

    // ...rest of existing code...
}
```

### 11.2 Nouvelles Instructions "Strict Assisté"

**Fichier :** `app/Services/AI/PromptBuilder.php`

```php
/**
 * Retourne les garde-fous pour le mode Strict Assisté.
 * Ce mode permet des suggestions quand il n'y a pas de contexte documentaire,
 * car un humain validera la réponse avant qu'elle n'atteigne le client.
 */
private function getStrictAssistedGuardrails(bool $hasContext): string
{
    if ($hasContext) {
        // Avec contexte : comportement strict normal + marqueur
        return <<<'GUARDRAILS'

## CONTRAINTES DE RÉPONSE (Mode Strict avec Validation Humaine)

- Réponds en priorité avec les informations présentes dans le contexte fourni
- NE CITE PAS les sources dans ta réponse (pas de "Source:", "Document:", etc.)
- IGNORE les sources qui ne parlent pas du sujet demandé
- Si plusieurs sources se contredisent, signale cette incohérence

Ta réponse sera validée par un agent avant d'être transmise au client.
Ajoute le marqueur `[DOCUMENTED]` à la fin de ta réponse.

GUARDRAILS;
    }

    // Sans contexte : permettre une suggestion
    return <<<'GUARDRAILS'

## MODE SUGGESTION (Contexte Documentaire Insuffisant)

⚠️ **IMPORTANT** : Aucune information pertinente n'a été trouvée dans la base de connaissances pour cette question.

Cependant, ta réponse sera **validée par un agent humain** avant d'être transmise au client.
Tu peux donc proposer une réponse basée sur tes connaissances générales.

### Instructions :
1. Propose une réponse utile basée sur tes connaissances générales du domaine
2. Sois honnête sur le fait que tu n'as pas de source spécifique
3. Formule ta réponse de manière à aider l'agent humain à la compléter/corriger
4. Ajoute le marqueur `[SUGGESTION]` à la fin de ta réponse

### Format de réponse :
- Commence par une réponse utile (même générale)
- Si tu identifies des points qui nécessitent vérification, mentionne-les
- L'agent humain pourra corriger, compléter ou remplacer ta suggestion

**RAPPEL** : Cette réponse NE SERA PAS envoyée directement au client.
Elle servira de base de travail pour l'agent de support.

GUARDRAILS;
}
```

### 11.3 Parsing des Marqueurs

**Fichier :** `app/Services/AI/ResponseParser.php` (nouveau ou existant)

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

class ResponseParser
{
    /**
     * Analyse une réponse IA pour détecter son type.
     *
     * @return array{
     *   type: 'documented'|'suggestion'|'unknown',
     *   content: string,
     *   requires_review: bool
     * }
     */
    public function parseResponseType(string $content): array
    {
        $type = 'unknown';
        $requiresReview = false;

        // Détecter le marqueur [DOCUMENTED]
        if (preg_match('/\[DOCUMENTED\]\s*$/i', $content)) {
            $type = 'documented';
            $content = preg_replace('/\s*\[DOCUMENTED\]\s*$/i', '', $content);
        }
        // Détecter le marqueur [SUGGESTION]
        elseif (preg_match('/\[SUGGESTION\]\s*$/i', $content)) {
            $type = 'suggestion';
            $requiresReview = true;
            $content = preg_replace('/\s*\[SUGGESTION\]\s*$/i', '', $content);
        }

        return [
            'type' => $type,
            'content' => trim($content),
            'requires_review' => $requiresReview,
        ];
    }
}
```

### 11.4 Stockage dans AiMessage

Modifier le `ProcessAiMessageJob` pour stocker le type de réponse :

```php
// Après génération de la réponse
$parser = app(ResponseParser::class);
$parsed = $parser->parseResponseType($response);

// Stocker dans rag_context
$ragContext['response_type'] = $parsed['type'];
$ragContext['is_suggestion'] = $parsed['type'] === 'suggestion';

$message->update([
    'content' => $parsed['content'],
    'rag_context' => $ragContext,
]);
```

### 11.5 Affichage dans le Back-Office

**Fichier :** `view-ai-session.blade.php`

Ajouter un badge visuel pour distinguer les types de réponses :

```blade
{{-- Header IA avec type de réponse --}}
<div class="flex items-center gap-2 mb-2 pb-2 border-b border-gray-100 dark:border-gray-700">
    <x-heroicon-o-cpu-chip class="w-4 h-4 text-gray-400" />
    <span class="text-xs text-gray-500">{{ $message['sender_name'] }}</span>

    {{-- Type de réponse --}}
    @php
        $responseType = $message['rag_context']['response_type'] ?? 'unknown';
        $isSuggestion = $message['rag_context']['is_suggestion'] ?? false;
    @endphp

    @if($isSuggestion)
        <x-filament::badge color="warning" size="sm" icon="heroicon-o-light-bulb">
            Suggestion IA
        </x-filament::badge>
        <span class="text-xs text-warning-600 dark:text-warning-400">
            (sans documentation)
        </span>
    @elseif($responseType === 'documented')
        <x-filament::badge color="info" size="sm" icon="heroicon-o-document-check">
            Documenté
        </x-filament::badge>
    @endif

    {{-- Status de validation existant --}}
    @if($message['validation_status'] === 'pending')
        <x-filament::badge color="warning" size="sm">En attente</x-filament::badge>
    @elseif($message['validation_status'] === 'validated')
        <x-filament::badge color="success" size="sm">Validée</x-filament::badge>
    {{-- ...etc --}}
    @endif
</div>

{{-- Bannière d'avertissement pour les suggestions --}}
@if($isSuggestion)
    <div class="mb-3 p-2 bg-warning-50 dark:bg-warning-950 border border-warning-200 dark:border-warning-800 rounded-lg">
        <div class="flex items-start gap-2">
            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" />
            <div class="text-xs text-warning-700 dark:text-warning-300">
                <strong>Attention :</strong> Cette réponse est une suggestion basée sur les connaissances générales de l'IA.
                Aucune source documentaire ou cas similaire n'a été trouvé.
                <strong>Vérifiez et corrigez si nécessaire avant validation.</strong>
            </div>
        </div>
    </div>
@endif
```

## 12. Configuration Agent

### 12.1 Nouvelle Option

Pas besoin de nouvelle colonne ! Le comportement est automatique :
- `strict_mode = true` + `human_support_enabled = true` → Mode Strict Assisté
- `strict_mode = true` + `human_support_enabled = false` → Mode Strict Pur

Optionnellement, ajouter un toggle pour désactiver les suggestions :

```php
// Migration optionnelle
Schema::table('agents', function (Blueprint $table) {
    $table->boolean('allow_suggestions_without_context')->default(true);
});
```

### 12.2 Interface Filament

Dans le formulaire Agent, ajouter une explication :

```php
Forms\Components\Toggle::make('strict_mode')
    ->label('Mode strict')
    ->helperText(fn ($get) => $get('human_support_enabled')
        ? 'En mode strict avec support humain : l\'IA proposera des suggestions même sans documentation (visibles uniquement par les agents).'
        : 'En mode strict sans support humain : l\'IA refusera de répondre sans documentation.'
    ),
```

## 13. Flux Complet

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Question utilisateur : "Comment configurer le module XYZ ?" │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. Recherche RAG : Aucun résultat pertinent (score < seuil)    │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. Mode Strict Assisté détecté (strict + handoff)              │
│    → Prompt avec instructions "MODE SUGGESTION"                 │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. Réponse IA :                                                 │
│    "Le module XYZ se configure généralement via le menu        │
│     Paramètres > Modules. Vous devriez trouver les options     │
│     de configuration dans l'onglet 'Avancé'.                   │
│                                                                 │
│     Note: Je n'ai pas de documentation spécifique pour votre   │
│     version. Un conseiller pourra confirmer ces étapes.        │
│                                                                 │
│     [SUGGESTION]"                                               │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. Parsing : type='suggestion', contenu nettoyé                │
│    → Stockage dans rag_context                                  │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 6. Affichage Back-Office :                                      │
│    ┌──────────────────────────────────────────────────────┐    │
│    │ 🤖 Assistant IA    [⚠️ Suggestion IA] [En attente]  │    │
│    ├──────────────────────────────────────────────────────┤    │
│    │ ⚠️ Attention : Cette réponse est une suggestion...   │    │
│    ├──────────────────────────────────────────────────────┤    │
│    │ Le module XYZ se configure généralement via le menu │    │
│    │ Paramètres > Modules...                              │    │
│    ├──────────────────────────────────────────────────────┤    │
│    │ [✓ Valider] [✏️ Corriger] [✗ Rejeter]              │    │
│    └──────────────────────────────────────────────────────┘    │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 7. Agent corrige et valide → Réponse envoyée au client         │
│    + Indexation pour apprentissage futur                        │
└─────────────────────────────────────────────────────────────────┘
```

## 14. Avantages de cette Approche

| Aspect | Avant | Après |
|--------|-------|-------|
| **Agent sans contexte** | Doit rédiger de zéro | A une base de travail |
| **Temps de réponse** | Long (rédaction manuelle) | Réduit (correction/validation) |
| **Cohérence LLM** | Varie selon le provider | Comportement unifié |
| **Qualité finale** | Dépend de l'agent | IA + validation humaine |
| **Apprentissage** | Limité aux cas documentés | S'enrichit des corrections |

## 15. Risques et Mitigations

| Risque | Impact | Mitigation |
|--------|--------|------------|
| Agent valide sans vérifier | Haut | Bannière d'avertissement très visible, logs d'audit |
| Suggestion erronée indexée | Haut | Flag `is_suggestion` dans l'indexation, possibilité de filtrer |
| Confusion client | Moyen | La réponse ne passe JAMAIS sans validation en mode handoff |
| Surcharge cognitive agent | Faible | Badge clair, UI intuitive |

## 16. Métriques de Succès

- **Taux de correction** des suggestions vs réponses documentées
- **Temps moyen de traitement** par l'agent (devrait diminuer)
- **Satisfaction agent** (feedback qualitatif)
- **Taux de réponse** (moins de "Je ne sais pas" côté client)

---

## 17. Risques et Mitigations (Global)

| Risque | Impact | Mitigation |
|--------|--------|------------|
| L'IA ne respecte pas le format multi-questions | Haut | Fallback sur mode simple, prompt robuste avec exemples |
| Performance (parsing sur chaque message) | Moyen | Parser léger, cache si nécessaire |
| Confusion utilisateur | Moyen | Option désactivée par défaut, documentation claire |
| Blocs partiellement validés | Faible | Tracking du statut par bloc, interface claire |
| Agent valide suggestion sans vérifier | Haut | Bannière d'avertissement, logs d'audit |

## 18. Métriques de Succès (Global)

- Taux de détection correct des multi-questions (>90% visé)
- Augmentation du nombre de paires Q/R indexées
- Amélioration du score de similarité moyen sur les recherches
- Réduction du temps de validation admin
- Taux de correction des suggestions vs réponses documentées

---

**Auteur :** Claude
**Date :** 2025-01-03
**Version :** 1.1
**Statut :** Proposition

**Changelog :**
- v1.1 : Ajout de la Partie 2 - Mode Strict Assisté avec Handoff Humain
