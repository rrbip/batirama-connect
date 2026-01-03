<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ConfigurableList;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.pages.settings-page';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Paramètres';

    protected static ?string $title = 'Paramètres système';

    protected static ?int $navigationSort = 100;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    // État du formulaire
    public ?string $selectedList = null;
    public ?array $listData = [];
    public ?string $newListKey = null;
    public ?string $newListName = null;
    public ?string $newListCategory = null;

    public function mount(): void
    {
        // Sélectionner la première liste par défaut
        $firstList = ConfigurableList::first();
        if ($firstList) {
            $this->selectedList = $firstList->key;
            $this->listData = $firstList->data ?? [];
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Listes système')
                    ->description('Gérez les listes de données réutilisables dans l\'application (modèles IA, modes de paiement, etc.)')
                    ->icon('heroicon-o-list-bullet')
                    ->collapsed()
                    ->schema([
                        Select::make('selectedList')
                            ->label('Liste à configurer')
                            ->options(fn () => $this->getListOptions())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn ($state) => $this->loadListData($state))
                            ->placeholder('Sélectionnez une liste...'),

                        KeyValue::make('listData')
                            ->label('Données de la liste')
                            ->keyLabel('Clé (identifiant)')
                            ->valueLabel('Valeur (libellé affiché)')
                            ->addActionLabel('Ajouter une entrée')
                            ->reorderable()
                            ->visible(fn (Get $get) => $get('selectedList') !== null)
                            ->helperText('La clé est l\'identifiant technique, la valeur est le texte affiché à l\'utilisateur.'),
                    ]),

                Section::make('Créer une nouvelle liste')
                    ->description('Ajoutez une nouvelle liste configurable')
                    ->icon('heroicon-o-plus-circle')
                    ->collapsed()
                    ->schema([
                        TextInput::make('newListKey')
                            ->label('Clé unique')
                            ->placeholder('ex: payment_methods')
                            ->helperText('Identifiant technique unique (snake_case)')
                            ->regex('/^[a-z][a-z0-9_]*$/')
                            ->validationMessages([
                                'regex' => 'La clé doit être en snake_case (lettres minuscules, chiffres, underscores)',
                            ]),

                        TextInput::make('newListName')
                            ->label('Nom affiché')
                            ->placeholder('ex: Modes de paiement'),

                        Select::make('newListCategory')
                            ->label('Catégorie')
                            ->options(ConfigurableList::getCategoryLabels())
                            ->default(ConfigurableList::CATEGORY_GENERAL),
                    ])
                    ->columns(3),
            ]);
    }

    protected function getListOptions(): array
    {
        $lists = ConfigurableList::all();

        if ($lists->isEmpty()) {
            return [];
        }

        $options = [];
        $categoryLabels = ConfigurableList::getCategoryLabels();

        foreach ($lists->groupBy('category') as $category => $categoryLists) {
            $categoryLabel = $categoryLabels[$category] ?? ucfirst($category);
            foreach ($categoryLists as $list) {
                $options[$categoryLabel][$list->key] = $list->name;
            }
        }

        return $options;
    }

    public function loadListData(?string $key): void
    {
        if (! $key) {
            $this->listData = [];

            return;
        }

        $list = ConfigurableList::where('key', $key)->first();
        $this->listData = $list?->data ?? [];
    }

    public function saveList(): void
    {
        if (! $this->selectedList) {
            Notification::make()
                ->title('Aucune liste sélectionnée')
                ->warning()
                ->send();

            return;
        }

        $list = ConfigurableList::where('key', $this->selectedList)->first();

        if (! $list) {
            Notification::make()
                ->title('Liste introuvable')
                ->danger()
                ->send();

            return;
        }

        $list->update(['data' => $this->listData ?? []]);

        Notification::make()
            ->title('Liste sauvegardée')
            ->body("La liste \"{$list->name}\" a été mise à jour.")
            ->success()
            ->send();
    }

    public function createList(): void
    {
        if (! $this->newListKey || ! $this->newListName) {
            Notification::make()
                ->title('Champs requis')
                ->body('La clé et le nom sont obligatoires.')
                ->warning()
                ->send();

            return;
        }

        // Vérifier l'unicité
        if (ConfigurableList::where('key', $this->newListKey)->exists()) {
            Notification::make()
                ->title('Clé déjà utilisée')
                ->body("La clé \"{$this->newListKey}\" existe déjà.")
                ->danger()
                ->send();

            return;
        }

        ConfigurableList::create([
            'key' => $this->newListKey,
            'name' => $this->newListName,
            'category' => $this->newListCategory ?? ConfigurableList::CATEGORY_GENERAL,
            'data' => [],
            'is_system' => false,
        ]);

        // Sélectionner la nouvelle liste
        $this->selectedList = $this->newListKey;
        $this->listData = [];

        // Réinitialiser les champs
        $this->newListKey = null;
        $this->newListName = null;
        $this->newListCategory = null;

        Notification::make()
            ->title('Liste créée')
            ->body("La liste \"{$this->newListName}\" a été créée.")
            ->success()
            ->send();
    }

    public function deleteList(): void
    {
        if (! $this->selectedList) {
            return;
        }

        $list = ConfigurableList::where('key', $this->selectedList)->first();

        if (! $list) {
            return;
        }

        if ($list->is_system) {
            Notification::make()
                ->title('Suppression impossible')
                ->body('Les listes système ne peuvent pas être supprimées.')
                ->danger()
                ->send();

            return;
        }

        $name = $list->name;
        $list->delete();

        $this->selectedList = null;
        $this->listData = [];

        Notification::make()
            ->title('Liste supprimée')
            ->body("La liste \"{$name}\" a été supprimée.")
            ->success()
            ->send();
    }

    public function resetToDefault(): void
    {
        if (! $this->selectedList) {
            return;
        }

        $defaultData = ConfigurableList::getDefaultData($this->selectedList);

        if (empty($defaultData)) {
            Notification::make()
                ->title('Pas de données par défaut')
                ->body('Cette liste n\'a pas de valeurs par défaut définies.')
                ->warning()
                ->send();

            return;
        }

        $this->listData = $defaultData;

        Notification::make()
            ->title('Valeurs par défaut restaurées')
            ->body('N\'oubliez pas de sauvegarder.')
            ->info()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset_default')
                ->label('Valeurs par défaut')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => $this->selectedList !== null)
                ->requiresConfirmation()
                ->modalHeading('Restaurer les valeurs par défaut ?')
                ->modalDescription('Les modifications non sauvegardées seront perdues.')
                ->action(fn () => $this->resetToDefault()),

            Action::make('delete_list')
                ->label('Supprimer')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => $this->selectedList !== null && ! ConfigurableList::where('key', $this->selectedList)->first()?->is_system)
                ->requiresConfirmation()
                ->modalHeading('Supprimer cette liste ?')
                ->modalDescription('Cette action est irréversible.')
                ->action(fn () => $this->deleteList()),

            Action::make('create_list')
                ->label('Créer la liste')
                ->icon('heroicon-o-plus')
                ->color('info')
                ->action(fn () => $this->createList()),

            Action::make('save')
                ->label('Sauvegarder')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action(fn () => $this->saveList()),
        ];
    }
}
