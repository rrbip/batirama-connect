# Cahier des Charges : Refonte UX Bloc Suggestion IA

## Date : 2026-01-03
## Statut : EN COURS DE VALIDATION

---

## 1. Objectif

Refondre l'affichage des messages IA dans ViewAiSession pour :
- Éliminer la duplication de données (contenu complet + blocs séparés)
- Avoir un template unique réutilisable (DRY)
- Simplifier l'UX tout en conservant les fonctionnalités

---

## 2. Solution Retenue

### Principe DRY
- **UN SEUL template** de bloc Q/R
- **Mono-question** : 1 bloc
- **Multi-questions** : `@foreach` sur le même template

### Structure

```
┌─────────────────────────────────────────────────────────────┐
│ Message IA                                                  │
├─────────────────────────────────────────────────────────────┤
│ [Header: icône + nom agent]                                 │
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
│ (répété N fois si multi-questions)                          │
│                                                             │
│ ──────────────────────────────────────────────────────────  │
│ [Envoyer]                                  [Voir contexte]  │
│ ──────────────────────────────────────────────────────────  │
│ 12:34 • gemini-2.0-flash • 1234 tokens                      │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Spécifications Fonctionnelles

### 3.1 Préparation des Données

```php
@php
    $isMultiQuestion = $message['rag_context']['multi_question']['is_multi'] ?? false;

    if ($isMultiQuestion) {
        // Multi-questions : utiliser les blocs parsés
        $qrBlocks = $message['rag_context']['multi_question']['blocks'] ?? [];
    } else {
        // Mono-question : créer un bloc unique
        $previousQuestion = ''; // récupérer du message client précédent
        $qrBlocks = [[
            'id' => 1,
            'question' => $previousQuestion,
            'answer' => $message['content'],
            'type' => $message['rag_context']['response_type'] ?? 'unknown',
            'is_suggestion' => $message['rag_context']['is_suggestion'] ?? false,
            'learned' => $message['validation_status'] === 'learned',
        ]];
    }
@endphp
```

### 3.2 Template de Bloc Q/R

| Élément | Description |
|---------|-------------|
| **Header** | "Question X/N" (si multi) + Badge type |
| **Badge type** | `Suggestion` (warning) ou `Documenté` (info) |
| **Bannière** | Avertissement si suggestion (vérifier avant validation) |
| **Question** | Affichage + bouton Éditer → textarea |
| **Réponse** | Affichage markdown + bouton Éditer → textarea |
| **Checkbox** | "Nécessite toujours un suivi humain" |
| **Boutons** | Valider + Rejeter (ou "Refuser et Rédiger" si mode accéléré) |

### 3.3 Footer Global (Mono ET Multi)

| Élément | Description |
|---------|-------------|
| **Envoyer** | TODO: Préciser le comportement exact |
| **Voir contexte** | Ouvre le modal RAG existant |

### 3.4 Métadonnées

- Heure (HH:mm)
- Modèle utilisé
- Nombre de tokens

---

## 4. Notion de "Correction" - Clarification

### Ancien Comportement

| Action | Comportement |
|--------|--------------|
| **Valider** | Indexe Q + R originale, `validation_status = validated` |
| **Corriger** | Ouvre formulaire, indexe Q + R modifiée, `validation_status = learned`, stocke `corrected_content` |

### Nouveau Comportement

| Action | Comportement |
|--------|--------------|
| **Éditer inline** | Modifie Q et/ou R dans les textareas |
| **Valider** | Indexe Q + R (éditées ou non) |

**Logique backend à adapter :**
```php
// Si la réponse a été modifiée par rapport à l'originale
if ($editedAnswer !== $originalAnswer) {
    $message->update(['corrected_content' => $editedAnswer]);
    $message->update(['validation_status' => 'learned']);
} else {
    $message->update(['validation_status' => 'validated']);
}
```

**Impact :**
- `corrected_content` est toujours stocké si modification
- `RebuildAgentIndexJob` continue de fonctionner (`corrected_content ?? content`)
- `DispatcherService` continue de fonctionner (historique)

---

## 5. Questions Ouvertes

### 5.1 Bouton "Envoyer" - Comportement ?

Que doit faire le bouton "Envoyer" dans le footer ?

- [ ] **Option A** : Valider TOUS les blocs non validés d'un coup et broadcaster au client
- [ ] **Option B** : Simplement broadcaster au client (sans indexer)
- [ ] **Option C** : Envoyer email si `user_email` présent
- [ ] **Option D** : Autre (préciser)

### 5.2 Bouton "Rejeter" - Comportement multi-questions ?

En multi-questions, "Rejeter" rejette :
- [ ] **Option A** : Uniquement le bloc concerné
- [ ] **Option B** : Tout le message (tous les blocs)

### 5.3 Affichage si Déjà Validé ?

Quand un bloc est déjà validé (`learned` ou `validated`) :
- [ ] **Option A** : Afficher en lecture seule (pas d'édition possible)
- [ ] **Option B** : Masquer le bloc (collapsed)
- [ ] **Option C** : Afficher normalement mais boutons grisés

---

## 6. Fichiers Impactés

| Fichier | Modifications |
|---------|---------------|
| `view-ai-session.blade.php` | Refonte section message IA (lignes ~232-631) |
| `ViewAiSession.php` | Adapter les méthodes de validation pour détecter les modifications |

---

## 7. Plan d'Implémentation (après validation cahier des charges)

1. **Backend** : Adapter les méthodes de validation
2. **Frontend** : Supprimer ancien code, implémenter nouveau template
3. **Tests** : Mono, multi, édition, modes

---

## 8. Validation

| Point | Validé ? |
|-------|----------|
| Template unique + foreach | ⏳ |
| Footer global (mono ET multi) | ⏳ |
| Bouton "Envoyer" - comportement | ❓ À préciser |
| Gestion "correction" simplifiée | ⏳ |
| Bouton "Rejeter" en multi | ❓ À préciser |
| Affichage blocs validés | ❓ À préciser |

---

## 9. Suivi

| Date | Action | Statut |
|------|--------|--------|
| 2026-01-03 | Création document | ✅ |
| 2026-01-03 | Correction analyse | ✅ |
| 2026-01-03 | Approche DRY validée | ✅ |
| 2026-01-03 | Cahier des charges v1 | ✅ |
| - | Réponses questions ouvertes | En attente |
| - | Validation finale | En attente |
| - | Implémentation | En attente |
