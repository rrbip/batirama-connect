<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Marketplace\InMemoryProductCatalog;
use App\Services\Marketplace\MockSupplier;
use App\Services\Marketplace\OrderDispatcher;
use App\Services\Marketplace\ProductCatalogInterface;
use App\Services\Marketplace\SkuMatchingService;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider pour les services Marketplace.
 *
 * Configure les bindings pour:
 * - ProductCatalogInterface → implémentation selon environnement
 * - SkuMatchingService
 * - OrderDispatcher avec fournisseurs enregistrés
 */
class MarketplaceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Binding du catalogue produits
        // En production: remplacer par BatiramaProductCatalog ou API externe
        $this->app->singleton(ProductCatalogInterface::class, function () {
            $catalog = new InMemoryProductCatalog();

            // En local/testing, charger le catalogue par défaut
            if ($this->app->environment(['local', 'testing'])) {
                $catalog->loadDefaultCatalog();
            }

            return $catalog;
        });

        // Binding du service de matching
        $this->app->singleton(SkuMatchingService::class, function ($app) {
            return new SkuMatchingService(
                $app->make(ProductCatalogInterface::class)
            );
        });

        // Binding du dispatcher de commandes
        $this->app->singleton(OrderDispatcher::class, function ($app) {
            $dispatcher = new OrderDispatcher();

            // En local/testing, enregistrer le fournisseur mock
            if ($app->environment(['local', 'testing'])) {
                $dispatcher->registerSupplier(new MockSupplier(
                    identifier: 'batirama_mock',
                    name: 'BATIRAMA Marketplace (Mock)',
                    categories: ['peinture', 'enduit', 'placo', 'outillage', 'accessoire', 'primaire']
                ));
            }

            // TODO: En production, enregistrer les vrais fournisseurs
            // $dispatcher->registerSupplier(new BatiramaSupplier(...));

            return $dispatcher;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
