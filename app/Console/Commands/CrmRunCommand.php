<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Crm\BuildReminders;
use App\Actions\Crm\CrmContexts;
use App\Actions\Crm\ManageCampaigns;
use App\Actions\Crm\ManageRelationships;
use App\Database\Cell;
use App\Jobs\SendMessage;
use App\Models\Message;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The CRM's scheduled work, one task per run:
 *
 *  - `reminders`: build the day's service reminders, per organization;
 *  - `campaigns`: start and advance campaigns, within their hourly limit;
 *  - `tasks`: tell assignees about tasks whose reminder time has come;
 *  - `deliver`: send queued messages whose time has come (those held through
 *    quiet hours, and any a worker missed).
 */
final class CrmRunCommand extends Command
{
    protected $signature = 'crm:run {task : reminders, campaigns, tasks or deliver}';

    protected $description = 'Run one of the CRM\'s scheduled tasks';

    public function handle(CrmContexts $contexts, TenantManager $tenancy): int
    {
        $task = $this->argument('task');
        if ($task === 'deliver') {
            $due = $tenancy->system('message delivery', fn (): array => Message::query()->where('status', Message::QUEUED)
                ->where(fn ($q) => $q->whereNull('send_after')->orWhere('send_after', '<=', CarbonImmutable::now()))->orderBy('created_at')->limit(500)->pluck('id')->all());
            foreach ($due as $id) {
                SendMessage::dispatch(Cell::string($id));
            }
            $this->info(count($due).' message(s) handed to delivery.');

            return self::SUCCESS;
        }
        if (! in_array($task, ['reminders', 'campaigns', 'tasks'], true)) {
            $this->error('Unknown task. Use reminders, campaigns, tasks or deliver.');

            return self::INVALID;
        }

        $contexts->each(function (string $organizationId) use ($task): void {
            $result = match ($task) {
                'reminders' => app(BuildReminders::class)->run(),
                'campaigns' => app(ManageCampaigns::class)->run(),
                default => ['sent' => app(ManageRelationships::class)->sendTaskReminders()],
            };
            $this->line($organizationId.': '.json_encode($result));
        });

        return self::SUCCESS;
    }
}
