<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\RuleKey;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an event or category posts: one rule key, one account. Editable by
 * the organization's admins; a change applies to postings from then on and
 * never rewrites an entry already made.
 *
 * @property string $id
 * @property string $organization_id
 * @property RuleKey $rule_key
 * @property string $account_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Account $account
 */
final class PostingRule extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return ['rule_key' => RuleKey::class];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
