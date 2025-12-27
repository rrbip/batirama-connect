<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration pour les commandes marketplace.
 *
 * Permet aux artisans de commander des matériaux depuis les pré-devis
 * générés par l'IA dans les sessions whitelabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Table principale des commandes
        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relation avec la session whitelabel
            $table->foreignId('session_id')
                ->nullable()
                ->constrained('ai_sessions')
                ->nullOnDelete();

            // L'artisan qui passe la commande
            $table->foreignId('artisan_id')
                ->constrained('users')
                ->onDelete('cascade');

            // L'éditeur (pour tracking)
            $table->foreignId('editor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Référence du devis original (fournie par l'éditeur)
            $table->string('quote_reference', 100)->nullable();

            // Statut de la commande
            // pending_validation: en attente validation artisan
            // validated: validé par artisan, prêt pour commande
            // processing: en cours de traitement chez fournisseur
            // ordered: commandé chez fournisseur
            // shipped: expédié
            // delivered: livré
            // cancelled: annulé
            $table->string('status', 30)->default('pending_validation');

            // Totaux
            $table->decimal('subtotal_ht', 12, 2)->default(0);
            $table->decimal('tva_amount', 12, 2)->default(0);
            $table->decimal('shipping_ht', 10, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->decimal('tva_rate', 5, 2)->default(20);

            // Adresse de livraison
            $table->jsonb('delivery_address')->nullable();

            // Notes et commentaires
            $table->text('artisan_notes')->nullable();
            $table->text('internal_notes')->nullable();

            // Métadonnées
            $table->jsonb('metadata')->nullable();

            // Dates importantes
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Index
            $table->index('status');
            $table->index(['artisan_id', 'status']);
            $table->index('quote_reference');
        });

        // Table des lignes de commande
        Schema::create('marketplace_order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')
                ->constrained('marketplace_orders')
                ->onDelete('cascade');

            // Désignation originale du pré-devis
            $table->string('original_designation', 500);

            // Produit marketplace matché (nullable si non trouvé)
            $table->foreignId('product_id')->nullable();
            $table->string('product_sku', 100)->nullable();
            $table->string('product_name', 500)->nullable();

            // Statut du matching
            // matched: produit trouvé
            // partial_match: correspondance partielle
            // not_found: aucun produit trouvé
            // manual: sélection manuelle par l'artisan
            $table->string('match_status', 30)->default('not_found');
            $table->decimal('match_score', 5, 2)->nullable(); // Score de correspondance 0-100

            // Quantités
            $table->decimal('quantity', 10, 2);
            $table->string('unit', 20)->default('u');
            $table->decimal('quantity_ordered', 10, 2)->nullable(); // Peut différer après validation

            // Prix
            $table->decimal('unit_price_ht', 10, 2)->nullable();
            $table->decimal('line_total_ht', 12, 2)->nullable();

            // Statut de la ligne
            // pending: en attente
            // included: inclus dans la commande
            // excluded: exclu par l'artisan
            // substituted: remplacé par un autre produit
            $table->string('line_status', 30)->default('pending');

            // Produit de substitution si applicable
            $table->foreignId('substitution_product_id')->nullable();
            $table->string('substitution_reason')->nullable();

            // Métadonnées
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            // Index
            $table->index('match_status');
            $table->index('line_status');
            $table->index('product_sku');
        });

        // Table de suivi des expéditions
        Schema::create('marketplace_shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')
                ->constrained('marketplace_orders')
                ->onDelete('cascade');

            // Fournisseur
            $table->string('supplier_name', 200);
            $table->string('supplier_order_ref', 100)->nullable();

            // Transporteur
            $table->string('carrier_name', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('tracking_url', 500)->nullable();

            // Statut
            $table->string('status', 30)->default('pending');

            // Dates
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Métadonnées
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_shipments');
        Schema::dropIfExists('marketplace_order_items');
        Schema::dropIfExists('marketplace_orders');
    }
};
