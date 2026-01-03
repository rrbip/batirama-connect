# Document de Travail : Refonte UX Bloc Suggestion IA

## Date : 2026-01-03
## Statut : EN COURS

---

## 1. Problème Identifié

### Description
Dans un message IA multi-questions, les données sont affichées **2 FOIS** sous 2 formats différents :

```
┌─────────────────────────────────────────────────────────────┐
│ Message IA                                                  │
├─────────────────────────────────────────────────────────────┤
│ [Badges: En attente, Suggestion IA, 3 questions]            │
│ [Bannière avertissement suggestion]                         │
│                                                             │
│ ══════════════════════════════════════════════════════════  │
│ ZONE 1 : CONTENU COMPLET (lignes 306-325)                   │
│ ══════════════════════════════════════════════════════════  │
│                                                             │
│ **Question 1 : Comment faire X ?**                          │
│ Réponse complète pour X...                                  │
│                                                             │
│ **Question 2 : Comment faire Y ?**                          │
│ Réponse complète pour Y...                                  │
│                                                             │
│ **Question 3 : Comment faire Z ?**                          │
│ Réponse complète pour Z...                                  │
│                                                             │
│ ──────────────────────────────────────────────────────────  │
│ [Métadonnées: heure, modèle, tokens]                        │
│ ──────────────────────────────────────────────────────────  │
│ [Boutons: Valider | Corriger | Rejeter] (lignes 361-404)    │
│ [Formulaires validation/correction] (lignes 406-496)        │
│                                                             │
│ ══════════════════════════════════════════════════════════  │
│ ZONE 2 : BLOCS MULTI-QUESTIONS (lignes 499-631)             │
│ ══════════════════════════════════════════════════════════  │
│                                                             │
│ Blocs Q/R individuels                          0/3 validés  │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Question 1    [Badge: Suggestion]           [Valider]   │ │
│ │ Comment faire X ?                                       │ │
│ │ ┌─────────────────────────────────────────────────────┐ │ │
│ │ │ Réponse complète pour X... (tronquée)               │ │ │
│ │ └─────────────────────────────────────────────────────┘ │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Question 2    [Badge: Documenté]            [Valider]   │ │
│ │ Comment faire Y ?                                       │ │
│ │ ┌─────────────────────────────────────────────────────┐ │ │
│ │ │ Réponse complète pour Y... (tronquée)               │ │ │
│ │ └─────────────────────────────────────────────────────┘ │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Question 3    [Badge: Suggestion]           [Valider]   │ │
│ │ Comment faire Z ?                                       │ │
│ │ ┌─────────────────────────────────────────────────────┐ │ │
│ │ │ Réponse complète pour Z... (tronquée)               │ │ │
│ │ └─────────────────────────────────────────────────────┘ │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ [Bouton contexte RAG]                                       │
└─────────────────────────────────────────────────────────────┘
```

### Problèmes UX

1. **Duplication des données** : Les Q/R sont visibles 2 fois
2. **Confusion sur les boutons** : Faut-il utiliser les boutons globaux ou ceux par bloc ?
3. **Incohérence** : Zone 1 montre le texte complet, Zone 2 le tronque
4. **Surcharge visuelle** : Trop d'informations redondantes

---

## 2. Code Source Actuel

### Fichier : `view-ai-session.blade.php`

```
Lignes 306-325  : Contenu complet ($message['content'])
Lignes 361-404  : Boutons globaux (Valider/Corriger/Rejeter)
Lignes 406-496  : Formulaires globaux (validation/correction)
Lignes 499-631  : Blocs multi-questions individuels
```

### Structure des Blocs Multi-Questions (rag_context)

```php
$message['rag_context']['multi_question'] = [
    'is_multi' => true,
    'block_count' => 3,
    'blocks' => [
        [
            'id' => 1,
            'question' => 'Comment faire X ?',
            'answer' => 'Réponse complète pour X...',
            'type' => 'suggestion', // ou 'documented'
            'is_suggestion' => true,
            'learned' => false,
            'learned_at' => null,
            'learned_by' => null,
        ],
        // ...
    ]
];
```

---

## 3. Proposition de Refonte UX

### Option A : Blocs Uniquement (Recommandée)

**Principe** : Pour les messages multi-questions, NE PAS afficher le contenu complet. Afficher UNIQUEMENT les blocs individuels avec une UX améliorée.

```
┌─────────────────────────────────────────────────────────────┐
│ Message IA                                                  │
├─────────────────────────────────────────────────────────────┤
│ [Badges: En attente, 3 questions]                           │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Question 1/3    [Suggestion]                            │ │
│ │ ───────────────────────────────────────────────────────│ │
│ │ Q: Comment faire X ?                          [Éditer]  │ │
│ │ ───────────────────────────────────────────────────────│ │
│ │ R: Réponse complète pour X avec tous les détails...    │ │
│ │    (expandable si longue)                     [Éditer]  │ │
│ │ ───────────────────────────────────────────────────────│ │
│ │ [ ] Nécessite suivi humain                              │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Question 2/3    [Documenté] ✓ Validée                   │ │
│ │ ... (collapsed car validé)                              │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Question 3/3    [Suggestion]                            │ │
│ │ ...                                                     │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ──────────────────────────────────────────────────────────  │
│ [Valider Tout (2 restantes)] [Rejeter Tout]  [Contexte RAG] │
│ ──────────────────────────────────────────────────────────  │
│ 12:34 • gemini-2.0-flash • 1234 tokens                      │
└─────────────────────────────────────────────────────────────┘
```

