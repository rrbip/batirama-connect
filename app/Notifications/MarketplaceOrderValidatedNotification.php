<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification envoyée à l'artisan quand une commande est validée.
 *
 * Confirme que la commande est prête à être transmise aux fournisseurs.
 */
class MarketplaceOrderValidatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MarketplaceOrder $order
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

        return (new MailMessage())
            ->subject('Commande validée - ' . ($order->quote_reference ?? $order->uuid))
            ->greeting('Bonjour ' . $notifiable->name . ',')
            ->line('Votre commande de matériaux a été validée et va être transmise aux fournisseurs.')
            ->line('')
            ->line('**Référence:** ' . ($order->quote_reference ?? $order->uuid))
            ->line('**Total HT:** ' . number_format($order->total_ht, 2, ',', ' ') . ' €')
            ->line('**Nombre de produits:** ' . $order->items()->where('line_status', 'included')->count())
            ->line('')
            ->line('Vous recevrez une notification dès que les produits seront expédiés.')
            ->action('Suivre ma commande', url('/marketplace/orders/' . $order->uuid));
    }

    /**
     * Notification en base de données.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'marketplace_order_validated',
            'order_id' => $this->order->uuid,
            'order_reference' => $this->order->quote_reference,
            'total_ht' => (float) $this->order->total_ht,
            'validated_at' => $this->order->validated_at?->toIso8601String(),
            'message' => 'Commande validée et transmise aux fournisseurs',
        ];
    }
}
