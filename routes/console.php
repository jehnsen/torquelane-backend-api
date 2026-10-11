<?php

declare(strict_types=1);

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Support\Facades\Schedule;

// Proves cron + queue worker are both alive; read by GET /api/v1/health.
Schedule::job(new RecordQueueHeartbeat)->everyMinute()->name('queue-heartbeat');

// Expired Idempotency-Key rows (IdempotencyKey::prunable()).
Schedule::command('model:prune')->daily()->at('03:00')->timezone('Asia/Manila');

// CRM (Phase 11). Reminders are built once a morning; a message held through quiet hours, or missed by a worker,
// goes out with the minute-by-minute delivery run.
Schedule::command('crm:run reminders')->dailyAt('09:00')->timezone('Asia/Manila')->withoutOverlapping()->name('crm-reminders');
Schedule::command('crm:run campaigns')->everyFiveMinutes()->withoutOverlapping()->name('crm-campaigns');
Schedule::command('crm:run tasks')->everyFiveMinutes()->withoutOverlapping()->name('crm-task-reminders');
Schedule::command('crm:run deliver')->everyMinute()->withoutOverlapping()->name('crm-deliver');
