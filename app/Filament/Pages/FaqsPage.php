<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Agent;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class FaqsPage extends Page implements HasForms
{
    use InteractsWithForms;
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static string $view = 'filament.pages.faqs-page';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $navigationLabel = 'FAQs';

    protected static ?string $title = 'FAQs - Questions/Réponses';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    private const LEARNED_RESPONSES_COLLECTION = 'learned_responses';

    #[Url]
    public ?string $selectedAgentId = null;

    public string $search = '';

    public array $faqs = [];

    public ?array $addFaqData = [];

    public bool $showAddForm = false;

    public int $perPage = 10;

    public int $currentPage = 1;

    public function mount(): void
    {
        // Sélectionner le premier agent par défaut
        $firstAgent = Agent::where('is_active', true)->first();
        if ($firstAgent && !$this->selectedAgentId) {
            $this->selectedAgentId = (string) $firstAgent->id;
        }

        $this->loadFaqs();
    }

    public function updatedSelectedAgentId(): void
    {
        $this->currentPage = 1;
        $this->search = '';
        $this->loadFaqs();
    }

    public function updatedSearch(): void
    {
        $this->currentPage = 1;
    }

    public function previousPage(): void
    {
        if ($this->currentPage > 1) {
            $this->currentPage--;
        }
    }

    public function nextPage(): void
    {
        if ($this->currentPage < $this->getTotalPages()) {
            $this->currentPage++;
        }
    }

    public function goToPage(int $page): void
    {
        $this->currentPage = max(1, min($page, $this->getTotalPages()));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selectedAgentId')
                    ->label('Agent IA')
                    ->options(Agent::where('is_active', true)->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadFaqs()),
            ])
            ->statePath('data');
    }

    public function addFaqForm(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('question')
                    ->label('Question')
                    ->required()
                    ->maxLength(500)
                    ->placeholder('Ex: Comment fonctionne le parrainage ?'),

                Textarea::make('answer')
                    ->label('Réponse')
                    ->required()
                    ->rows(5)
                    ->placeholder('La réponse complète à la question...'),
            ])
            ->statePath('addFaqData');
    }

    protected function getForms(): array
    {
        return [
            'form',
            'addFaqForm',
        ];
    }

    public function loadFaqs(): void
    {
        $this->faqs = [];

        if (!$this->selectedAgentId) {
            return;
        }

        $agent = Agent::find($this->selectedAgentId);
        if (!$agent) {
            return;
        }

        try {
            $qdrantService = app(QdrantService::class);

            if (!$qdrantService->collectionExists(self::LEARNED_RESPONSES_COLLECTION)) {
                return;
            }

            // Récupérer toutes les FAQs de cet agent via scroll
            $this->faqs = $this->scrollAllFaqs($qdrantService, $agent->slug);

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('Impossible de charger les FAQs: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function scrollAllFaqs(QdrantService $qdrantService, string $agentSlug): array
    {
        $faqs = [];
        $offset = null;
        $limit = 100;

        do {
            $response = $qdrantService->scroll(
                collection: self::LEARNED_RESPONSES_COLLECTION,
                limit: $limit,
                offset: $offset,
                filter: [
                    'must' => [
                        ['key' => 'agent_slug', 'match' => ['value' => $agentSlug]]
                    ]
                ],
                withFullResult: true
            );

            $points = $response['points'] ?? [];

            if (empty($points)) {
                break;
            }

            foreach ($points as $point) {
                $faqs[] = [
                    'id' => $point['id'],
                    'question' => $point['payload']['question'] ?? '',
                    'answer' => $point['payload']['answer'] ?? '',
                    'validated_at' => $point['payload']['validated_at'] ?? null,
                    'message_id' => $point['payload']['message_id'] ?? null,
                    'is_manual' => empty($point['payload']['message_id']),
                ];
            }

            $offset = $response['next_page_offset'] ?? null;

        } while ($offset !== null);

        // Trier par date de validation (plus récent en premier)
        usort($faqs, function ($a, $b) {
            return ($b['validated_at'] ?? '') <=> ($a['validated_at'] ?? '');
        });

        return $faqs;
    }

    public function toggleAddForm(): void
    {
        $this->showAddForm = !$this->showAddForm;
        if (!$this->showAddForm) {
            $this->addFaqData = [];
        }
    }

    public function saveFaq(): void
    {
        $data = $this->addFaqForm->getState();

        if (empty($data['question']) || empty($data['answer'])) {
            Notification::make()
                ->title('Erreur')
                ->body('La question et la réponse sont obligatoires.')
                ->danger()
                ->send();
            return;
        }

        $agent = Agent::find($this->selectedAgentId);
        if (!$agent) {
            Notification::make()
                ->title('Erreur')
                ->body('Agent non trouvé.')
                ->danger()
                ->send();
            return;
        }

        try {
            $embeddingService = app(EmbeddingService::class);
            $qdrantService = app(QdrantService::class);

            // S'assurer que la collection existe
            if (!$qdrantService->collectionExists(self::LEARNED_RESPONSES_COLLECTION)) {
                $qdrantService->createCollection(self::LEARNED_RESPONSES_COLLECTION, [
                    'vector_size' => config('ai.qdrant.vector_size', 768),
                    'distance' => 'Cosine',
                ]);
            }

            // Générer l'embedding de la question
            $vector = $embeddingService->embed($data['question']);

            $pointId = Str::uuid()->toString();

            $result = $qdrantService->upsert(self::LEARNED_RESPONSES_COLLECTION, [
                [
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => [
                        'agent_id' => $agent->id,
                        'agent_slug' => $agent->slug,
                        'message_id' => null, // null = ajout manuel
                        'question' => $data['question'],
                        'answer' => $data['answer'],
                        'validated_by' => auth()->id(),
                        'validated_at' => now()->toIso8601String(),
                        'source' => 'manual',
                    ],
                ]
            ]);

            if ($result) {
                Notification::make()
                    ->title('FAQ ajoutée')
                    ->body('La question/réponse a été indexée et sera utilisée par l\'IA.')
                    ->success()
                    ->send();

                $this->addFaqData = [];
                $this->showAddForm = false;
                $this->loadFaqs();
            } else {
                throw new \RuntimeException('Échec de l\'indexation dans Qdrant');
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('Impossible d\'ajouter la FAQ: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function deleteFaq(string $pointId): void
    {
        try {
            $qdrantService = app(QdrantService::class);

            $result = $qdrantService->delete(self::LEARNED_RESPONSES_COLLECTION, [$pointId]);

            if ($result) {
                Notification::make()
                    ->title('FAQ supprimée')
                    ->body('La question/réponse a été retirée de la base d\'apprentissage.')
                    ->success()
                    ->send();

                $this->loadFaqs();
            } else {
                throw new \RuntimeException('Échec de la suppression');
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('Impossible de supprimer: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getAgents(): \Illuminate\Database\Eloquent\Collection
    {
        return Agent::where('is_active', true)->orderBy('name')->get();
    }

    public function getSelectedAgent(): ?Agent
    {
        return $this->selectedAgentId ? Agent::find($this->selectedAgentId) : null;
    }

    public function getFilteredFaqs(): array
    {
        $filtered = $this->faqs;

        // Filtrer par recherche
        if (!empty($this->search)) {
            $searchLower = mb_strtolower($this->search);
            $filtered = array_filter($filtered, function ($faq) use ($searchLower) {
                return str_contains(mb_strtolower($faq['question']), $searchLower)
                    || str_contains(mb_strtolower($faq['answer']), $searchLower);
            });
            $filtered = array_values($filtered); // Réindexer
        }

        return $filtered;
    }

    public function getPaginatedFaqs(): array
    {
        $filtered = $this->getFilteredFaqs();
        $offset = ($this->currentPage - 1) * $this->perPage;

        return array_slice($filtered, $offset, $this->perPage);
    }

    public function getTotalPages(): int
    {
        $total = count($this->getFilteredFaqs());

        return max(1, (int) ceil($total / $this->perPage));
    }

    public function getTotalFilteredCount(): int
    {
        return count($this->getFilteredFaqs());
    }

    public function isAdmin(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole('super-admin') || $user->hasRole('admin');
    }
}
