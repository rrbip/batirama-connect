# Configuration Multi-Providers LLM

> **Référence** : [00_index.md](./00_index.md)
> **Statut** : En développement
> **Date** : Janvier 2026

---

## Vue d'Ensemble

Cette fonctionnalité permet de configurer différents providers LLM par agent, offrant flexibilité entre solutions self-hosted (Ollama) et APIs cloud (Gemini, OpenAI).

```
┌─────────────────────────────────────────────────────────────────┐
│                    ARCHITECTURE MULTI-PROVIDERS                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐                                               │
│  │    Agent     │                                               │
│  │ llm_provider │                                               │
│  └──────┬───────┘                                               │
│         │                                                        │
│         ▼                                                        │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                   LLMServiceInterface                     │   │
│  │  ├── generate(prompt, options): LLMResponse              │   │
│  │  ├── generateStream(prompt, options): Generator          │   │
│  │  ├── isAvailable(): bool                                 │   │
│  │  └── listModels(): array                                 │   │
│  └──────────────────────────────────────────────────────────┘   │
│         │                                                        │
│         ├─────────────┬─────────────┬─────────────┐             │
│         ▼             ▼             ▼             ▼             │
│  ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌───────────┐       │
│  │  Ollama   │ │  Gemini   │ │  OpenAI   │ │  Anthropic│       │
│  │ (default) │ │   API     │ │   API     │ │   API     │       │
│  └───────────┘ └───────────┘ └───────────┘ └───────────┘       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 1. Providers Supportés

### 1.1 Ollama (Self-Hosted) - Par défaut

| Caractéristique | Valeur |
|-----------------|--------|
| Type | Self-hosted |
| Coût | Gratuit (matériel requis) |
| Latence | Dépend du GPU |
| Vision | Via llava, moondream |
| Modèles | mistral, llama, qwen, etc. |

**Configuration agent :**
```php
'llm_provider' => 'ollama',
'ollama_host' => 'ollama',
'ollama_port' => 11434,
'model' => 'mistral:7b',
```

### 1.2 Google Gemini API

| Caractéristique | Valeur |
|-----------------|--------|
| Type | Cloud API |
| Coût Free Tier | 250 req/jour (Flash) |
| Coût Payant | ~$0.075/1M tokens (input) |
| Latence | 300-500ms |
| Vision | Native (multimodal) |
| Modèles | gemini-2.5-flash, gemini-2.5-pro |

**Limites Free Tier (Janvier 2026) :**

| Modèle | RPM | Tokens/min | Req/jour |
|--------|-----|------------|----------|
| gemini-2.5-flash | 10 | 250K | 250 |
| gemini-2.5-flash-lite | 15 | 250K | 1000 |
| gemini-2.5-pro | 5 | 250K | 20 |

**Configuration agent :**
```php
'llm_provider' => 'gemini',
'llm_api_key' => 'AIza...',
'model' => 'gemini-2.5-flash',
```

### 1.3 OpenAI API (Futur)

| Caractéristique | Valeur |
|-----------------|--------|
| Type | Cloud API |
| Coût | ~$0.50/1M tokens (GPT-4o) |
| Vision | Via GPT-4o |
| Modèles | gpt-4o, gpt-4o-mini |

---

## 2. Modèle de Données

### 2.1 Nouveaux champs table `agents`

```sql
-- Migration: add_llm_provider_fields_to_agents
ALTER TABLE agents ADD COLUMN llm_provider VARCHAR(20) DEFAULT 'ollama';
ALTER TABLE agents ADD COLUMN llm_api_key TEXT NULL;
ALTER TABLE agents ADD COLUMN llm_api_model VARCHAR(100) NULL;

-- Commentaires
COMMENT ON COLUMN agents.llm_provider IS 'Provider LLM: ollama, gemini, openai';
COMMENT ON COLUMN agents.llm_api_key IS 'Clé API (chiffrée) pour providers cloud';
COMMENT ON COLUMN agents.llm_api_model IS 'Modèle spécifique API (ex: gemini-2.5-flash)';
```

### 2.2 Enum LLMProvider

```php
<?php

namespace App\Enums;

enum LLMProvider: string
{
    case OLLAMA = 'ollama';
    case GEMINI = 'gemini';
    case OPENAI = 'openai';

    public function label(): string
    {
        return match($this) {
            self::OLLAMA => 'Ollama (Self-Hosted)',
            self::GEMINI => 'Google Gemini API',
            self::OPENAI => 'OpenAI API',
        };
    }

    public function requiresApiKey(): bool
    {
        return $this !== self::OLLAMA;
    }

    public function defaultModel(): string
    {
        return match($this) {
            self::OLLAMA => 'mistral:7b',
            self::GEMINI => 'gemini-2.5-flash',
            self::OPENAI => 'gpt-4o-mini',
        };
    }
}
```

---

## 3. Services

### 3.1 GeminiService

```php
<?php

namespace App\Services\AI;

use App\DTOs\AI\LLMResponse;
use App\Services\AI\Contracts\LLMServiceInterface;
use Illuminate\Support\Facades\Http;

