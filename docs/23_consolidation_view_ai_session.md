# Document de Travail : Consolidation UI ViewAiSession

## Date : 2026-01-03
## Statut : EN COURS

---

## 1. Contexte du Problème

### Symptômes Observés
Le développement précédent a créé **deux zones UI distinctes** dans la page ViewAiSession qui se chevauchent fonctionnellement :

1. **Zone Haute (Messages IA inline)** - Lignes ~232-631
   - Boutons par message IA : Valider, Corriger, Rejeter
   - Badges : En attente, Validée, Apprise, Rejetée
   - Badges de type : Suggestion IA, Documenté, Multi-questions
   - Formulaires inline pour validation/correction
   - Blocs multi-questions avec validation individuelle
   - Modal contexte RAG

2. **Zone Basse (Zone de réponse Support)** - Lignes ~1359-1493
   - Zone de réponse pour agent support
   - Suggestion IA (via RAG)
   - Mode Apprentissage Accéléré (zone verrouillée/déverrouillée)
   - Boutons : Envoyer, Suggérer, Envoyer et Apprendre
   - Passer (cas exceptionnel)

### Problèmes Identifiés

| Zone | Manque | Présent |
|------|--------|---------|
| **Zone Haute** | Retours à la ligne dans le contenu, boutons contextuels selon mode | Badges, validation par bloc multi-questions, modal RAG |
| **Zone Basse** | Badges de type réponse, blocs multi-questions, boutons Corriger/Refuser | Zone de saisie libre, mode accéléré verrouillé |

### Impact Utilisateur
- Confusion sur quelle zone utiliser
- Duplication des actions de validation
- Flux de travail non intuitif
- Impossibilité de valider toutes les Q/R en un seul clic

---

## 2. Fonctionnalités Existantes à Préserver

### 2.1 Validation des Messages IA

#### Boutons d'Action (Zone Haute actuelle)
```blade
@if($message['is_pending_validation'])
    - Valider : Ouvre formulaire avec question modifiable
    - Corriger : Ouvre formulaire avec question ET réponse modifiables
    - Rejeter : Simple rejet (mode normal) ou "Refuser et Rédiger" (mode accéléré)
```

#### Formulaire de Validation
- Champ question (pré-rempli avec question client)
- Checkbox "Nécessite toujours un suivi humain" (requires_handoff)
- Boutons Enregistrer / Annuler

#### Formulaire de Correction
- Champ question (pré-rempli)
- Champ réponse (pré-rempli avec réponse IA)
- Checkbox requires_handoff
- Boutons Enregistrer / Annuler

### 2.2 Multi-Questions (Blocs Q/R)

Quand `$isMultiQuestion` est vrai :
- Affichage de N blocs individuels
- Chaque bloc a :
  - Numéro de question
  - Badge de type (Suggestion/Documenté)
  - Aperçu Q/R limité
  - Bouton "Valider" par bloc
  - Bannière d'avertissement si suggestion
  - Formulaire d'édition Q/R + handoff
- Compteur "X/Y validés"

### 2.3 Mode Apprentissage Accéléré

Quand `$this->isAcceleratedLearningMode()` est vrai :

**Zone verrouillée** (`!$this->canRespondFreely`) :
- Message explicatif des 3 options
- Instructions : Valider / Corriger / Rejeter depuis la réponse IA
- Bouton "Passer (cas exceptionnel)" si autorisé

**Zone déverrouillée** (`$this->canRespondFreely`) :
- Zone de saisie libre
- Info "Votre réponse sera indexée"
- Bouton "Envoyer et Apprendre"

### 2.4 Suggestion IA (RAG)

- Affichage de la suggestion proposée
- Boutons "Utiliser" / "Ignorer"
- Rendu markdown de la suggestion

### 2.5 Badges et Indicateurs

#### Badges de statut validation
- `pending` : Warning "En attente"
- `validated` : Success "Validée"
- `learned` : Primary "Apprise"
- `rejected` : Danger "Rejetée"

#### Badges de type réponse
- `is_suggestion` : Warning "Suggestion IA" + icône ampoule
- `documented` : Info "Documenté" + icône document
- `isMultiQuestion` : Gray "X questions" + icône queue

### 2.6 Modal Contexte RAG

- Stats de génération
- Détection de catégorie
- System prompt envoyé
- Historique conversation
- Sources apprises
- Documents RAG
- Évaluation Handoff
- Rapport complet (copiable)

### 2.7 Bouton "Utiliser comme modèle"

