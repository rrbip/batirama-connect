# Cahier des Charges : Refonte UX Bloc Suggestion IA

## Date : 2026-01-03
## Statut : ✅ IMPLÉMENTÉ

---

## 1. Objectif

Refondre l'affichage des messages IA dans ViewAiSession pour :
- Éliminer la duplication de données (contenu complet + blocs séparés)
- Avoir un template unique réutilisable (DRY)
- Simplifier l'UX tout en conservant toutes les fonctionnalités

---

## 2. Architecture DRY

### Principe
- **UN SEUL template** de bloc Q/R
- **Mono-question** : 1 bloc
- **Multi-questions** : `@foreach` sur le même template

### Préparation des Données

```php
@php
    $isMultiQuestion = $message['rag_context']['multi_question']['is_multi'] ?? false;

    if ($isMultiQuestion) {
        $qrBlocks = $message['rag_context']['multi_question']['blocks'] ?? [];
    } else {
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
            'learned' => in_array($message['validation_status'], ['learned', 'validated']),
        ]];
    }
    $blockCount = count($qrBlocks);
@endphp
```

---

## 3. Structure UI Finale

```
┌─────────────────────────────────────────────────────────────┐
│ Message IA                                                  │
├─────────────────────────────────────────────────────────────┤
│ [Header: icône CPU + nom agent + badges]                    │
│                                                             │
│ ┌─ Bloc 1 ─────────────────────────────────────────────────┐│
│ │ [Question 1/N si multi] [Badge: Suggestion/Documenté]    ││
│ │ [Bannière avertissement si suggestion]                   ││
│ │                                                          ││
│ │ Question:                                                ││
│ │ ┌──────────────────────────────────┐ [Modifier]          ││
│ │ │ Texte de la question...          │                     ││
│ │ └──────────────────────────────────┘                     ││
│ │                                                          ││
│ │ Réponse:                                                 ││
│ │ ┌──────────────────────────────────┐ [Modifier]          ││
│ │ │ Texte de la réponse (markdown)   │                     ││
│ │ └──────────────────────────────────┘                     ││
│ │                                                          ││
│ │ [ ] Nécessite toujours un suivi humain                   ││
│ │                                                          ││
│ │ [Valider] [Rejeter]                                      ││
│ └──────────────────────────────────────────────────────────┘│
│                                                             │
│ (répété N fois si multi-questions)                          │
│                                                             │
│ ════════════════════════════════════════════════════════════│
│ [Envoyer]                                   [Voir contexte] │
│ ════════════════════════════════════════════════════════════│
│ 12:34 • gemini-2.0-flash • 1234 tokens                      │
└─────────────────────────────────────────────────────────────┘
```

---

## 4. Comportement des Boutons

### 4.1 Bouton "Valider" (par bloc)

**Action :**
- Marque le bloc comme "prêt à envoyer"
- Passe le bloc en **lecture seule**
- Affiche un bouton "Modifier" pour réactiver l'édition
- **N'envoie rien** encore (pas de broadcast, pas d'indexation)

**État visuel :**
- Badge "Validé" (success) affiché
- Champs Q/R en lecture seule
- Bouton "Modifier" visible tant que "Envoyer" pas cliqué

### 4.2 Bouton "Rejeter" (par bloc)

**Action :**
- Supprime le bloc de la réponse finale
- Le bloc disparaît visuellement (ou grisé/barré)

**Comportement selon contexte :**
- **Mono-question** : La suggestion IA disparaît complètement
- **Multi-questions** : Seul ce bloc disparaît, les autres restent
- **Tous blocs rejetés** : Équivalent à "Passer" (aucune réponse IA envoyée)

**Mode accéléré :**
- Bouton = "Refuser et Rédiger" (`rejectAndUnlock()`)

### 4.3 Bouton "Envoyer" (footer global)

**Action complète :**
1. Prend tous les blocs **validés** (non rejetés)
2. Pour chaque bloc :
   - Indexe Q/R dans Qdrant (`learned_responses` + collection agent)
   - Dédoublonne par message_id et par similarité (score > 0.98)
3. Met à jour le message : `validation_status = 'learned'`, `corrected_content`
4. Broadcast `AiMessageValidated` au client (WebSocket)
5. Envoie email si `user_email` présent
6. Affiche notification de succès

**Équivalent ancien :** Bouton "Corriger" → "Enregistrer"

### 4.4 Bouton "Modifier" (après validation)

**Action :**
- Réactive l'édition du bloc
- Visible uniquement si bloc validé ET "Envoyer" pas encore cliqué

