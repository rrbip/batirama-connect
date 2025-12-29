<?php

declare(strict_types=1);

namespace App\Events\Whitelabel;

use App\Models\AiSession;
use App\Models\SessionFile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileUploaded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AiSession $session,
        public SessionFile $file
    ) {}
}
