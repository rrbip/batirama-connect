<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use App\Models\Agent;
use App\Models\AiSession;
use App\Services\ChatDispatcherService;
use Filament\Actions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Computed;

class TestAgent extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = AgentResource::class;

    protected static string $view = 'filament.resources.agent-resource.pages.test-agent';

    public ?int $testSessionId = null;

    public array $messages = [];

    public ?string $userMessage = '';

    public bool $isLoading = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return "Tester : {$this->getRecord()->name}";
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
                    $this->testSessionId = null;
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
            if (!$this->testSessionId) {
                $session = $dispatcher->createSession($this->getRecord());
                $this->testSessionId = $session->id;
            }

            // Récupérer la session
            $session = AiSession::find($this->testSessionId);

            // Envoyer le message
            $response = $dispatcher->chat($session, $message);

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
        $record = $this->getRecord();

        return [
            'Modèle' => $record->model ?? 'Par défaut',
            'Température' => $record->temperature ?? 0.7,
            'Max tokens' => $record->max_tokens ?? 2048,
            'Mode RAG' => $record->retrieval_mode ?? 'VECTOR_ONLY',
            'Collection' => $record->qdrant_collection ?? '-',
        ];
    }

    public function getTestSession(): ?AiSession
    {
        return $this->testSessionId ? AiSession::find($this->testSessionId) : null;
    }
}
