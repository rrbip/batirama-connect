<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Whitelabel;

use App\Http\Controllers\Controller;
use App\Models\AiSession;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Notifications\MarketplaceOrderCreatedNotification;
use App\Notifications\MarketplaceOrderValidatedNotification;
use App\Services\Marketplace\SkuMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller pour les endpoints marketplace whitelabel.
 *
 * Gère la conversion des pré-devis en commandes marketplace.
 */
class MarketplaceController extends Controller
{
    public function __construct(
        private SkuMatchingService $skuMatchingService
    ) {}

    /**
     * Notification de devis signé.
     *
     * Appelé par l'éditeur quand le particulier signe le devis.
     * Crée une commande marketplace et démarre le matching produits.
     *
     * @OA\Post(
     *     path="/api/editor/marketplace/quote-signed",
     *     summary="Notifier la signature d'un devis",
     *     tags={"Marketplace"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"session_id"},
     *             @OA\Property(property="session_id", type="string", description="UUID de la session"),
     *             @OA\Property(property="quote_reference", type="string", description="Référence du devis dans l'ERP"),
     *             @OA\Property(property="quote_total_ht", type="number", description="Total HT du devis signé"),
     *             @OA\Property(property="signed_at", type="string", format="date-time"),
     *             @OA\Property(property="delivery_address", type="object",
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="street", type="string"),
     *                 @OA\Property(property="postal_code", type="string"),
     *                 @OA\Property(property="city", type="string"),
     *                 @OA\Property(property="phone", type="string")
     *             ),
     *             @OA\Property(property="items", type="array", description="Items du devis (optionnel, sinon utilise pre_quote_data)",
     *                 @OA\Items(
     *                     @OA\Property(property="designation", type="string"),
     *                     @OA\Property(property="quantity", type="number"),
     *                     @OA\Property(property="unit", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Commande marketplace créée",
     *         @OA\JsonContent(
     *             @OA\Property(property="order_id", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="matching", type="object")
     *         )
     *     )
     * )
     */
    public function quoteSigned(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'quote_reference' => 'nullable|string|max:100',
            'quote_total_ht' => 'nullable|numeric|min:0',
            'signed_at' => 'nullable|date',
            'delivery_address' => 'nullable|array',
            'delivery_address.name' => 'nullable|string|max:255',
            'delivery_address.street' => 'nullable|string|max:500',
            'delivery_address.postal_code' => 'nullable|string|max:20',
            'delivery_address.city' => 'nullable|string|max:100',
            'delivery_address.phone' => 'nullable|string|max:30',
            'items' => 'nullable|array',
            'items.*.designation' => 'required_with:items|string|max:500',
            'items.*.quantity' => 'required_with:items|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:50',
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        // Trouver la session par UUID
        $session = AiSession::where('uuid', $validated['session_id'])
            ->whereHas('deployment', fn ($q) => $q->where('editor_id', $editor->id))
            ->first();

        if (!$session) {
            return response()->json([
                'error' => 'session_not_found',
                'message' => 'Session non trouvée ou non autorisée',
            ], 404);
        }

        // Vérifier que la session a un pré-devis
        if (empty($session->pre_quote_data) && empty($validated['items'])) {
            return response()->json([
                'error' => 'no_pre_quote',
                'message' => 'Aucun pré-devis trouvé pour cette session et aucun item fourni',
            ], 400);
        }

        // Vérifier qu'il n'y a pas déjà une commande pour cette session
        $existingOrder = MarketplaceOrder::where('session_id', $session->id)->first();
        if ($existingOrder) {
            return response()->json([
                'error' => 'order_exists',
                'message' => 'Une commande existe déjà pour cette session',
                'order_id' => $existingOrder->uuid,
                'order_status' => $existingOrder->status,
            ], 409);
        }

        // Récupérer l'artisan lié à la session
        $artisan = $session->editorLink?->artisan;
        if (!$artisan) {
            return response()->json([
                'error' => 'artisan_not_found',
                'message' => 'Aucun artisan lié à cette session',
            ], 400);
        }

        // Vérifier que l'artisan a le marketplace activé
        if (!$artisan->marketplace_enabled) {
            return response()->json([
                'error' => 'marketplace_disabled',
                'message' => 'Le marketplace n\'est pas activé pour cet artisan',
            ], 403);
        }

        // Récupérer les items à matcher
        $items = $validated['items'] ?? $this->extractItemsFromPreQuote($session->pre_quote_data);

        if (empty($items)) {
            return response()->json([
                'error' => 'no_items',
                'message' => 'Aucun item à traiter',
            ], 400);
        }

        // Créer la commande et matcher les produits
        $result = DB::transaction(function () use (
            $session,
            $artisan,
            $validated,
            $items
        ) {
            // Créer la commande
            $order = MarketplaceOrder::createFromPreQuote(
                session: $session,
                preQuote: $session->pre_quote_data ?? [],
                artisan: $artisan,
                quoteReference: $validated['quote_reference'] ?? null,
                deliveryAddress: $validated['delivery_address'] ?? null
            );

            // Matcher les produits avec le catalogue
            $matchResult = $this->skuMatchingService->matchPreQuoteItems($items);

            // Créer les lignes de commande
            $orderItems = $this->skuMatchingService->createOrderItems($order->id, $matchResult);

            // Recalculer les totaux
            $order->recalculateTotals();

            return [
                'order' => $order->fresh(),
                'matching' => $matchResult,
                'items_count' => $orderItems->count(),
            ];
        });

        Log::info('Marketplace order created from signed quote', [
            'order_id' => $result['order']->uuid,
            'session_id' => $session->uuid,
            'artisan_id' => $artisan->id,
            'editor_id' => $editor->id,
            'items_count' => $result['items_count'],
            'match_rate' => $result['matching']->getMatchRate(),
        ]);

        // Notifier l'artisan
        $matchingStats = [
            'match_rate' => $result['matching']->getMatchRate(),
            'needs_review' => $result['matching']->needsManualReview(),
            'matched_count' => count($result['matching']->matched),
            'partial_count' => count($result['matching']->partial),
            'unmatched_count' => count($result['matching']->unmatched),
        ];
        $artisan->notify(new MarketplaceOrderCreatedNotification($result['order'], $matchingStats));

        return response()->json([
            'order_id' => $result['order']->uuid,
            'status' => $result['order']->status,
            'quote_reference' => $result['order']->quote_reference,
            'matching' => [
                'summary' => $result['matching']->getSummary(),
                'match_rate' => $result['matching']->getMatchRate(),
                'needs_review' => $result['matching']->needsManualReview(),
                'total_items' => $result['matching']->getTotalCount(),
                'matched_count' => count($result['matching']->matched),
                'partial_count' => count($result['matching']->partial),
                'unmatched_count' => count($result['matching']->unmatched),
            ],
            'totals' => [
                'estimated_total_ht' => (float) $result['order']->total_ht,
                'tva_rate' => (float) $result['order']->tva_rate,
            ],
            'next_steps' => $this->getNextSteps($result['matching']),
            'created_at' => $result['order']->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * Récupère les détails d'une commande marketplace.
     */
    public function getOrder(Request $request, string $orderId): JsonResponse
    {
        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $order = MarketplaceOrder::where('uuid', $orderId)
            ->whereHas('session.deployment', fn ($q) => $q->where('editor_id', $editor->id))
            ->with(['items', 'shipments'])
            ->first();

        if (!$order) {
            return response()->json([
                'error' => 'order_not_found',
                'message' => 'Commande non trouvée',
            ], 404);
        }

        return response()->json($order->toApiArray());
    }

    /**
     * Liste les commandes d'un artisan.
     */
    public function listOrders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'external_id' => 'nullable|string|max:100',
            'status' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $query = MarketplaceOrder::whereHas('session.deployment', fn ($q) => $q->where('editor_id', $editor->id))
            ->with(['session:id,uuid', 'artisan:id,name,company_name']);

        // Filtrer par external_id de l'artisan
        if (!empty($validated['external_id'])) {
            $query->whereHas('session.editorLink', fn ($q) => $q->where('external_id', $validated['external_id']));
        }

        // Filtrer par statut
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = $validated['per_page'] ?? 20;
        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'orders' => $orders->items(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * Valide une commande (tous les produits sont confirmés).
     */
    public function validateOrder(Request $request, string $orderId): JsonResponse
    {
        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $order = MarketplaceOrder::where('uuid', $orderId)
            ->whereHas('session.deployment', fn ($q) => $q->where('editor_id', $editor->id))
            ->first();

        if (!$order) {
            return response()->json([
                'error' => 'order_not_found',
                'message' => 'Commande non trouvée',
            ], 404);
        }

        if ($order->status !== MarketplaceOrder::STATUS_PENDING_VALIDATION) {
            return response()->json([
                'error' => 'invalid_status',
                'message' => 'La commande ne peut pas être validée dans son état actuel',
                'current_status' => $order->status,
            ], 400);
        }

        // Vérifier qu'il n'y a pas d'items non matchés ou partiels non résolus
        $unresolvedItems = $order->items()
            ->where('line_status', '!=', 'excluded')
            ->whereIn('match_status', ['not_found', 'partial_match'])
            ->count();

        if ($unresolvedItems > 0) {
            return response()->json([
                'error' => 'unresolved_items',
                'message' => "Il reste {$unresolvedItems} produit(s) à résoudre avant validation",
                'unresolved_count' => $unresolvedItems,
            ], 400);
        }

        $order->validate();

        Log::info('Marketplace order validated', [
            'order_id' => $order->uuid,
            'editor_id' => $editor->id,
        ]);

        // Notifier l'artisan
        $order->artisan?->notify(new MarketplaceOrderValidatedNotification($order->fresh()));

        return response()->json([
            'order_id' => $order->uuid,
            'status' => $order->status,
            'validated_at' => $order->validated_at?->toIso8601String(),
        ]);
    }

    /**
     * Annule une commande.
     */
    public function cancelOrder(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $order = MarketplaceOrder::where('uuid', $orderId)
            ->whereHas('session.deployment', fn ($q) => $q->where('editor_id', $editor->id))
            ->first();

        if (!$order) {
            return response()->json([
                'error' => 'order_not_found',
                'message' => 'Commande non trouvée',
            ], 404);
        }

        if (!$order->canBeCancelled()) {
            return response()->json([
                'error' => 'cannot_cancel',
                'message' => 'La commande ne peut pas être annulée dans son état actuel',
                'current_status' => $order->status,
            ], 400);
        }

        $order->cancel($validated['reason'] ?? null);

        Log::info('Marketplace order cancelled', [
            'order_id' => $order->uuid,
            'editor_id' => $editor->id,
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'order_id' => $order->uuid,
            'status' => $order->status,
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
        ]);
    }

    /**
     * Met à jour un item de commande (sélection manuelle, quantité, etc.).
     */
    public function updateOrderItem(Request $request, string $orderId, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:select_product,update_quantity,exclude,include',
            'product_id' => 'required_if:action,select_product|integer',
            'product_sku' => 'nullable|string',
            'product_name' => 'nullable|string',
            'unit_price_ht' => 'nullable|numeric|min:0',
            'quantity' => 'required_if:action,update_quantity|numeric|min:0',
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $order = MarketplaceOrder::where('uuid', $orderId)
            ->whereHas('session.deployment', fn ($q) => $q->where('editor_id', $editor->id))
            ->first();

        if (!$order) {
            return response()->json([
                'error' => 'order_not_found',
                'message' => 'Commande non trouvée',
            ], 404);
        }

        $item = $order->items()->where('uuid', $itemId)->first();
        if (!$item) {
            return response()->json([
                'error' => 'item_not_found',
                'message' => 'Ligne de commande non trouvée',
            ], 404);
        }

        match ($validated['action']) {
            'select_product' => $item->selectProduct(
                productId: $validated['product_id'],
                sku: $validated['product_sku'] ?? '',
                name: $validated['product_name'] ?? '',
                unitPrice: $validated['unit_price_ht'] ?? 0
            ),
            'update_quantity' => $item->updateQuantity($validated['quantity']),
            'exclude' => $item->exclude(),
            'include' => $item->include(),
        };

        // Recalculer les totaux
        $order->recalculateTotals();

        return response()->json([
            'item' => $item->fresh()->toApiArray(),
            'order_totals' => [
                'total_ht' => (float) $order->fresh()->total_ht,
            ],
        ]);
    }

    /**
     * Extrait les items d'un pré-devis stocké.
     */
    private function extractItemsFromPreQuote(array $preQuote): array
    {
        $items = $preQuote['items'] ?? $preQuote['lignes'] ?? [];

        return array_map(fn ($item) => [
            'designation' => $item['designation'] ?? $item['label'] ?? $item['description'] ?? '',
            'quantity' => $item['quantity'] ?? $item['quantite'] ?? $item['qty'] ?? 1,
            'unit' => $item['unit'] ?? $item['unite'] ?? 'unité',
        ], $items);
    }

    /**
     * Détermine les prochaines étapes selon le résultat du matching.
     */
    private function getNextSteps($matchResult): array
    {
        $steps = [];

        if ($matchResult->needsManualReview()) {
            if (count($matchResult->partial) > 0) {
                $steps[] = [
                    'action' => 'review_partial_matches',
                    'message' => 'Confirmer ou modifier les correspondances partielles',
                    'count' => count($matchResult->partial),
                ];
            }
            if (count($matchResult->unmatched) > 0) {
                $steps[] = [
                    'action' => 'select_products_manually',
                    'message' => 'Sélectionner manuellement les produits non trouvés',
                    'count' => count($matchResult->unmatched),
                ];
            }
            $steps[] = [
                'action' => 'validate_order',
                'message' => 'Valider la commande une fois tous les produits confirmés',
            ];
        } else {
            $steps[] = [
                'action' => 'validate_order',
                'message' => 'Tous les produits sont matchés, la commande peut être validée',
            ];
        }

        return $steps;
    }
}
