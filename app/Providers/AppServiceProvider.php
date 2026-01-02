<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Support\NewSupportMessage;
use App\Listeners\Support\NotifyOnNewSupportMessage;
use App\Listeners\Whitelabel\DispatchWebhookListener;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register broadcasting routes for Soketi/Pusher authentication
        Broadcast::routes(['middleware' => ['web', 'auth']]);

        // Load broadcast channel authorization callbacks
        require base_path('routes/channels.php');

        // Register webhook event subscriber
        Event::subscribe(DispatchWebhookListener::class);

        // Notifications Filament pour les nouveaux messages support
        // Note: Les notifications d'escalade sont gérées directement par EscalationService::notifyAvailableAgents()
        Event::listen(
            NewSupportMessage::class,
            NotifyOnNewSupportMessage::class
        );
    }
}
