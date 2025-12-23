<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $modelLabel = 'Document RAG';

    protected static ?string $pluralModelLabel = 'Documents RAG';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Document')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Informations')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\Section::make('Fichier')
                                    ->schema([
                                        Forms\Components\Select::make('agent_id')
                                            ->label('Agent')
                                            ->relationship('agent', 'name')
                                            ->required()
                                            ->searchable()
                                            ->preload(),

                                        Forms\Components\FileUpload::make('storage_path')
                                            ->label('Document')
                                            ->required()
                                            ->disk('local')
                                            ->directory('documents')
                                            ->acceptedFileTypes([
                                                'application/pdf',
                                                'text/plain',
                                                'text/markdown',
                                                'application/msword',
                                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                            ])
                                            ->maxSize(50 * 1024) // 50MB
                                            ->columnSpanFull()
                                            ->visible(fn ($record) => !$record),

                                        Forms\Components\TextInput::make('title')
                                            ->label('Titre')
                                            ->maxLength(255)
                                            ->columnSpanFull(),

                                        Forms\Components\Textarea::make('description')
                                            ->label('Description')
                                            ->rows(3)
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('source_url')
                                            ->label('URL source')
                                            ->url()
                                            ->maxLength(500),

                                        Forms\Components\Select::make('category')
                                            ->label('Catégorie')
                                            ->options([
                                                'documentation' => 'Documentation',
                                                'faq' => 'FAQ',
                                                'product' => 'Produit',
                                                'support' => 'Support',
                                                'legal' => 'Légal',
                                                'other' => 'Autre',
                                            ]),
                                    ])
                                    ->columns(2),
                            ]),

                        Forms\Components\Tabs\Tab::make('Extraction')
                            ->icon('heroicon-o-document-magnifying-glass')
                            ->schema([
                                Forms\Components\Section::make('Statut extraction')
                                    ->schema([
                                        Forms\Components\Placeholder::make('extraction_status_display')
                                            ->label('Statut')
                                            ->content(fn ($record) => match ($record?->extraction_status) {
                                                'pending' => 'En attente',
                                                'processing' => 'En cours',
                                                'completed' => 'Terminé',
                                                'failed' => 'Échoué',
                                                default => '-',
                                            }),

                                        Forms\Components\Placeholder::make('extracted_at_display')
                                            ->label('Extrait le')
                                            ->content(fn ($record) => $record?->extracted_at?->format('d/m/Y H:i') ?? '-'),

                                        Forms\Components\Placeholder::make('chunk_count_display')
                                            ->label('Nombre de chunks')
                                            ->content(fn ($record) => $record?->chunk_count ?? 0),

                                        Forms\Components\Placeholder::make('file_size_display')
                                            ->label('Taille')
                                            ->content(fn ($record) => $record?->getFileSizeForHumans() ?? '-'),
                                    ])
                                    ->columns(4),

                                Forms\Components\Section::make('Texte extrait')
                                    ->schema([
                                        Forms\Components\Textarea::make('extracted_text')
                                            ->label('')
                                            ->rows(10)
                                            ->disabled()
                                            ->columnSpanFull(),
                                    ])
                                    ->collapsed()
                                    ->visible(fn ($record) => $record?->extracted_text),

                                Forms\Components\Section::make('Erreur')
                                    ->schema([
                                        Forms\Components\Textarea::make('extraction_error')
                                            ->label('')
                                            ->rows(3)
                                            ->disabled()
                                            ->columnSpanFull(),
                                    ])
                                    ->visible(fn ($record) => $record?->extraction_error),
                            ])
                            ->visible(fn ($record) => $record !== null),

                        Forms\Components\Tabs\Tab::make('Indexation')
                            ->icon('heroicon-o-magnifying-glass')
                            ->schema([
                                Forms\Components\Section::make('Statut indexation')
                                    ->schema([
                                        Forms\Components\Toggle::make('is_indexed')
                                            ->label('Indexé dans Qdrant')
                                            ->disabled(),

                                        Forms\Components\Placeholder::make('indexed_at_display')
                                            ->label('Indexé le')
                                            ->content(fn ($record) => $record?->indexed_at?->format('d/m/Y H:i') ?? '-'),

                                        Forms\Components\Select::make('chunk_strategy')
                                            ->label('Stratégie de chunking')
                                            ->options([
                                                'fixed_size' => 'Taille fixe',
                                                'sentence' => 'Par phrase',
                                                'paragraph' => 'Par paragraphe',
                                                'recursive' => 'Récursif',
                                            ])
                                            ->default('recursive'),
                                    ])
                                    ->columns(3),
                            ])
                            ->visible(fn ($record) => $record !== null),

                        Forms\Components\Tabs\Tab::make('Chunks')
                            ->icon('heroicon-o-squares-2x2')
                            ->schema([
                                Forms\Components\Section::make('Chunks indexés')
                                    ->schema([
                                        Forms\Components\Placeholder::make('chunks_list')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (!$record || $record->chunks->isEmpty()) {
                                                    return 'Aucun chunk disponible';
                                                }

                                                $html = '<div class="space-y-4">';
                                                foreach ($record->chunks as $index => $chunk) {
                                                    $status = $chunk->is_indexed ? '✓ Indexé' : '✗ Non indexé';
                                                    $statusColor = $chunk->is_indexed ? 'text-success-600' : 'text-danger-600';
                                                    $tokens = $chunk->token_count ?? 0;

                                                    $html .= sprintf(
                                                        '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                                            <div class="flex justify-between items-center mb-2">
                                                                <span class="font-semibold">Chunk #%d</span>
                                                                <div class="flex items-center gap-3">
                                                                    <span class="text-xs text-gray-500">%d tokens</span>
                                                                    <span class="text-xs %s">%s</span>
                                                                </div>
                                                            </div>
                                                            <div class="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-32 overflow-y-auto">%s</div>
                                                        </div>',
                                                        $index,
                                                        $tokens,
                                                        $statusColor,
                                                        $status,
                                                        e(\Illuminate\Support\Str::limit($chunk->content, 500))
                                                    );
                                                }
                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
                                            ->columnSpanFull(),
                                    ]),
                            ])
                            ->visible(fn ($record) => $record !== null && $record->chunk_count > 0),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Titre')
                    ->default(fn ($record) => $record->original_name)
                    ->searchable(['title', 'original_name'])
                    ->sortable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pdf' => 'danger',
                        'docx', 'doc' => 'info',
                        'txt', 'md' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('extraction_status')
                    ->label('Extraction')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'processing' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                        default => '-',
                    }),

                Tables\Columns\IconColumn::make('is_indexed')
                    ->label('Indexé')
                    ->boolean(),

                Tables\Columns\TextColumn::make('chunk_count')
                    ->label('Chunks')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('file_size')
                    ->label('Taille')
                    ->formatStateUsing(fn ($record) => $record->getFileSizeForHumans())
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ajouté')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('agent')
                    ->relationship('agent', 'name'),

                Tables\Filters\SelectFilter::make('extraction_status')
                    ->label('Statut extraction')
                    ->options([
                        'pending' => 'En attente',
                        'processing' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                    ]),

                Tables\Filters\TernaryFilter::make('is_indexed')
                    ->label('Indexé'),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Catégorie')
                    ->options([
                        'documentation' => 'Documentation',
                        'faq' => 'FAQ',
                        'product' => 'Produit',
                        'support' => 'Support',
                        'legal' => 'Légal',
                        'other' => 'Autre',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('reprocess')
                    ->label('Retraiter')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => in_array($record->extraction_status, ['failed', 'pending']))
                    ->requiresConfirmation()
                    ->modalHeading('Retraiter le document')
                    ->modalDescription('Le document sera ré-extrait, re-découpé et ré-indexé. Continuer ?')
                    ->action(function (Document $record) {
                        $record->update([
                            'extraction_status' => 'pending',
                            'extraction_error' => null,
                            'is_indexed' => false,
                        ]);

                        ProcessDocumentJob::dispatch($record);

                        Notification::make()
                            ->title('Traitement relancé')
                            ->body("Le document \"{$record->original_name}\" est en cours de retraitement.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('index')
                    ->label('Indexer')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('success')
                    ->visible(fn ($record) => $record->extraction_status === 'completed' && !$record->is_indexed)
                    ->action(function (Document $record) {
                        // Relancer le job pour indexer uniquement
                        ProcessDocumentJob::dispatch($record, reindex: true);

                        Notification::make()
                            ->title('Indexation en cours')
                            ->body("Le document \"{$record->original_name}\" est en cours d'indexation.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('reprocess')
                        ->label('Retraiter')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                $record->update([
                                    'extraction_status' => 'pending',
                                    'extraction_error' => null,
                                    'is_indexed' => false,
                                ]);
                                ProcessDocumentJob::dispatch($record);
                                $count++;
                            }

                            Notification::make()
                                ->title('Traitement en cours')
                                ->body("{$count} document(s) en cours de retraitement.")
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('index')
                        ->label('Indexer')
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('success')
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->extraction_status === 'completed' && !$record->is_indexed) {
                                    ProcessDocumentJob::dispatch($record, reindex: true);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->title('Indexation en cours')
                                ->body("{$count} document(s) en cours d'indexation.")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