**Avantages** :
- Pas de duplication
- Chaque Q/R est éditable individuellement
- Vision claire du statut par bloc
- Boutons globaux pour actions en masse

### Option B : Contenu Complet Seulement

**Principe** : NE PAS afficher les blocs. Garder le contenu complet avec un seul bouton "Valider Tout".

**Inconvénient** : Perd la granularité (badge par bloc, validation individuelle)

### Option C : Mode Accordéon

**Principe** : Contenu complet collapsed par défaut, blocs expanded. Ou l'inverse.

---

## 4. Spécifications Détaillées (Option A)

### 4.1 Condition d'Affichage

```blade
@if($isMultiQuestion && !empty($message['rag_context']['multi_question']['blocks']))
    {{-- Afficher UNIQUEMENT les blocs --}}
    @include('partials.multi-question-blocks')
@else
    {{-- Afficher le contenu simple --}}
    {!! \Illuminate\Support\Str::markdown($message['content']) !!}
@endif
```

### 4.2 Structure d'un Bloc Q/R

```blade
<div class="border rounded-lg p-4 mb-3" x-data="{
    expanded: true,
    editing: false,
    question: @js($block['question']),
    answer: @js($block['answer']),
    requiresHandoff: false,
    validated: @js($block['learned'] ?? false)
}">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
            <span class="font-medium">Question {{ $block['id'] }}/{{ $blockCount }}</span>
            @if($block['is_suggestion'])
                <x-filament::badge color="warning" size="xs">Suggestion</x-filament::badge>
            @else
                <x-filament::badge color="info" size="xs">Documenté</x-filament::badge>
            @endif
        </div>
        <div x-show="validated">
            <x-filament::badge color="success" size="xs">Validée</x-filament::badge>
        </div>
    </div>

    {{-- Question --}}
    <div class="mb-3">
        <label class="text-xs text-gray-500 mb-1">Question</label>
        <div x-show="!editing" class="p-2 bg-gray-50 rounded">
            <span x-text="question"></span>
            <button x-on:click="editing = true" class="ml-2 text-primary-500">
                <x-heroicon-o-pencil class="w-3 h-3" />
            </button>
        </div>
        <textarea x-show="editing" x-model="question" rows="2"
                  class="w-full rounded border-gray-300 text-sm"></textarea>
    </div>

    {{-- Réponse --}}
    <div class="mb-3">
        <label class="text-xs text-gray-500 mb-1">Réponse</label>
        <div x-show="!editing" class="p-2 bg-gray-50 rounded prose prose-sm max-w-none">
            <div x-html="marked.parse(answer)"></div>
            <button x-on:click="editing = true" class="ml-2 text-primary-500">
                <x-heroicon-o-pencil class="w-3 h-3" />
            </button>
        </div>
        <textarea x-show="editing" x-model="answer" rows="4"
                  class="w-full rounded border-gray-300 text-sm"></textarea>
    </div>

    {{-- Options --}}
    <div class="flex items-center gap-4 mb-3" x-show="!validated">
        <label class="flex items-center gap-2 text-xs">
            <input type="checkbox" x-model="requiresHandoff" class="rounded" />
            Nécessite suivi humain
        </label>
    </div>

    {{-- Actions --}}
    <div class="flex gap-2" x-show="!validated">
        <x-filament::button size="xs" color="success"
            x-on:click="$wire.learnMultiQuestionBlock({{ $message['original_id'] }}, {{ $block['id'] }}, question, answer, requiresHandoff); validated = true; editing = false">
            Valider ce bloc
        </x-filament::button>
        <x-filament::button size="xs" color="gray" x-show="editing"
            x-on:click="editing = false; question = @js($block['question']); answer = @js($block['answer'])">
            Annuler
        </x-filament::button>
    </div>
</div>
```

### 4.3 Boutons Globaux

```blade
<div class="flex items-center justify-between pt-4 border-t mt-4">
    <div class="flex gap-2">
        {{-- Valider Tout --}}
        @php $pendingCount = collect($blocks)->where('learned', false)->count(); @endphp
        @if($pendingCount > 0)
            <x-filament::button size="sm" color="success" icon="heroicon-o-check"
                x-on:click="validateAll()">
                Valider Tout ({{ $pendingCount }})
            </x-filament::button>

            <x-filament::button size="sm" color="danger" icon="heroicon-o-x-mark"
                wire:click="rejectMessage({{ $message['original_id'] }})">
                Rejeter Tout
            </x-filament::button>
        @endif
    </div>

    {{-- Contexte RAG --}}
    <button @click="openContext = true" class="text-xs text-gray-500">
        Voir le contexte ({{ $totalSources }})
    </button>
</div>

{{-- Métadonnées --}}
<div class="text-xs text-gray-400 mt-2">
    {{ $message['created_at']->format('H:i') }} •
    {{ $message['model_used'] }} •
    {{ $message['tokens'] }} tokens
</div>
```

