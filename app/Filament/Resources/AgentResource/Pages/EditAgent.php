<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Http;

class EditAgent extends EditRecord
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('testOllama')
                ->label('Tester Ollama')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->action(fn () => $this->testOllamaConnections()),

            Actions\Action::make('test')
                ->label('Tester')
                ->icon('heroicon-o-play')
                ->color('success')
                ->url(fn () => $this->getResource()::getUrl('test', ['record' => $this->record])),

            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // Validate Ollama connections after saving
        $this->validateOllamaConnectionsQuietly();
    }

    /**
     * Test all configured Ollama connections and show results
     */
    protected function testOllamaConnections(): void
    {
        $data = $this->form->getState();
        $results = [];

        // Test Chat Ollama
        $chatHost = $data['ollama_host'] ?? config('ai.ollama.host', 'ollama');
        $chatPort = $data['ollama_port'] ?? config('ai.ollama.port', 11434);
        $results['Chat'] = $this->testOllamaEndpoint($chatHost, $chatPort, $data['model'] ?? null);

        // Test Vision Ollama (if configured)
        if (!empty($data['vision_ollama_host']) || !empty($data['vision_ollama_port'])) {
            $visionHost = $data['vision_ollama_host'] ?? \App\Models\VisionSetting::getInstance()->ollama_host;
            $visionPort = $data['vision_ollama_port'] ?? \App\Models\VisionSetting::getInstance()->ollama_port;
            $results['Vision'] = $this->testOllamaEndpoint($visionHost, $visionPort, $data['vision_model'] ?? null);
        }

        // Test Chunking Ollama (if configured)
        if (!empty($data['chunking_ollama_host']) || !empty($data['chunking_ollama_port'])) {
            $chunkHost = $data['chunking_ollama_host'] ?? \App\Models\LlmChunkingSetting::getInstance()->ollama_host;
            $chunkPort = $data['chunking_ollama_port'] ?? \App\Models\LlmChunkingSetting::getInstance()->ollama_port;
            $results['Chunking'] = $this->testOllamaEndpoint($chunkHost, $chunkPort, $data['chunking_model'] ?? null);
        }

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
        $data = $this->form->getState();
        $warnings = [];

        // Check Chat Ollama
        $chatHost = $data['ollama_host'] ?? config('ai.ollama.host', 'ollama');
        $chatPort = $data['ollama_port'] ?? config('ai.ollama.port', 11434);
        $chatResult = $this->testOllamaEndpoint($chatHost, $chatPort, $data['model'] ?? null);

        if (!$chatResult['connected']) {
            $warnings[] = "Chat Ollama ({$chatResult['url']}) inaccessible";
        } elseif ($data['model'] && !$chatResult['model_installed']) {
            $warnings[] = "Modèle Chat '{$data['model']}' non installé";
        }

        // Check Vision Ollama (if configured)
        if (!empty($data['vision_ollama_host']) || !empty($data['vision_model'])) {
            $visionHost = $data['vision_ollama_host'] ?? \App\Models\VisionSetting::getInstance()->ollama_host;
            $visionPort = $data['vision_ollama_port'] ?? \App\Models\VisionSetting::getInstance()->ollama_port;
            $visionResult = $this->testOllamaEndpoint($visionHost, $visionPort, $data['vision_model'] ?? null);

            if (!$visionResult['connected']) {
                $warnings[] = "Vision Ollama ({$visionResult['url']}) inaccessible";
            } elseif ($data['vision_model'] && !$visionResult['model_installed']) {
                $warnings[] = "Modèle Vision '{$data['vision_model']}' non installé";
            }
        }

        // Check Chunking Ollama (if configured)
        if (!empty($data['chunking_ollama_host']) || !empty($data['chunking_model'])) {
            $chunkHost = $data['chunking_ollama_host'] ?? \App\Models\LlmChunkingSetting::getInstance()->ollama_host;
            $chunkPort = $data['chunking_ollama_port'] ?? \App\Models\LlmChunkingSetting::getInstance()->ollama_port;
            $chunkResult = $this->testOllamaEndpoint($chunkHost, $chunkPort, $data['chunking_model'] ?? null);

            if (!$chunkResult['connected']) {
                $warnings[] = "Chunking Ollama ({$chunkResult['url']}) inaccessible";
            } elseif ($data['chunking_model'] && !$chunkResult['model_installed']) {
                $warnings[] = "Modèle Chunking '{$data['chunking_model']}' non installé";
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