Visible si session escaladée :
- Copie le contenu IA (nettoyé de HANDOFF_NEEDED) dans le champ de réponse

---

## 3. Exigences UX (Demandées par l'Utilisateur)

### 3.1 Bouton "Valider" Unique
> "Un seul bouton 'Valider' pour toutes les questions/réponses qui seront envoyées en un bloc au client"

**Comportement attendu :**
- En mode multi-questions : valider TOUTES les Q/R d'un coup
- Envoi groupé au client (pas de validation bloc par bloc obligatoire)
- Possibilité de modifier chaque question avant validation globale

### 3.2 Modification Universelle des Questions
> "Le bouton 'Valider' permet de modifier la question"

Déjà implémenté dans le formulaire de validation.

### 3.3 "Corriger" = Q + R Modifiables
> "Le bouton 'Corriger' permet de modifier la question ET la réponse"

Déjà implémenté dans le formulaire de correction.

### 3.4 "Refuser" = Q + R Modifiables
> "Le bouton 'Refuser et Rédiger' permet de modifier la question ET la réponse"

**À implémenter :**
- En mode accéléré : après rejet, ouvrir un formulaire Q/R
- La réponse écrite sera indexée avec la question (éventuellement modifiée)

---

## 4. Proposition de Consolidation

### 4.1 Architecture Proposée

```
┌─────────────────────────────────────────────────────────────┐
│                    Zone Messages Unifiée                    │
│  (Client → IA → Support → Client → IA → ...)                │
├─────────────────────────────────────────────────────────────┤
│  Message Client (bleu, droite)                              │
├─────────────────────────────────────────────────────────────┤
│  Message IA (gris, gauche)                                  │
│  ┌─────────────────────────────────────────────────────────┐│
│  │ [Badges: Statut + Type + Multi-questions]               ││
│  │ [Bannière avertissement si suggestion]                  ││
│  │ [Contenu avec retours à la ligne préservés]             ││
│  │ ─────────────────────────────────────────────────────── ││
│  │ [Boutons: Valider Tout | Corriger | Refuser et Rédiger] ││
│  │ (visibles si pending_validation)                        ││
│  │ ─────────────────────────────────────────────────────── ││
│  │ [Blocs Multi-Questions] (si multi)                      ││
│  │   - Affichage groupé, édition inline possible           ││
│  │   - Pas de validation bloc par bloc obligatoire         ││
│  │ ─────────────────────────────────────────────────────── ││
│  │ [Bouton contexte RAG]                                   ││
│  └─────────────────────────────────────────────────────────┘│
├─────────────────────────────────────────────────────────────┤
│  Zone Réponse Support (si escaladé et non résolu)           │
│  ┌─────────────────────────────────────────────────────────┐│
│  │ [Mode Accéléré Verrouillé] ou [Zone Libre]              ││
│  │ [Suggestion IA si disponible]                           ││
│  │ [Champ de saisie + Boutons]                             ││
│  └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
```

### 4.2 Changements Proposés

#### A. Bouton "Valider Tout" pour Multi-Questions

**Nouveau comportement :**
1. Si multi-questions : afficher un bouton "Valider Tout" en plus des boutons par bloc
2. Ce bouton ouvre un formulaire récapitulatif de TOUTES les Q/R
3. Chaque Q/R est éditable dans ce formulaire
4. Un seul clic final pour tout valider et envoyer

**Nouvelle méthode PHP à créer :**
```php
public function validateAllMultiQuestionBlocks(int $messageId, array $blocks): void
{
    // $blocks = [
    //   ['id' => 1, 'question' => '...', 'answer' => '...', 'requires_handoff' => false],
    //   ['id' => 2, 'question' => '...', 'answer' => '...', 'requires_handoff' => false],
    // ]
    // - Indexer chaque Q/R
    // - Broadcaster la validation au client
    // - Envoyer email si user_email
}
```

#### B. "Refuser et Rédiger" avec Formulaire Q/R

**Nouveau comportement :**
1. Clic sur "Refuser et Rédiger"
2. Au lieu de déverrouiller la zone basse, ouvrir un formulaire inline
3. Question pré-remplie (modifiable)
4. Réponse vide (à rédiger)
5. Checkbox requires_handoff
6. Bouton "Envoyer et Apprendre"

**Avantage :** Cohérence avec les autres formulaires, pas de zone basse séparée.

#### C. Unification Mode Accéléré

**Supprimer la zone verrouillée en bas.**

À la place :
- Si mode accéléré ET message IA pending :
  - Afficher les 3 boutons : Valider / Corriger / Refuser et Rédiger
  - Chaque bouton ouvre son formulaire inline
