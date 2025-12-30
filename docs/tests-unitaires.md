# Tests Unitaires - Pipeline RAG

## Structure des tests

```
tests/
├── Unit/
│   ├── Services/
│   │   └── Pipeline/
│   │       ├── MarkdownChunkerServiceTest.php
│   │       ├── QrGeneratorServiceTest.php
│   │       └── PipelineOrchestratorServiceTest.php
│   └── Jobs/
│       └── Pipeline/
│           ├── ProcessHtmlToMarkdownJobTest.php
│           ├── ProcessMarkdownToQrJobTest.php
│           └── ProcessPdfToImagesJobTest.php
├── Feature/
│   ├── Auth/
│   │   ├── UserAuthenticationTest.php
│   │   └── UserRegistrationTest.php
│   └── Pipeline/
│       └── PipelineIntegrationTest.php
└── TestCase.php
```

## Tests à implémenter

### 1. MarkdownChunkerService

**Fichier:** `tests/Unit/Services/Pipeline/MarkdownChunkerServiceTest.php`

| Test | Description |
|------|-------------|
| `test_chunks_markdown_by_headers` | Vérifie le découpage par titres H1, H2, H3 |
| `test_fallback_for_headerless_content` | Vérifie le fallback quand pas de headers |
| `test_respects_max_chunk_size` | Vérifie que les chunks ne dépassent pas la taille max |
| `test_preserves_hierarchy` | Vérifie que le contexte parent est conservé |
| `test_handles_empty_content` | Vérifie la gestion du contenu vide |

### 2. QrGeneratorService

**Fichier:** `tests/Unit/Services/Pipeline/QrGeneratorServiceTest.php`

| Test | Description |
|------|-------------|
| `test_generates_questions_from_chunk` | Vérifie la génération de Q/R depuis un chunk |
| `test_handles_llm_timeout` | Vérifie la gestion du timeout LLM |
| `test_parses_json_response` | Vérifie le parsing de la réponse JSON du LLM |
| `test_handles_malformed_json` | Vérifie la gestion des réponses mal formées |
| `test_retries_on_failure` | Vérifie les tentatives de retry |

### 3. PipelineOrchestratorService

**Fichier:** `tests/Unit/Services/Pipeline/PipelineOrchestratorServiceTest.php`

| Test | Description |
|------|-------------|
| `test_determines_correct_pipeline_for_pdf` | Pipeline PDF → Images → Markdown → Q/R |
| `test_determines_correct_pipeline_for_html` | Pipeline HTML → Markdown → Q/R |
| `test_determines_correct_pipeline_for_image` | Pipeline Image → Markdown → Q/R |
| `test_starts_pipeline_correctly` | Vérifie l'initialisation des étapes |
| `test_marks_step_completed` | Vérifie la mise à jour du statut |
| `test_marks_step_failed` | Vérifie la gestion des erreurs |
| `test_relaunch_step` | Vérifie la relance d'une étape |

### 4. ProcessHtmlToMarkdownJob

**Fichier:** `tests/Unit/Jobs/Pipeline/ProcessHtmlToMarkdownJobTest.php`

| Test | Description |
|------|-------------|
| `test_converts_html_to_markdown` | Vérifie la conversion HTML → Markdown |
| `test_fetches_from_source_url` | Vérifie le fetch depuis URL si pas de fichier local |
| `test_stores_fetched_content` | Vérifie le stockage du contenu téléchargé |
| `test_dispatches_next_step` | Vérifie le dispatch de l'étape suivante |
| `test_handles_missing_content` | Vérifie la gestion du contenu manquant |

### 5. Tests d'intégration

**Fichier:** `tests/Feature/Pipeline/PipelineIntegrationTest.php`

| Test | Description |
|------|-------------|
| `test_full_html_pipeline` | Pipeline complet HTML → chunks indexés |
| `test_full_pdf_pipeline` | Pipeline complet PDF → chunks indexés |
| `test_pipeline_recovery_after_failure` | Reprise après échec |
| `test_relaunch_from_specific_step` | Relance depuis une étape spécifique |

### 6. Tests Utilisateurs (Authentification)

**Fichier:** `tests/Feature/Auth/UserAuthenticationTest.php`

| Test | Description |
|------|-------------|
| `test_user_can_register` | Vérifie la création d'un compte utilisateur |
| `test_registered_user_can_login` | Vérifie le login après création de compte |
| `test_user_can_update_profile` | Vérifie la modification du profil |
| `test_updated_user_can_login` | Vérifie le login après modification (email/password) |
| `test_user_cannot_login_with_old_password` | Vérifie que l'ancien mot de passe ne fonctionne plus |
| `test_user_can_logout` | Vérifie la déconnexion |

**Fichier:** `tests/Feature/Auth/UserRegistrationTest.php`

| Test | Description |
|------|-------------|
| `test_registration_requires_valid_email` | Vérifie la validation de l'email |
| `test_registration_requires_password_confirmation` | Vérifie la confirmation du mot de passe |
| `test_duplicate_email_rejected` | Vérifie le rejet des emails dupliqués |
| `test_weak_password_rejected` | Vérifie les règles de complexité du mot de passe |

**Exemple de test:**
```php
public function test_registered_user_can_login(): void
{
    // Création du compte
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
    ]);

    // Test du login
    $response = $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
}

public function test_updated_user_can_login(): void
{
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'password' => Hash::make('oldpassword'),
    ]);

    // Modification du compte
    $this->actingAs($user);
    $this->put('/profile', [
        'email' => 'new@example.com',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    // Déconnexion
    $this->post('/logout');

    // Test login avec nouvelles credentials
    $response = $this->post('/login', [
        'email' => 'new@example.com',
        'password' => 'newpassword123',
    ]);

    $response->assertRedirect('/dashboard');
}
```

## Commandes

```bash
# Lancer tous les tests
php artisan test

# Lancer un fichier spécifique
php artisan test tests/Unit/Services/Pipeline/MarkdownChunkerServiceTest.php

# Lancer avec couverture
php artisan test --coverage

# Lancer en parallèle
php artisan test --parallel
```

## Mocks nécessaires

### OllamaService
```php
$this->mock(OllamaService::class, function ($mock) {
    $mock->shouldReceive('generate')
        ->andReturn([
            'questions' => [
                ['question' => 'Test?', 'reponse' => 'Réponse']
            ]
        ]);
});
```

### QdrantService
```php
$this->mock(QdrantService::class, function ($mock) {
    $mock->shouldReceive('upsertPoints')->andReturn(true);
    $mock->shouldReceive('deleteByDocumentId')->andReturn(true);
});
```

## Fixtures

Créer des fichiers de test dans `tests/Fixtures/`:
- `test-document.html` - Document HTML simple
- `test-document.md` - Document Markdown
- `test-document.pdf` - Document PDF (petit)

## Configuration PHPUnit

Le fichier `phpunit.xml` doit inclure :
```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="OLLAMA_URL" value="http://mock-ollama"/>
<env name="QDRANT_URL" value="http://mock-qdrant"/>
```