### 4.4 Fonction "Valider Tout"

```javascript
function validateAll() {
    // Collecter toutes les Q/R non validées avec leurs valeurs éditées
    const blocks = [];
    document.querySelectorAll('[data-block-id]').forEach(el => {
        if (!el.dataset.validated) {
            blocks.push({
                id: parseInt(el.dataset.blockId),
                question: el.querySelector('[x-model="question"]').value,
                answer: el.querySelector('[x-model="answer"]').value,
                requiresHandoff: el.querySelector('[x-model="requiresHandoff"]').checked
            });
        }
    });

    // Appeler la méthode Livewire
    $wire.validateAllMultiQuestionBlocks(messageId, blocks);
}
```

---

## 5. Backend : Nouvelles Méthodes

### ViewAiSession.php

```php
/**
 * Valide tous les blocs d'un message multi-questions en une fois.
 */
public function validateAllMultiQuestionBlocks(int $messageId, array $blocks): void
{
    $message = AiMessage::findOrFail($messageId);

    if ($message->session_id !== $this->record->id) {
        return;
    }

    try {
        foreach ($blocks as $blockData) {
            // Indexer chaque Q/R
            app(LearningService::class)->indexLearnedResponse(
                question: trim($blockData['question']),
                answer: trim($blockData['answer']),
                agentId: $this->record->agent_id,
                agentSlug: $this->record->agent->slug,
                messageId: $messageId,
                validatorId: auth()->id(),
                requiresHandoff: $blockData['requiresHandoff'] ?? false
            );

            // Marquer le bloc comme appris
            $this->updateBlockLearnedStatus($message, $blockData['id']);
        }

        // Marquer le message global comme validé
        $message->update([
            'validation_status' => 'learned',
            'validated_by' => auth()->id(),
            'validated_at' => now(),
        ]);

        // Broadcaster au client
        if ($this->record->isEscalated()) {
            broadcast(new AiMessageValidated($message));
        }

        // Email si nécessaire
        if ($this->record->user_email) {
            app(SupportService::class)->sendValidatedAiMessageByEmail(
                $this->record,
                $message,
                auth()->user()
            );
        }

        Notification::make()
            ->title('Toutes les réponses validées')
            ->body(count($blocks) . ' Q/R indexées avec succès.')
            ->success()
            ->send();

    } catch (\Throwable $e) {
        Notification::make()
            ->title('Erreur')
            ->body($e->getMessage())
            ->danger()
            ->send();
    }
}
```

---

## 6. Plan d'Implémentation

### Phase 1 : Backend
- [ ] Créer `validateAllMultiQuestionBlocks()` dans ViewAiSession.php
- [ ] Tester avec des données mock

### Phase 2 : Frontend - Refonte Bloc IA
- [ ] Créer condition : si multi-question → afficher blocs seulement
- [ ] Implémenter le nouveau design de bloc Q/R
- [ ] Ajouter édition inline (question + réponse)
- [ ] Ajouter boutons globaux (Valider Tout, Rejeter Tout)

### Phase 3 : Nettoyage
- [ ] Supprimer l'ancien affichage du contenu complet pour multi-questions
- [ ] Supprimer les anciens boutons globaux pour multi-questions
- [ ] Garder le code pour messages simples (non multi-questions)

### Phase 4 : Tests
- [ ] Tester message simple (1 Q/R)
- [ ] Tester message multi-questions (2-5 Q/R)
- [ ] Tester édition Q/R
- [ ] Tester Valider Tout
- [ ] Tester Rejeter Tout
- [ ] Tester mode Apprentissage Accéléré

---

## 7. Fichiers à Modifier

| Fichier | Action |
|---------|--------|
| `ViewAiSession.php` | Ajouter `validateAllMultiQuestionBlocks()` |
| `view-ai-session.blade.php` | Refonte complète du bloc message IA |

---

## 8. Questions pour Validation

1. **Option A confirmée ?** (Blocs uniquement, pas de contenu complet pour multi-Q)
2. **Édition inline ou formulaire popup ?**
3. **Collapse automatique des blocs validés ?**
4. **Garder la possibilité de valider bloc par bloc ?** (en plus de Valider Tout)

---

## 9. Suivi

| Date | Action | Statut |
|------|--------|--------|
| 2026-01-03 | Création document | ✅ |
| 2026-01-03 | Correction analyse (zones dans même bloc) | ✅ |
| - | Validation utilisateur | En attente |
| - | Implémentation Phase 1 | - |
| - | Implémentation Phase 2 | - |
| - | Tests | - |
