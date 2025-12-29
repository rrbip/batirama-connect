<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDeploymentResource\Pages;

use App\Filament\Resources\AgentDeploymentResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Http;

class EditAgentDeployment extends EditRecord
{
    protected static string $resource = AgentDeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('testOllama')
                ->label('Tester Ollama')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->action(fn () => $this->testOllamaConnections()),

            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        $this->validateOllamaConnectionsQuietly();
    }

    /**
     * Test all configured Ollama connections (including overrides) and show results
     */
    protected function testOllamaConnections(): void
    {
        $deployment = $this->record;
        $results = [];

        // Test resolved Chat config
        $chatConfig = $deployment->getChatConfig();
        $results['Chat'] = $this->testOllamaEndpoint(
            $chatConfig['host'],
            $chatConfig['port'],
            $chatConfig['model']
        );

        // Test resolved Vision config
        $visionConfig = $deployment->getVisionConfig();
        $results['Vision'] = $this->testOllamaEndpoint(
            $visionConfig['host'],
            $visionConfig['port'],
            $visionConfig['model']
        );

        // Test resolved Chunking config
        $chunkConfig = $deployment->getChunkingConfig();
        $results['Chunking'] = $this->testOllamaEndpoint(
            $chunkConfig['host'],
            $chunkConfig['port'],
            $chunkConfig['model']
        );

        // Build notification message
        $messages = [];
        $hasErrors = false;

        foreach ($results as $type => $result) {
            if ($result['connected']) {
                $modelStatus = $result['model_installed'] ? '✓' : '⚠ modèle non installé';
                $messages[] = "{$type}: ✓ {$result['url']} {$modelStatus}";
            } else {
                $messages[] = "{$type}: ✗ {$result['url']} - {$result['error']}";
                $hasErrors = true;
            }
        }

        Notification::make()
            ->title($hasErrors ? 'Problèmes de connexion détectés' : 'Toutes les connexions OK')
            ->body(implode("\n", $messages))
            ->color($hasErrors ? 'warning' : 'success')
            ->icon($hasErrors ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
            ->duration(10000)
            ->send();
    }

    /**
     * Quietly validate Ollama connections and warn if issues
     */
    protected function validateOllamaConnectionsQuietly(): void
    {
        $deployment = $this->record->fresh();
        $warnings = [];
        $configOverlay = $deployment->config_overlay ?? [];

        // Only validate if there are overrides
        $hasOverrides = !empty($configOverlay['chat_ollama_host'])
            || !empty($configOverlay['vision_ollama_host'])
            || !empty($configOverlay['chunking_ollama_host']);

        if (!$hasOverrides) {
            return;
        }

        // Check Chat override
        if (!empty($configOverlay['chat_ollama_host'])) {
            $chatConfig = $deployment->getChatConfig();
            $result = $this->testOllamaEndpoint($chatConfig['host'], $chatConfig['port'], $chatConfig['model']);

            if (!$result['connected']) {
                $warnings[] = "Chat Ollama ({$result['url']}) inaccessible";
            } elseif (!$result['model_installed']) {
                $warnings[] = "Modèle Chat '{$chatConfig['model']}' non installé";
            }
        }

        // Check Vision override
        if (!empty($configOverlay['vision_ollama_host'])) {
            $visionConfig = $deployment->getVisionConfig();
            $result = $this->testOllamaEndpoint($visionConfig['host'], $visionConfig['port'], $visionConfig['model']);

            if (!$result['connected']) {
                $warnings[] = "Vision Ollama ({$result['url']}) inaccessible";
            } elseif (!$result['model_installed']) {
                $warnings[] = "Modèle Vision '{$visionConfig['model']}' non installé";
            }
        }

        // Check Chunking override
        if (!empty($configOverlay['chunking_ollama_host'])) {
            $chunkConfig = $deployment->getChunkingConfig();
            $result = $this->testOllamaEndpoint($chunkConfig['host'], $chunkConfig['port'], $chunkConfig['model']);

            if (!$result['connected']) {
                $warnings[] = "Chunking Ollama ({$result['url']}) inaccessible";
            } elseif (!$result['model_installed']) {
                $warnings[] = "Modèle Chunking '{$chunkConfig['model']}' non installé";
            }
        }

        if (!empty($warnings)) {
            Notification::make()
                ->title('Attention: Problèmes de configuration Ollama')
                ->body(implode("\n", $warnings))
                ->warning()
                ->duration(8000)
                ->send();
        }
    }

    /**
     * Test an Ollama endpoint
     */
    protected function testOllamaEndpoint(string $host, int|string $port, ?string $model = null): array
    {
        $port = (int) $port;
        $url = "http://{$host}:{$port}";

        try {
            $response = Http::timeout(5)->get("{$url}/api/tags");

            if ($response->successful()) {
                $models = collect($response->json('models', []))
                    ->pluck('name')
                    ->toArray();

                $modelInstalled = !$model || in_array($model, $models) || in_array("{$model}:latest", $models);

                return [
                    'connected' => true,
                    'url' => $url,
                    'models' => $models,
                    'model_installed' => $modelInstalled,
                ];
            }

            return [
                'connected' => false,
                'url' => $url,
                'error' => "HTTP {$response->status()}",
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'url' => $url,
                'error' => $e->getMessage(),
            ];
        }
    }
}