- Si mode accéléré ET aucun message pending :
  - Autoriser la saisie libre en bas (pour les questions suivantes)

#### D. Préservation des Retours à la Ligne

Vérifier que le contenu IA est rendu avec `{!! nl2br(e($content)) !!}` ou mieux via markdown qui gère les retours à la ligne.

**Actuel :**
```blade
{!! \Illuminate\Support\Str::markdown($message['content']) !!}
```

Le markdown préserve déjà les retours à la ligne si double-saut ou `<br>`. Vérifier le format du contenu stocké.

---

## 5. Plan d'Implémentation

### Phase 1 : Backend (ViewAiSession.php)

- [ ] **5.1** Créer méthode `validateAllMultiQuestionBlocks(int $messageId, array $blocks)`
- [ ] **5.2** Modifier `rejectAndUnlock()` pour accepter un formulaire Q/R inline
- [ ] **5.3** Créer méthode `rejectAndLearnWithEdit(int $messageId, string $question, string $answer, bool $requiresHandoff)`

### Phase 2 : Frontend (Blade)

- [ ] **5.4** Ajouter bouton "Valider Tout" pour multi-questions
- [ ] **5.5** Créer formulaire récapitulatif multi-questions (Alpine.js)
- [ ] **5.6** Modifier "Refuser et Rédiger" pour ouvrir formulaire inline (pas zone basse)
- [ ] **5.7** Simplifier/supprimer la zone verrouillée du mode accéléré
- [ ] **5.8** Vérifier rendu retours à la ligne dans contenu IA

### Phase 3 : Nettoyage

- [ ] **5.9** Supprimer code dupliqué de la zone basse (si remplacé par inline)
- [ ] **5.10** Tester tous les modes : Simple, Multi-Q, Accéléré, Support
- [ ] **5.11** Vérifier régressions sur boutons existants

---

## 6. Tests à Effectuer

### 6.1 Mode Simple (1 question)
- [ ] Valider avec question modifiée
- [ ] Corriger avec Q/R modifiées
- [ ] Rejeter simple
- [ ] Refuser et Rédiger (mode accéléré)

### 6.2 Mode Multi-Questions
- [ ] Valider bloc par bloc (conserver pour flexibilité)
- [ ] Valider Tout en un clic
- [ ] Modifier Q/R dans le récapitulatif
- [ ] Vérifier envoi groupé au client

### 6.3 Mode Apprentissage Accéléré
- [ ] Zone verrouillée → actions depuis message IA
- [ ] Refuser et Rédiger → formulaire inline
- [ ] Passer (si autorisé) → zone libre en bas

### 6.4 Support Humain
- [ ] Zone de réponse fonctionnelle
- [ ] Suggestion IA
- [ ] Envoyer message
- [ ] Session résolue = zone masquée

---

## 7. Fichiers Impactés

| Fichier | Modifications |
|---------|---------------|
| `app/Filament/Resources/AiSessionResource/Pages/ViewAiSession.php` | Nouvelles méthodes PHP |
| `resources/views/filament/resources/ai-session-resource/pages/view-ai-session.blade.php` | Refonte UI |

---

## 8. Risques et Mitigations

| Risque | Impact | Mitigation |
|--------|--------|------------|
| Régression sur validation existante | Élevé | Tests exhaustifs avant merge |
| Perte de la validation par bloc | Moyen | Conserver en plus du "Valider Tout" |
| Confusion utilisateur sur nouveaux boutons | Moyen | Labels clairs + tooltips |
| WebSocket ne broadcast pas correctement | Élevé | Tester avec client standalone |

---

## 9. Questions Ouvertes

1. **Validation par bloc obligatoire ?**
   - Option A : Garder les deux (bloc + tout)
   - Option B : Supprimer bloc, forcer tout

2. **Zone basse : garder ou supprimer ?**
   - Si tous les formulaires sont inline, la zone basse devient inutile
   - Exception : mode libre après "Passer"

3. **Envoi au client : immédiat ou différé ?**
   - Actuellement : validation = envoi immédiat
   - Alternative : valider tout, puis bouton "Envoyer au client"

---

## 10. Décisions Prises

*(À remplir après discussion)*

- [ ] Option validation par bloc : ___
- [ ] Zone basse : ___
- [ ] Envoi immédiat vs différé : ___

---

## 11. Suivi des Modifications

| Date | Auteur | Modification |
|------|--------|--------------|
| 2026-01-03 | Claude | Création du document |
