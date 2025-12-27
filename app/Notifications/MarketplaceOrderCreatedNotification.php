<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification envoyée à l'artisan quand une commande marketplace est créée.
 *
 * Déclenchée après la signature d'un devis par le particulier.
 */
class MarketplaceOrderCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MarketplaceOrder $order,
        public array $matchingStats = []
    ) {}

    /**
     * Canaux de notification.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Notification par email.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $matchRate = $this->matchingStats['match_rate'] ?? 0;
        $needsReview = $this->matchingStats['needs_review'] ?? false;

        $message = (new MailMessage())
            ->subject('Nouvelle commande de matériaux à valider')
            ->greeting('Bonjour ' . $notifiable->name . ',')
            ->line('Un devis a été signé et une commande de matériaux a été créée.')
            ->line('**Référence devis:** ' . ($order->quote_reference ?? 'N/A'))
            ->line('**Nombre de produits:** ' . $order->items()->count())
            ->line('**Taux de correspondance:** ' . round($matchRate) . '%');

        if ($needsReview) {
            $unmatched = $this->matchingStats['unmatched_count'] ?? 0;
            $partial = $this->matchingStats['partial_count'] ?? 0;

            $message->line('');
            $message->line('⚠️ **Action requise:** ' . ($unmatched + $partial) . ' produit(s) nécessitent une validation manuelle.');
        }

        $message->action('Voir la commande', url('/marketplace/orders/' . $order->uuid));

        return $message;
    }

    /**
     * Notification en base de données.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'marketplace_order_created',
            'order_id' => $this->order->uuid,
            'order_reference' => $this->order->quote_reference,
            'items_count' => $this->order->items()->count(),
            'matching_stats' => $this->matchingStats,
            'needs_review' => $this->matchingStats['needs_review'] ?? false,
            'message' => 'Nouvelle commande marketplace créée',
        ];
    }
}
