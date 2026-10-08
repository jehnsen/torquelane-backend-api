<?php

declare(strict_types=1);

use App\Actions\System\CheckHealth;
use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;

it('reports ok when the database answers and the queue heartbeat is fresh', function () {
    RecordQueueHeartbeat::dispatch();

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.checks.database.status', 'ok')
        ->assertJsonPath('data.checks.queue.status', 'ok')
        ->assertJsonPath('data.checks.queue.connection', 'sync')
        ->assertJsonStructure(['data' => [
            'status',
            'checked_at',
            'checks' => [
                'database' => ['status', 'latency_ms'],
                'queue' => ['status', 'connection', 'pending_jobs', 'last_heartbeat_at'],
            ],
        ]]);
});

it('reports degraded, still 200, when no worker has run the heartbeat', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'degraded')
        ->assertJsonPath('data.checks.queue.status', 'down')
        ->assertJsonPath('data.checks.queue.last_heartbeat_at', null);
});

it('reports degraded when the heartbeat is older than five minutes', function () {
    RecordQueueHeartbeat::dispatch();
    $this->travel(6)->minutes();

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'degraded');
});

it('answers 503 in the error envelope when the database is unreachable', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->andThrow(new RuntimeException('connection refused'));

    $this->app->instance(CheckHealth::class, new CheckHealth(
        $db,
        $this->app->make(Repository::class),
        $this->app->make(QueueFactory::class),
        $this->app->make(Config::class),
    ));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'server_error')
        ->assertJsonPath('error.details.health.status', 'down')
        ->assertJsonPath('error.details.health.checks.database', ['status' => 'down', 'latency_ms' => null]);
});

it('schedules the queue heartbeat every minute', function () {
    $events = collect($this->app->make(Schedule::class)->events())
        ->filter(fn ($event) => $event->description === 'queue-heartbeat');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
