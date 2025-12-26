<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactoring du crawler web pour supporter plusieurs agents par crawl.
 *
 * Changements :
 * - web_crawls devient un cache pur (plus d'agent_id)
 * - agent_web_crawls : configuration par agent (filtres, chunking, stats)
 * - agent_web_crawl_urls : statut d'indexation par URL par agent
 * - web_crawl_url_crawl simplifié (plus de document_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Créer la table agent_web_crawls
        Schema::create('agent_web_crawls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('web_crawl_id')->constrained()->cascadeOnDelete();

            // Configuration de filtrage (déplacée depuis web_crawls)
            $table->string('url_filter_mode', 10)->default('exclude');
            $table->jsonb('url_patterns')->default('[]');

            // Types de contenu à indexer
            $table->jsonb('content_types')->default('["html", "pdf", "image", "document"]');

            // Stratégie de chunking (NULL = utilise Agent.default_chunk_strategy)
            $table->string('chunk_strategy', 50)->nullable();

            // Statut d'indexation
            $table->string('index_status', 20)->default('pending');
            // pending, indexing, indexed, error

            // Statistiques (déplacées depuis web_crawls)
            $table->integer('pages_indexed')->default(0);
            $table->integer('pages_skipped')->default(0);
            $table->integer('pages_error')->default(0);

            $table->timestamp('last_indexed_at')->nullable();
            $table->timestamps();

            // Index unique : un agent ne peut être lié qu'une fois à un crawl
            $table->unique(['agent_id', 'web_crawl_id']);
            $table->index('index_status');
        });

        // 2. Créer la table agent_web_crawl_urls
        Schema::create('agent_web_crawl_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_web_crawl_id')->constrained()->cascadeOnDelete();
            $table->foreignId('web_crawl_url_id')->constrained()->cascadeOnDelete();

            // Document RAG créé (nullable car peut être skipped)
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            // Statut d'indexation pour cet agent
            $table->string('status', 20)->default('pending');
            // pending, indexed, skipped, error

            // Raison du skip ou erreur
            $table->string('skip_reason', 100)->nullable();
            $table->text('error_message')->nullable();

            // Pattern qui a matché (pour debug)
            $table->string('matched_pattern', 500)->nullable();

            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            // Index unique : une URL par agent_web_crawl
            $table->unique(['agent_web_crawl_id', 'web_crawl_url_id']);
            $table->index('status');
            $table->index('document_id');
        });

        // 3. Migrer les données existantes
        $this->migrateExistingData();

        // 4. Modifier web_crawls : supprimer les colonnes agent-specific
        Schema::table('web_crawls', function (Blueprint $table) {
            // Supprimer la contrainte foreign key sur agent_id
            $table->dropForeign(['agent_id']);
            $table->dropColumn([
                'agent_id',
                'url_filter_mode',
                'url_patterns',
                'pages_indexed',
                'pages_skipped',
                'pages_error',
                'documents_found',
                'images_found',
            ]);
        });

        // Supprimer chunk_strategy s'il existe (ajouté par migration précédente)
        if (Schema::hasColumn('web_crawls', 'chunk_strategy')) {
            Schema::table('web_crawls', function (Blueprint $table) {
                $table->dropColumn('chunk_strategy');
            });
        }

        // 5. Modifier web_crawl_url_crawl : simplifier
        Schema::table('web_crawl_url_crawl', function (Blueprint $table) {
            // Supprimer la contrainte foreign key sur document_id
            if (Schema::hasColumn('web_crawl_url_crawl', 'document_id')) {
                $table->dropForeign(['document_id']);
                $table->dropColumn([
                    'document_id',
                    'matched_pattern',
                    'skip_reason',
                    'indexed_at',
                ]);
            }
        });
    }

    public function down(): void
    {
        // Restaurer web_crawl_url_crawl
        Schema::table('web_crawl_url_crawl', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('matched_pattern', 500)->nullable();
            $table->string('skip_reason', 100)->nullable();
            $table->timestamp('indexed_at')->nullable();
        });

        // Restaurer web_crawls
        Schema::table('web_crawls', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url_filter_mode', 10)->default('exclude');
            $table->jsonb('url_patterns')->default('[]');
            $table->integer('pages_indexed')->default(0);
            $table->integer('pages_skipped')->default(0);
            $table->integer('pages_error')->default(0);
            $table->integer('documents_found')->default(0);
            $table->integer('images_found')->default(0);
        });

        // Supprimer les nouvelles tables
        Schema::dropIfExists('agent_web_crawl_urls');
        Schema::dropIfExists('agent_web_crawls');
    }

    /**
     * Migrer les données existantes vers la nouvelle structure.
     */
    private function migrateExistingData(): void
    {
        // Récupérer tous les web_crawls existants
        $crawls = DB::table('web_crawls')->get();

        foreach ($crawls as $crawl) {
            // Créer l'entrée agent_web_crawl
            $agentWebCrawlId = DB::table('agent_web_crawls')->insertGetId([
                'agent_id' => $crawl->agent_id,
                'web_crawl_id' => $crawl->id,
                'url_filter_mode' => $crawl->url_filter_mode,
                'url_patterns' => $crawl->url_patterns,
                'content_types' => json_encode(['html', 'pdf', 'image', 'document']),
                'chunk_strategy' => null, // Utilisera le défaut de l'agent
                'index_status' => $crawl->status === 'completed' ? 'indexed' : 'pending',
                'pages_indexed' => $crawl->pages_indexed,
                'pages_skipped' => $crawl->pages_skipped,
                'pages_error' => $crawl->pages_error,
                'last_indexed_at' => $crawl->completed_at,
                'created_at' => $crawl->created_at,
                'updated_at' => $crawl->updated_at,
            ]);

            // Migrer les entrées web_crawl_url_crawl vers agent_web_crawl_urls
            $urlCrawls = DB::table('web_crawl_url_crawl')
                ->where('crawl_id', $crawl->id)
                ->get();

            foreach ($urlCrawls as $urlCrawl) {
                // Déterminer le statut
                $status = match ($urlCrawl->status) {
                    'indexed' => 'indexed',
                    'skipped' => 'skipped',
                    'error' => 'error',
                    default => 'pending',
                };

                DB::table('agent_web_crawl_urls')->insert([
                    'agent_web_crawl_id' => $agentWebCrawlId,
                    'web_crawl_url_id' => $urlCrawl->crawl_url_id,
                    'document_id' => $urlCrawl->document_id,
                    'status' => $status,
                    'skip_reason' => $urlCrawl->skip_reason,
                    'error_message' => $urlCrawl->error_message,
                    'matched_pattern' => $urlCrawl->matched_pattern,
                    'indexed_at' => $urlCrawl->indexed_at,
                    'created_at' => $urlCrawl->created_at,
                    'updated_at' => $urlCrawl->updated_at,
                ]);
            }
        }
    }
};
