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

## 9. Risques et Mitigations

| Risque | Impact | Mitigation |
|--------|--------|------------|
| L'IA ne respecte pas le format | Haut | Fallback sur mode simple, prompt robuste avec exemples |
| Performance (parsing sur chaque message) | Moyen | Parser léger, cache si nécessaire |
| Confusion utilisateur | Moyen | Option désactivée par défaut, documentation claire |
| Blocs partiellement validés | Faible | Tracking du statut par bloc, interface claire |

## 10. Métriques de Succès

- Taux de détection correct des multi-questions (>90% visé)
- Augmentation du nombre de paires Q/R indexées
- Amélioration du score de similarité moyen sur les recherches
- Réduction du temps de validation admin (moins de corrections manuelles)

---

**Auteur :** Claude
**Date :** 2025-01-03
**Version :** 1.0
**Statut :** Proposition
