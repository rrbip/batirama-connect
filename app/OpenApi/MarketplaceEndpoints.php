<?php

declare(strict_types=1);

namespace App\OpenApi;

/**
 * ═══════════════════════════════════════════════════════════════
 * MARKETPLACE ENDPOINTS (Phase 5)
 * ═══════════════════════════════════════════════════════════════
 *
 * @OA\Tag(
 *     name="Marketplace",
 *     description="Gestion des commandes marketplace et matching produits"
 * )
 *
 * ─────────────────────────────────────────────────────────────────
 * SCHEMAS
 * ─────────────────────────────────────────────────────────────────
 *
 * @OA\Schema(
 *     schema="QuoteSignedRequest",
 *     type="object",
 *     required={"session_id"},
 *     @OA\Property(property="session_id", type="string", format="uuid", description="UUID de la session IA"),
 *     @OA\Property(property="quote_reference", type="string", example="DEV-2025-001", description="Référence du devis dans l'ERP"),
 *     @OA\Property(property="quote_total_ht", type="number", format="float", example=1250.50, description="Total HT du devis signé"),
 *     @OA\Property(property="signed_at", type="string", format="date-time", description="Date de signature"),
 *     @OA\Property(property="delivery_address", type="object",
 *         @OA\Property(property="name", type="string", example="M. Martin"),
 *         @OA\Property(property="street", type="string", example="12 rue de la Paix"),
 *         @OA\Property(property="postal_code", type="string", example="75001"),
 *         @OA\Property(property="city", type="string", example="Paris"),
 *         @OA\Property(property="phone", type="string", example="0612345678")
 *     ),
 *     @OA\Property(property="items", type="array", description="Items du devis (optionnel si pre_quote_data existe)",
 *         @OA\Items(type="object",
 *             @OA\Property(property="designation", type="string", example="Peinture acrylique blanche 10L"),
 *             @OA\Property(property="quantity", type="number", example=5),
 *             @OA\Property(property="unit", type="string", example="seau")
 *         )
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="MarketplaceOrderResponse",
 *     type="object",
 *     @OA\Property(property="order_id", type="string", format="uuid"),
 *     @OA\Property(property="status", type="string", enum={"pending_validation", "validated", "processing", "ordered", "shipped", "delivered", "cancelled"}),
 *     @OA\Property(property="quote_reference", type="string"),
 *     @OA\Property(property="matching", type="object",
 *         @OA\Property(property="summary", type="string", example="3/5 produits matchés (60.0%)"),
 *         @OA\Property(property="match_rate", type="number", example=60.0),
 *         @OA\Property(property="needs_review", type="boolean"),
 *         @OA\Property(property="total_items", type="integer"),
 *         @OA\Property(property="matched_count", type="integer"),
 *         @OA\Property(property="partial_count", type="integer"),
 *         @OA\Property(property="unmatched_count", type="integer")
 *     ),
 *     @OA\Property(property="totals", type="object",
 *         @OA\Property(property="estimated_total_ht", type="number", format="float"),
 *         @OA\Property(property="tva_rate", type="number", example=20)
 *     ),
 *     @OA\Property(property="next_steps", type="array",
 *         @OA\Items(type="object",
 *             @OA\Property(property="action", type="string"),
 *             @OA\Property(property="message", type="string"),
 *             @OA\Property(property="count", type="integer")
 *         )
 *     ),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="MarketplaceOrderDetailResponse",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="quote_reference", type="string"),
 *     @OA\Property(property="status", type="string"),
 *     @OA\Property(property="status_label", type="string"),
 *     @OA\Property(property="items_count", type="integer"),
 *     @OA\Property(property="matched_items_count", type="integer"),
 *     @OA\Property(property="subtotal_ht", type="number"),
 *     @OA\Property(property="tva_rate", type="number"),
 *     @OA\Property(property="total_ttc", type="number"),
 *     @OA\Property(property="delivery_address", type="object"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="validated_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="items", type="array",
 *         @OA\Items(ref="#/components/schemas/MarketplaceOrderItem")
 *     ),
 *     @OA\Property(property="shipments", type="array",
 *         @OA\Items(ref="#/components/schemas/MarketplaceShipment")
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="MarketplaceOrderItem",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="original_designation", type="string"),
 *     @OA\Property(property="product", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer"),
 *         @OA\Property(property="sku", type="string"),
 *         @OA\Property(property="name", type="string"),
 *         @OA\Property(property="unit_price_ht", type="number")
 *     ),
 *     @OA\Property(property="match_status", type="string", enum={"matched", "partial_match", "not_found", "manual"}),
 *     @OA\Property(property="match_score", type="number", nullable=true),
 *     @OA\Property(property="quantity", type="number"),
 *     @OA\Property(property="quantity_ordered", type="number", nullable=true),
 *     @OA\Property(property="unit", type="string"),
 *     @OA\Property(property="line_total_ht", type="number", nullable=true),
 *     @OA\Property(property="line_status", type="string", enum={"pending", "included", "excluded", "substituted"})
 * )
 *
 * @OA\Schema(
 *     schema="MarketplaceShipment",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="supplier_name", type="string"),
 *     @OA\Property(property="supplier_order_ref", type="string"),
 *     @OA\Property(property="carrier", type="object",
 *         @OA\Property(property="name", type="string"),
 *         @OA\Property(property="tracking_number", type="string", nullable=true),
 *         @OA\Property(property="tracking_url", type="string", nullable=true)
 *     ),
 *     @OA\Property(property="status", type="string", enum={"pending", "preparing", "shipped", "in_transit", "delivered", "failed"}),
 *     @OA\Property(property="status_label", type="string"),
 *     @OA\Property(property="estimated_delivery_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="shipped_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="delivered_at", type="string", format="date-time", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="UpdateOrderItemRequest",
 *     type="object",
 *     required={"action"},
 *     @OA\Property(property="action", type="string", enum={"select_product", "update_quantity", "exclude", "include"}),
 *     @OA\Property(property="product_id", type="integer", description="Requis si action=select_product"),
 *     @OA\Property(property="product_sku", type="string"),
 *     @OA\Property(property="product_name", type="string"),
 *     @OA\Property(property="unit_price_ht", type="number"),
 *     @OA\Property(property="quantity", type="number", description="Requis si action=update_quantity")
 * )
 *
 * ─────────────────────────────────────────────────────────────────
 * ENDPOINTS
 * ─────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/editor/marketplace/quote-signed",
 *     summary="Notifier la signature d'un devis",
 *     description="Crée une commande marketplace à partir d'un devis signé. Lance le matching automatique des produits.",
 *     operationId="notifyQuoteSigned",
 *     tags={"Marketplace"},
 *     security={{"editorApiKey": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/QuoteSignedRequest")
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="Commande créée avec résultat du matching",
 *         @OA\JsonContent(ref="#/components/schemas/MarketplaceOrderResponse")
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Données invalides ou session sans pré-devis",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Session non trouvée",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=409,
 *         description="Une commande existe déjà pour cette session",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Get(
 *     path="/editor/marketplace/orders",
 *     summary="Lister les commandes marketplace",
 *     description="Retourne la liste des commandes marketplace de l'éditeur.",
 *     operationId="listMarketplaceOrders",
 *     tags={"Marketplace"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="external_id",
 *         in="query",
 *         required=false,
 *         description="Filtrer par external_id de l'artisan",
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\Parameter(
 *         name="status",
 *         in="query",
 *         required=false,
 *         description="Filtrer par statut",
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\Parameter(
 *         name="page",
 *         in="query",
 *         required=false,
 *         @OA\Schema(type="integer", default=1)
 *     ),
 *     @OA\Parameter(
 *         name="per_page",
 *         in="query",
 *         required=false,
 *         @OA\Schema(type="integer", default=20, maximum=100)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Liste paginée des commandes",
 *         @OA\JsonContent(
 *             @OA\Property(property="orders", type="array",
 *                 @OA\Items(ref="#/components/schemas/MarketplaceOrderDetailResponse")
 *             ),
 *             @OA\Property(property="pagination", type="object",
 *                 @OA\Property(property="current_page", type="integer"),
 *                 @OA\Property(property="per_page", type="integer"),
 *                 @OA\Property(property="total", type="integer"),
 *                 @OA\Property(property="last_page", type="integer")
 *             )
 *         )
 *     )
 * )
 *
 * @OA\Get(
 *     path="/editor/marketplace/orders/{order_id}",
 *     summary="Détails d'une commande",
 *     description="Retourne les détails complets d'une commande avec ses items et expéditions.",
 *     operationId="getMarketplaceOrder",
 *     tags={"Marketplace"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="order_id",
 *         in="path",
 *         required=true,
 *         description="UUID de la commande",
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Détails de la commande",
 *         @OA\JsonContent(ref="#/components/schemas/MarketplaceOrderDetailResponse")
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Commande non trouvée",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Post(
 *     path="/editor/marketplace/orders/{order_id}/validate",
 *     summary="Valider une commande",
 *     description="Valide une commande après résolution de tous les items. La commande sera transmise aux fournisseurs.",
 *     operationId="validateMarketplaceOrder",
 *     tags={"Marketplace"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="order_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Commande validée",
 *         @OA\JsonContent(
 *             @OA\Property(property="order_id", type="string"),
 *             @OA\Property(property="status", type="string"),
 *             @OA\Property(property="validated_at", type="string", format="date-time")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Items non résolus ou statut invalide",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Post(
 *     path="/editor/marketplace/orders/{order_id}/cancel",
 *     summary="Annuler une commande",
 *     description="Annule une commande en attente de validation ou validée.",
 *     operationId="cancelMarketplaceOrder",
 *     tags={"Marketplace"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="order_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\RequestBody(
 *         required=false,
 *         @OA\JsonContent(
 *             @OA\Property(property="reason", type="string", description="Raison de l'annulation")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Commande annulée",
 *         @OA\JsonContent(
 *             @OA\Property(property="order_id", type="string"),
 *             @OA\Property(property="status", type="string"),
 *             @OA\Property(property="cancelled_at", type="string", format="date-time")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Commande ne peut pas être annulée",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Patch(
 *     path="/editor/marketplace/orders/{order_id}/items/{item_id}",
 *     summary="Modifier un item de commande",
 *     description="Permet de sélectionner manuellement un produit, modifier la quantité, ou exclure un item.",
 *     operationId="updateMarketplaceOrderItem",
 *     tags={"Marketplace"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="order_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\Parameter(
 *         name="item_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/UpdateOrderItemRequest")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Item mis à jour",
 *         @OA\JsonContent(
 *             @OA\Property(property="item", ref="#/components/schemas/MarketplaceOrderItem"),
 *             @OA\Property(property="order_totals", type="object",
 *                 @OA\Property(property="total_ht", type="number")
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Commande ou item non trouvé",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 */
class MarketplaceEndpoints
{
    // Cette classe sert uniquement de conteneur pour les annotations OpenAPI
}
