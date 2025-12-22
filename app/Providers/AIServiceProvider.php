<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AI\DispatcherService;
use App\Services\AI\EmbeddingService;
use App\Services\AI\HydrationService;
use App\Services\AI\LearningService;
use App\Services\AI\OllamaService;
use App\Services\AI\PromptBuilder;
use App\Services\AI\QdrantService;
use App\Services\AI\RagService;
use App\Services\AI\Contracts\LLMServiceInterface;
use App\Services\AI\Contracts\VectorStoreInterface;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind les interfaces aux implémentations
        $this->app->singleton(LLMServiceInterface::class, OllamaService::class);
        $this->app->singleton(VectorStoreInterface::class, QdrantService::class);

        // Services singletons
        $this->app->singleton(OllamaService::class);
        $this->app->singleton(QdrantService::class);
        $this->app->singleton(EmbeddingService::class);
        $this->app->singleton(HydrationService::class);

        // Services avec dépendances
        $this->app->singleton(PromptBuilder::class, function ($app) {
            return new PromptBuilder(
                $app->make(HydrationService::class)
            );
        });

        $this->app->singleton(RagService::class, function ($app) {
            return new RagService(
                $app->make(EmbeddingService::class),
                $app->make(QdrantService::class),
                $app->make(OllamaService::class),
                $app->make(HydrationService::class),
                $app->make(PromptBuilder::class)
            );
        });

        $this->app->singleton(LearningService::class, function ($app) {
            return new LearningService(
                $app->make(EmbeddingService::class),
                $app->make(QdrantService::class)
            );
        });

        $this->app->singleton(DispatcherService::class, function ($app) {
            return new DispatcherService(
                $app->make(RagService::class),
                $app->make(LearningService::class),
                $app->make(EmbeddingService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publier les configurations si nécessaire
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/ai.php' => config_path('ai.php'),
                __DIR__.'/../../config/qdrant.php' => config_path('qdrant.php'),
            ], 'ai-config');
        }
    }
}
