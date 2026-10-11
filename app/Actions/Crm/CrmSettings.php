<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Audit\AuditTrail;
use App\Domain\Crm\QuietHours;
use App\Models\CrmSetting;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** An organization's CRM settings. The row is made, with its defaults, the first time it is asked for. */
final class CrmSettings
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    public function current(): CrmSetting
    {
        return $this->of($this->tenancy->require()->organizationId());
    }

    public function of(string $organizationId): CrmSetting
    {
        $existing = CrmSetting::query()->where('organization_id', $organizationId)->first();
        if ($existing instanceof CrmSetting) {
            return $existing;
        }
        CrmSetting::query()->insertOrIgnore(['id' => strtolower((string) Str::ulid()), 'organization_id' => $organizationId, 'created_at' => now(), 'updated_at' => now()]);

        return CrmSetting::query()->where('organization_id', $organizationId)->firstOrFail();
    }

    public static function quietHours(CrmSetting $settings): QuietHours
    {
        return new QuietHours($settings->quiet_start_minutes, $settings->quiet_end_minutes);
    }

    /**
     * @param  array<string, int|bool>  $changes  validated; only the keys present change
     */
    public function save(array $changes): CrmSetting
    {
        return DB::transaction(function () use ($changes): CrmSetting {
            $settings = CrmSetting::query()->whereKey($this->current()->id)->lockForUpdate()->firstOrFail();
            $before = AuditTrail::snapshot($settings);
            $settings->forceFill($changes)->save();
            $this->audit->record($settings, 'updated', $before, AuditTrail::snapshot($settings));

            return $settings;
        });
    }
}
