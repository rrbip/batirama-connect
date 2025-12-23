<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use App\Models\Agent;
use App\Models\AiSession;
use App\Services\ChatDispatcherService;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;

class TestAgent extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = AgentResource::class;

    protected static string $view = 'filament.resources.agent-resource.pages.test-agent';

    public Agent $record;

    public ?AiSession $testSession = null;

    public array $messages = [];

    public ?string $userMessage = '';

    public bool $isLoading = false;

    public function mount(int|string $record): void
    {
        $this->record = Agent::findOrFail($record);
    }

    public function getTitle(): string
    {
        return "Tester : {$this->record->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit')
                ->label('Modifier')
                ->icon('heroicon-o-pencil')
                ->url(fn () => AgentResource::getUrl('edit', ['record' => $this->record])),

            Actions\Action::make('newSession')
                ->label('Nouvelle session')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $this->testSession = null;
                    $this->messages = [];
                    Notification::make()
                        ->title('Session réinitialisée')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function sendMessage(): void
    {
        if (empty(trim($this->userMessage))) {
            return;
        }

        $this->isLoading = true;
        $message = $this->userMessage;
        $this->userMessage = '';

        // Ajouter le message utilisateur à l'affichage
        $this->messages[] = [
            'role' => 'user',
            'content' => $message,
            'timestamp' => now()->format('H:i'),
        ];

        try {
            /** @var ChatDispatcherService $dispatcher */
            $dispatcher = app(ChatDispatcherService::class);

            // Créer une session de test si nécessaire
            if (!$this->testSession) {
                $this->testSession = $dispatcher->createSession($this->record);
            }

            // Envoyer le message
            $response = $dispatcher->chat($this->testSession, $message);

            // Ajouter la réponse
            $this->messages[] = [
                'role' => 'assistant',
                'content' => $response['response'] ?? 'Erreur: pas de réponse',
                'timestamp' => now()->format('H:i'),
                'tokens' => $response['tokens_used'] ?? null,
                'sources' => $response['sources'] ?? [],
            ];

        } catch (\Throwable $e) {
            $this->messages[] = [
                'role' => 'error',
                'content' => "Erreur: {$e->getMessage()}",
                'timestamp' => now()->format('H:i'),
            ];

            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->isLoading = false;
    }

    #[Computed]
    public function agentInfo(): array
    {
        return [
            'Modèle' => $this->record->model ?? 'Par défaut',
            'Température' => $this->record->temperature ?? 0.7,
            'Max tokens' => $this->record->max_tokens ?? 2048,
            'Mode RAG' => $this->record->retrieval_mode ?? 'VECTOR_ONLY',
            'Collection' => $this->record->qdrant_collection ?? '-',
        ];
    }
}
