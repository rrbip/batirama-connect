<?php

declare(strict_types=1);

namespace App\Events\Whitelabel;

use App\Models\AiMessage;
use App\Models\AiSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AiSession $session,
        public AiMessage $message
    ) {}
}
