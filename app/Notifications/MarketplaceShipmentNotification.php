<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use App\Models\MarketplaceShipment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification envoyée à l'artisan pour le suivi des expéditions.
 */
class MarketplaceShipmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MarketplaceOrder $order,
        public MarketplaceShipment $shipment,
        public string $event = 'shipped' // shipped, delivered, failed
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
        $message = (new MailMessage())
            ->greeting('Bonjour ' . $notifiable->name . ',');

        match ($this->event) {
            'shipped' => $this->buildShippedMail($message),
            'delivered' => $this->buildDeliveredMail($message),
            'failed' => $this->buildFailedMail($message),
            default => $message->line('Mise à jour de votre expédition.'),
        };

        return $message->action('Voir ma commande', url('/marketplace/orders/' . $this->order->uuid));
    }

    private function buildShippedMail(MailMessage $message): void
    {
        $shipment = $this->shipment;

        $message->subject('Votre commande est expédiée!')
            ->line('Bonne nouvelle! Une partie de votre commande vient d\'être expédiée.')
            ->line('')
            ->line('**Fournisseur:** ' . $shipment->supplier_name)
            ->line('**Transporteur:** ' . $shipment->carrier_name);

        if ($shipment->carrier_tracking_number) {
            $message->line('**N° de suivi:** ' . $shipment->carrier_tracking_number);
        }

        if ($shipment->estimated_delivery_at) {
            $message->line('**Livraison estimée:** ' . $shipment->estimated_delivery_at->format('d/m/Y'));
        }

        if ($shipment->carrier_tracking_url) {
            $message->line('')
                ->line('[Suivre mon colis](' . $shipment->carrier_tracking_url . ')');
        }
    }

    private function buildDeliveredMail(MailMessage $message): void
    {
        $message->subject('Livraison effectuée!')
            ->line('Votre commande a été livrée.')
            ->line('')
            ->line('**Fournisseur:** ' . $this->shipment->supplier_name)
            ->line('**Livré le:** ' . $this->shipment->delivered_at?->format('d/m/Y H:i'))
            ->line('')
            ->line('Si vous avez des questions sur les produits reçus, n\'hésitez pas à nous contacter.');
    }

    private function buildFailedMail(MailMessage $message): void
    {
        $message->subject('Problème de livraison')
            ->line('Nous avons rencontré un problème avec la livraison de votre commande.')
            ->line('')
            ->line('**Fournisseur:** ' . $this->shipment->supplier_name)
            ->line('')
            ->line('Notre équipe travaille pour résoudre ce problème. Vous serez tenu informé de l\'avancement.');
    }

    /**
     * Notification en base de données.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'marketplace_shipment_' . $this->event,
            'order_id' => $this->order->uuid,
            'shipment_id' => $this->shipment->uuid,
            'supplier_name' => $this->shipment->supplier_name,
            'carrier_name' => $this->shipment->carrier_name,
            'tracking_number' => $this->shipment->carrier_tracking_number,
            'event' => $this->event,
            'message' => match ($this->event) {
                'shipped' => 'Commande expédiée par ' . $this->shipment->supplier_name,
                'delivered' => 'Commande livrée',
                'failed' => 'Problème de livraison',
                default => 'Mise à jour d\'expédition',
            },
        ];
    }
}