---

## 5. Gestion de `corrected_content`

### Règle : Toujours Stocker

Pour la **rétro-compatibilité**, on stocke TOUJOURS dans `corrected_content` :
- Même si l'utilisateur n'a rien modifié
- `corrected_content` = "réponse validée par humain"

**Impact :**
- `RebuildAgentIndexJob` : `corrected_content ?? content` → fonctionne
- `DispatcherService` : historique utilise `corrected_content ?? content` → fonctionne

---

## 6. Éléments UI à Préserver

### Header Message IA
- [x] Icône CPU (`heroicon-o-cpu-chip`)
- [x] Nom agent (`$message['sender_name']`)
- [x] Badge statut : En attente / Validée / Apprise / Rejetée
- [x] Badge type : Suggestion IA / Documenté
- [x] Badge multi : "X questions"

### Bannières
- [x] Avertissement suggestion (si `is_suggestion && pending`)
- [x] Indicateur "Contenu corrigé" (si `learned && corrected_content`)
- [x] Spinner "Génération en cours" (si content vide)

### Contenu Bloc Q/R
- [x] Question éditable inline
- [x] Réponse éditable inline (rendu markdown en lecture)
- [x] Badge type par bloc (Suggestion/Documenté)
- [x] Badge "Validé" par bloc
- [x] Checkbox "Nécessite suivi humain"
- [x] Bannière avertissement par bloc (si suggestion)

### Footer
- [x] Bouton "Envoyer"
- [x] Bouton "Voir le contexte (X sources)"
- [x] Compteur "X/Y validés" (multi-questions)

### Métadonnées
- [x] Heure (H:i)
- [x] Modèle utilisé
- [x] Tokens
- [x] Bouton "Utiliser" (copie vers zone support, si escaladé)

### Modal Contexte RAG
- [x] Stats de génération
- [x] Détection catégorie
- [x] System prompt
- [x] Historique conversation
- [x] Sources apprises
- [x] Documents RAG
- [x] Évaluation Handoff
- [x] Rapport copiable

---

## 7. Indexation Qdrant

### Double Indexation (confirmé)
1. **`learned_responses`** : Collection globale d'apprentissage
2. **`{agent_collection}`** : Collection spécifique agent (type=`qa_pair`)

### Dédoublonnage (confirmé)
1. **Par `message_id`** : `deleteExistingPointsForMessage()` supprime les anciens points
2. **Par similarité** : `deleteSimilarQuestions()` supprime si score > 0.98

---

## 8. Fichiers à Modifier

| Fichier | Modifications |
|---------|---------------|
| `ViewAiSession.php` | Nouvelle méthode `sendValidatedBlocks()` |
| `view-ai-session.blade.php` | Refonte complète section message IA (lignes ~232-700) |

---

## 9. Plan d'Implémentation

### Phase 1 : Backend ✅
- [x] Créer `sendValidatedBlocks(int $messageId, array $blocks)` dans ViewAiSession.php
- [x] Créer `rejectBlock(int $messageId, int $blockId, int $totalBlocks)` dans ViewAiSession.php
- [x] Adapter pour gérer mono ET multi uniformément

### Phase 2 : Frontend ✅
- [x] Supprimer l'ancien affichage (contenu complet + blocs séparés)
- [x] Implémenter template unique de bloc Q/R avec Alpine.js `x-for`
- [x] Ajouter foreach pour multi-questions
- [x] Implémenter états : édition / validé / rejeté
- [x] Ajouter footer global avec "Envoyer" et "Tout valider"

### Phase 3 : Tests
- [ ] Mono-question simple
- [ ] Mono-question suggestion
- [ ] Multi-questions (2-5 blocs)
- [ ] Rejeter un bloc en multi
- [ ] Rejeter tous les blocs
- [ ] Mode Apprentissage Accéléré

---

## 10. Suivi

| Date | Action | Statut |
|------|--------|--------|
| 2026-01-03 | Création document | ✅ |
| 2026-01-03 | Analyse du problème | ✅ |
| 2026-01-03 | Approche DRY validée | ✅ |
| 2026-01-03 | Vérification LearningService | ✅ |
| 2026-01-03 | Inventaire fonctionnalités | ✅ |
| 2026-01-03 | Cahier des charges validé | ✅ |
| 2026-01-03 | Backend (sendValidatedBlocks, rejectBlock) | ✅ |
| 2026-01-03 | Frontend (template Q/R unifié) | ✅ |
| 2026-01-03 | Implémentation complète | ✅ |
