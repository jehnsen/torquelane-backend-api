<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Models\Branch;
use App\Models\DetailingDisplayToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tokens for a branch's wall screens. A token reads that branch's shop
 * monitor and nothing else; it is shown once, when it is made, and only its
 * SHA-256 is kept. Revoking is a timestamp: a revoked token is refused from
 * then on and stays on record.
 */
final class ManageDisplayTokens
{
    public const string PREFIX = 'tld_';

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly DetailingJournal $journal,
    ) {}

    /**
     * @return array{token: DetailingDisplayToken, plain: string}
     */
    public function issue(Branch $branch, string $name): array
    {
        return DB::transaction(function () use ($branch, $name): array {
            $plain = self::PREFIX.bin2hex(random_bytes(24));
            $token = new DetailingDisplayToken;
            $token->forceFill([
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'name' => trim($name),
                'token_hash' => DetailingDisplayToken::hash($plain),
                'token_hint' => substr($plain, -4),
                'created_by_name' => $this->journal->actorName(),
            ])->save();
            $this->audit->record($token, 'issued', null, AuditTrail::snapshot($token));

            return ['token' => $token, 'plain' => $plain];
        });
    }

    public function revoke(DetailingDisplayToken $token): DetailingDisplayToken
    {
        return DB::transaction(function () use ($token): DetailingDisplayToken {
            $locked = DetailingDisplayToken::query()->lockForUpdate()->findOrFail($token->id);
            if ($locked->revoked_at !== null) {
                return $locked;
            }
            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by_name' => $this->journal->actorName()])->save();
            $this->audit->record($locked, 'revoked', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }
}
