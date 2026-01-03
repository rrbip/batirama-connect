# Document de Travail : Refonte UX Bloc Suggestion IA

## Date : 2026-01-03
## Statut : EN COURS

---

## 1. Problème Identifié

Dans un message IA multi-questions, les données sont affichées **2 FOIS** sous 2 formats différents (contenu complet + blocs individuels).

---

## 2. Solution Retenue : Template Unique + Foreach

### Principe
- **UN SEUL template** de bloc Q/R
- **Mono-question** : afficher 1 bloc
- **Multi-questions** : `@foreach` sur le même template

### Avantages
- Code DRY (pas de duplication)
- UX cohérente entre mono et multi
- Maintenance simplifiée

---

## 3. Architecture

### 3.1 Extraction des Données

```php
// Dans le blade, préparer les blocs de manière uniforme
@php
    $isMultiQuestion = $message['rag_context']['multi_question']['is_multi'] ?? false;

    if ($isMultiQuestion) {
        // Multi-questions : utiliser les blocs parsés
        $qrBlocks = $message['rag_context']['multi_question']['blocks'] ?? [];
    } else {
        // Mono-question : créer un bloc unique
        $previousQuestion = '';
        if (isset($unifiedMessages[$index - 1]) && $unifiedMessages[$index - 1]['type'] === 'client') {
            $previousQuestion = $unifiedMessages[$index - 1]['content'] ?? '';
        }

        $qrBlocks = [[
            'id' => 1,
            'question' => $previousQuestion,
            'answer' => $message['content'],
            'type' => $message['rag_context']['response_type'] ?? 'unknown',
            'is_suggestion' => $message['rag_context']['is_suggestion'] ?? false,
            'learned' => $message['validation_status'] === 'learned',
        ]];
    }

    $blockCount = count($qrBlocks);
@endphp
```

### 3.2 Template Unique de Bloc Q/R

```blade
{{-- Template réutilisable pour un bloc Q/R --}}
@foreach($qrBlocks as $blockIndex => $block)
    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 {{ $blockIndex > 0 ? 'mt-3' : '' }}"
         x-data="{
             editing: false,
             question: @js($block['question']),
             answer: @js($block['answer']),
             requiresHandoff: false,
             validated: @js($block['learned'] ?? false)
         }">

        {{-- Header du bloc --}}
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                @if($blockCount > 1)
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Question {{ $block['id'] }}/{{ $blockCount }}
                    </span>
                @endif

                {{-- Badge type --}}
                @if($block['is_suggestion'] ?? false)
                    <x-filament::badge color="warning" size="xs" icon="heroicon-o-light-bulb">
                        Suggestion
                    </x-filament::badge>
                @elseif(($block['type'] ?? '') === 'documented')
                    <x-filament::badge color="info" size="xs" icon="heroicon-o-document-check">
                        Documenté
                    </x-filament::badge>
                @endif
            </div>

            {{-- Badge statut --}}
            <div x-show="validated">
                <x-filament::badge color="success" size="xs">Validé</x-filament::badge>
            </div>
        </div>

        {{-- Bannière avertissement si suggestion --}}
        @if($block['is_suggestion'] ?? false)
            <div class="mb-3 p-2 bg-warning-50 dark:bg-warning-950 border border-warning-200 dark:border-warning-800 rounded-lg">
                <div class="flex items-start gap-2">
                    <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-warning-500 flex-shrink-0 mt-0.5" />
                    <span class="text-xs text-warning-700 dark:text-warning-300">
                        <strong>Suggestion IA</strong> - Aucune source documentaire trouvée. Vérifiez avant validation.
                    </span>
                </div>
            </div>
        @endif

        {{-- Question --}}
        <div class="mb-3">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Question</label>

            {{-- Mode lecture --}}
            <div x-show="!editing" class="flex items-start gap-2">
                <div class="flex-1 p-2 bg-gray-50 dark:bg-gray-800 rounded text-sm">
                    <span x-text="question"></span>
                </div>
                <button x-on:click="editing = true" x-show="!validated"
                        class="p-1 text-gray-400 hover:text-primary-500 transition-colors">
                    <x-heroicon-o-pencil class="w-4 h-4" />
                </button>
            </div>

            {{-- Mode édition --}}
            <textarea x-show="editing" x-model="question" rows="2"
                      class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm"></textarea>
        </div>

        {{-- Réponse --}}
        <div class="mb-3">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Réponse</label>

            {{-- Mode lecture --}}
            <div x-show="!editing" class="flex items-start gap-2">
                <div class="flex-1 p-2 bg-gray-50 dark:bg-gray-800 rounded prose prose-sm dark:prose-invert max-w-none">
                    {!! \Illuminate\Support\Str::markdown($block['answer']) !!}
                </div>
                <button x-on:click="editing = true" x-show="!validated"
                        class="p-1 text-gray-400 hover:text-primary-500 transition-colors">
                    <x-heroicon-o-pencil class="w-4 h-4" />
                </button>
            </div>

            {{-- Mode édition --}}
            <textarea x-show="editing" x-model="answer" rows="6"
                      class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm"></textarea>
        </div>

        {{-- Options & Actions (si non validé) --}}
        <div x-show="!validated" class="pt-3 border-t border-gray-100 dark:border-gray-700">
            {{-- Checkbox handoff --}}
            <div class="mb-3">
                <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" x-model="requiresHandoff"
                           class="rounded border-gray-300 dark:border-gray-600 text-warning-600 focus:ring-warning-500" />
                    <span>Nécessite toujours un suivi humain</span>
                    <x-heroicon-o-user-group class="w-4 h-4 text-warning-500" />
                </label>
            </div>

            {{-- Boutons --}}
            <div class="flex flex-wrap gap-2">
                {{-- Valider --}}
                <x-filament::button size="xs" color="success" icon="heroicon-o-check"
                    x-on:click="$wire.learnMultiQuestionBlock({{ $message['original_id'] }}, {{ $block['id'] }}, question, answer, requiresHandoff); validated = true; editing = false">
                    Valider
                </x-filament::button>

                {{-- Rejeter (mode normal) ou Refuser et Rédiger (mode accéléré) --}}
                @if(!($block['is_direct_match'] ?? false))
                    @if($this->isAcceleratedLearningMode())
                        <x-filament::button size="xs" color="danger" icon="heroicon-o-x-mark"
                            wire:click="rejectAndUnlock({{ $message['original_id'] }})">
                            Refuser et Rédiger
                        </x-filament::button>
                    @else
                        <x-filament::button size="xs" color="danger" icon="heroicon-o-x-mark"
                            wire:click="rejectMessage({{ $message['original_id'] }})">
                            Rejeter
                        </x-filament::button>
                    @endif
                @endif

                {{-- Annuler édition --}}
                <x-filament::button size="xs" color="gray" x-show="editing"
                    x-on:click="editing = false; question = @js($block['question']); answer = @js($block['answer'])">
                    Annuler
                </x-filament::button>
            </div>
        </div>
    </div>
@endforeach
```