class GeminiService implements LLMServiceInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-2.5-flash'
    ) {}

    public function generate(string $prompt, array $options = []): LLMResponse
    {
        $startTime = microtime(true);

        $response = Http::timeout($options['timeout'] ?? 120)
            ->post("{$this::BASE_URL}/models/{$this->model}:generateContent", [
                'key' => $this->apiKey,
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'maxOutputTokens' => $options['max_tokens'] ?? 2048,
                    'topP' => $options['top_p'] ?? 0.9,
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Gemini API error: " . $response->body());
        }

        $data = $response->json();
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $generationTime = (int) ((microtime(true) - $startTime) * 1000);

        return new LLMResponse(
            content: $content,
            model: $this->model,
            tokensPrompt: $data['usageMetadata']['promptTokenCount'] ?? null,
            tokensCompletion: $data['usageMetadata']['candidatesTokenCount'] ?? null,
            generationTimeMs: $generationTime,
            raw: $data
        );
    }

    public function generateStream(string $prompt, array $options = []): \Generator
    {
        // Gemini streaming via SSE
        $response = Http::timeout($options['timeout'] ?? 120)
            ->withOptions(['stream' => true])
            ->post("{$this::BASE_URL}/models/{$this->model}:streamGenerateContent", [
                'key' => $this->apiKey,
                'contents' => [['parts' => [['text' => $prompt]]]],
            ]);

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(1024);

            // Parse SSE chunks
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $json = json_decode(substr($line, 6), true);
                    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                        yield $json['candidates'][0]['content']['parts'][0]['text'];
                    }
                }
            }
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)
                ->get("{$this::BASE_URL}/models", ['key' => $this->apiKey]);
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function listModels(): array
    {
        return [
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-2.5-pro',
            'gemini-2.0-flash',
        ];
    }
}
```

### 3.2 Modification DispatcherService

```php
// Dans DispatcherService.php

protected function getLLMService(Agent $agent): LLMServiceInterface
{
    $provider = LLMProvider::tryFrom($agent->llm_provider) ?? LLMProvider::OLLAMA;

    return match ($provider) {
        LLMProvider::GEMINI => new GeminiService(
            apiKey: decrypt($agent->llm_api_key),
            model: $agent->llm_api_model ?? 'gemini-2.5-flash'
        ),
        LLMProvider::OPENAI => new OpenAIService(
            apiKey: decrypt($agent->llm_api_key),
            model: $agent->llm_api_model ?? 'gpt-4o-mini'
        ),
        default => OllamaService::forAgent($agent),
    };
}
```

---

## 4. Configuration Admin (Filament)

### 4.1 Onglet "Modèle IA" dans AgentResource

```php
Forms\Components\Tabs\Tab::make('Modèle IA')
    ->icon('heroicon-o-cpu-chip')
    ->schema([
        Forms\Components\Section::make('Provider LLM')
            ->schema([
                Forms\Components\Select::make('llm_provider')
                    ->label('Provider')
                    ->options(LLMProvider::class)
                    ->default('ollama')
                    ->live()
                    ->required(),

                // Ollama fields (visible si ollama)
                Forms\Components\TextInput::make('ollama_host')
                    ->visible(fn (Get $get) => $get('llm_provider') === 'ollama'),
                Forms\Components\TextInput::make('ollama_port')
                    ->visible(fn (Get $get) => $get('llm_provider') === 'ollama'),
                Forms\Components\Select::make('model')
                    ->options(fn () => $this->getOllamaModels())
                    ->visible(fn (Get $get) => $get('llm_provider') === 'ollama'),

                // API fields (visible si gemini/openai)
                Forms\Components\TextInput::make('llm_api_key')
                    ->label('Clé API')
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get) => in_array($get('llm_provider'), ['gemini', 'openai'])),
                Forms\Components\Select::make('llm_api_model')
                    ->label('Modèle')
                    ->options(fn (Get $get) => $this->getApiModels($get('llm_provider')))
                    ->visible(fn (Get $get) => in_array($get('llm_provider'), ['gemini', 'openai'])),
            ]),
    ]),
```

---

## 5. Vision avec Gemini

Gemini étant nativement multimodal, l'analyse d'images est intégrée au même modèle.

```php
// Exemple d'envoi d'image à Gemini
public function analyzeImage(string $imagePath, string $prompt): string
{
    $imageData = base64_encode(file_get_contents($imagePath));
    $mimeType = mime_content_type($imagePath);

    $response = Http::post("{$this::BASE_URL}/models/{$this->model}:generateContent", [
        'key' => $this->apiKey,
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $imageData,
                        ]
                    ]
                ]
            ]
        ],
    ]);

    return $response->json('candidates.0.content.parts.0.text');
}
```

---

## 6. Considérations de Sécurité

### 6.1 Stockage des clés API

Les clés API sont chiffrées en base de données via le helper Laravel `encrypt()`.

```php
// Mutateur dans Agent.php
protected function llmApiKey(): Attribute
{
    return Attribute::make(
        get: fn (?string $value) => $value ? decrypt($value) : null,
        set: fn (?string $value) => $value ? encrypt($value) : null,
    );
}
```

### 6.2 Restrictions RGPD

**Important** : Le tier gratuit de Gemini peut utiliser vos données pour entraîner les modèles.
- ⚠️ Ne pas utiliser en production avec données sensibles sans tier payant
- ⚠️ Non disponible dans l'UE/EEE/UK/Suisse en free tier

---

## 7. Changelog

| Date | Version | Changements |
|------|---------|-------------|
| 2026-01 | 1.0.0 | Support initial Ollama + Gemini |
