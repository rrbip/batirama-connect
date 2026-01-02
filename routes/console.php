<?php

declare(strict_types=1);

use App\Jobs\FetchSupportEmailsJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

// Scheduled tasks
Schedule::command('queue:work --stop-when-empty')->everyMinute()->withoutOverlapping();
Schedule::command('logs:purge --days=90')->daily();

// Support email polling (IMAP) - Dispatch job to queue for visibility
Schedule::job(new FetchSupportEmailsJob())
    ->everyMinute()
    ->withoutOverlapping()
    ->name('support:fetch-emails');
