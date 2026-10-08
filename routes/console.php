<?php

declare(strict_types=1);

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Support\Facades\Schedule;

// Proves cron + queue worker are both alive; read by GET /api/v1/health.
Schedule::job(new RecordQueueHeartbeat)->everyMinute()->name('queue-heartbeat');

// Expired Idempotency-Key rows (IdempotencyKey::prunable()).
Schedule::command('model:prune')->daily()->at('03:00')->timezone('Asia/Manila');