### 3.3 Footer Global (Multi-questions uniquement)

```blade
@if($blockCount > 1)
    <div class="flex items-center justify-between pt-4 mt-4 border-t border-gray-200 dark:border-gray-700">
        <div class="flex gap-2">
            @php $pendingCount = collect($qrBlocks)->where('learned', false)->count(); @endphp
            @if($pendingCount > 0)
                <x-filament::button size="sm" color="success" icon="heroicon-o-check-circle"
                    x-on:click="validateAllBlocks()">
                    Valider Tout ({{ $pendingCount }})
                </x-filament::button>
            @endif
        </div>

        {{-- Contexte RAG --}}
        @if(!empty($message['rag_context']))
            <button @click="openContext = true" class="text-xs text-gray-500 hover:text-gray-700">
                <x-heroicon-o-document-magnifying-glass class="w-4 h-4 inline" />
                Voir le contexte
            </button>
        @endif
    </div>
@endif
```

### 3.4 Métadonnées (Toujours affichées)

```blade
<div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-400">
    <span>{{ $message['created_at']->format('H:i') }}</span>
    <div class="flex items-center gap-2">
        @if($message['model_used'])
            <span>{{ $message['model_used'] }}</span>
        @endif
        @if($message['tokens'])
            <span>{{ $message['tokens'] }} tokens</span>
        @endif
    </div>
</div>
```

---

## 4. Structure Finale du Message IA

```
┌─────────────────────────────────────────────────────────────┐
│ Message IA                                                  │
├─────────────────────────────────────────────────────────────┤
│ [Header: icône CPU + nom agent + badges statut]             │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ [Question 1/N si multi] [Badge: Suggestion/Documenté]   │ │
│ │ [Bannière avertissement si suggestion]                  │ │
│ │ Q: _________________________________ [Éditer]           │ │
│ │ R: _________________________________ [Éditer]           │ │
│ │    (markdown rendu)                                     │ │
│ │ [ ] Nécessite suivi humain                              │ │
│ │ [Valider] [Rejeter]                                     │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ [Question 2/N] ... (même template)                      │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ [Valider Tout (N)] (si multi)            [Voir contexte]    │
│ ──────────────────────────────────────────────────────────  │
│ 12:34 • gemini-2.0-flash • 1234 tokens                      │
└─────────────────────────────────────────────────────────────┘
```

---

## 5. Plan d'Implémentation

### Phase 1 : Préparer les données
- [ ] Créer la logique d'extraction uniforme des blocs Q/R
- [ ] Mono → 1 bloc, Multi → N blocs

### Phase 2 : Créer le template unique
- [ ] Supprimer l'ancien affichage (contenu complet + blocs séparés)
- [ ] Implémenter le nouveau template de bloc Q/R
- [ ] Foreach sur les blocs

### Phase 3 : Boutons et actions
- [ ] Valider par bloc
- [ ] Rejeter
- [ ] Valider Tout (multi)
- [ ] Édition inline Q/R

### Phase 4 : Contexte RAG
- [ ] Garder le modal existant
- [ ] Déplacer le bouton dans le footer

### Phase 5 : Tests
- [ ] Message mono-question simple
- [ ] Message mono-question suggestion
- [ ] Message multi-questions (2-5)
- [ ] Mode Apprentissage Accéléré

---

## 6. Fichiers à Modifier

| Fichier | Action |
|---------|--------|
| `view-ai-session.blade.php` | Refonte section message IA (lignes ~232-631) |
| `ViewAiSession.php` | Aucun changement (méthodes existantes suffisent) |

---

## 7. Suivi

| Date | Action | Statut |
|------|--------|--------|
| 2026-01-03 | Création document | ✅ |
| 2026-01-03 | Correction analyse | ✅ |
| 2026-01-03 | Validation approche DRY (template unique + foreach) | ✅ |
| - | Implémentation | En attente |
