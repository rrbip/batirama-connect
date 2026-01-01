<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Support\NewSupportMessage;
use App\Events\Support\SessionEscalated;
use App\Listeners\Support\NotifyOnNewSupportMessage;
use App\Listeners\Support\NotifySupportAgentsOnEscalation;
use App\Listeners\Whitelabel\DispatchWebhookListener;
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
        // Register webhook event subscriber
        Event::subscribe(DispatchWebhookListener::class);

        // Notifications Filament pour le support
        Event::listen(
            SessionEscalated::class,
            NotifySupportAgentsOnEscalation::class
        );

        Event::listen(
            NewSupportMessage::class,
            NotifyOnNewSupportMessage::class
        );
    }
}
