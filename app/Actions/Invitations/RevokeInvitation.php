<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Audit\AuditTrail;
use App\Exceptions\InvalidTransitionException;
use App\Models\Invitation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RevokeInvitation
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Invitation $invitation): Invitation
    {
        return DB::transaction(function () use ($invitation): Invitation {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (! $locked->isPending()) {
                throw new InvalidTransitionException('Only a pending invitation can be revoked.');
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['revoked_at' => CarbonImmutable::now('UTC')])->save();
            $this->audit->record($locked, 'revoked', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }
}
