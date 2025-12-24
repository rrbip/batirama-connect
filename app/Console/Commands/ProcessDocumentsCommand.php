<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Console\Command;

class ProcessDocumentsCommand extends Command
{
    protected $signature = 'documents:process
                            {--pending : Traiter uniquement les documents en attente}
                            {--failed : Traiter uniquement les documents échoués}
                            {--id=* : ID(s) spécifique(s) de document à traiter}
                            {--all : Retraiter tous les documents}';

    protected $description = 'Traite les documents RAG (extraction, chunking, indexation)';

    public function handle(): int
    {
        $query = Document::query();

        // Filtrer par IDs spécifiques
        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        } elseif ($this->option('pending')) {
            $query->where('extraction_status', 'pending');
        } elseif ($this->option('failed')) {
            $query->where('extraction_status', 'failed');
        } elseif ($this->option('all')) {
            // Tous les documents
        } else {
            // Par défaut: pending + failed
            $query->whereIn('extraction_status', ['pending', 'failed']);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->info('Aucun document à traiter.');
            return Command::SUCCESS;
        }

        $this->info("Traitement de {$documents->count()} document(s)...");
        $this->newLine();

        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        $success = 0;
        $errors = 0;

        foreach ($documents as $document) {
            try {
                // Réinitialiser le statut si échoué
                if ($document->extraction_status === 'failed') {
                    $document->update([
                        'extraction_status' => 'pending',
                        'extraction_error' => null,
                    ]);
                }

                // Exécution synchrone
                ProcessDocumentJob::dispatchSync($document);

                // Recharger pour vérifier le statut
                $document->refresh();

                if ($document->extraction_status === 'completed') {
                    $success++;
                } else {
                    $errors++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Erreur sur {$document->original_name}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Terminé: {$success} succès, {$errors} erreur(s)");

        // Afficher le résumé
        $this->newLine();
        $this->table(
            ['Statut', 'Nombre'],
            [
                ['Terminés', Document::where('extraction_status', 'completed')->count()],
                ['En attente', Document::where('extraction_status', 'pending')->count()],
                ['Echoués', Document::where('extraction_status', 'failed')->count()],
                ['Indexés', Document::where('is_indexed', true)->count()],
            ]
        );

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
