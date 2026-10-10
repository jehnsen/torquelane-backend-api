<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ledger\BackfillLedger;
use App\Models\Organization;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * `php artisan ledger:backfill [--organization=ID] [--dry-run]`: posts the
 * journal entries for money and stock events that have none. Idempotent.
 */
final class BackfillLedgerCommand extends Command
{
    protected $signature = 'ledger:backfill {--organization= : Only this organization id} {--dry-run : Count what would be posted, post nothing}';

    protected $description = 'Post journal entries for invoices, payments and stock moves that have none (safe to re-run)';

    public function handle(BackfillLedger $backfill, TenantManager $tenancy): int
    {
        $only = $this->option('organization');
        $organizations = $tenancy->system('ledger backfill', fn () => Organization::query()
            ->when(is_string($only) && $only !== '', fn ($q) => $q->whereKey($only))->orderBy('id')->get());

        foreach ($organizations as $organization) {
            $result = $backfill->organization($organization, (bool) $this->option('dry-run'));
            $summary = $result['posted'] === [] ? 'nothing to post' : implode(', ', array_map(fn (string $kind, int $n): string => "{$n} {$kind}", array_keys($result['posted']), array_values($result['posted'])));
            $this->info(sprintf('%s: %s%s', $organization->name, $summary, $result['skipped_closed'] > 0 ? " ({$result['skipped_closed']} left: their month is closed)" : ''));
        }

        return self::SUCCESS;
    }
}
