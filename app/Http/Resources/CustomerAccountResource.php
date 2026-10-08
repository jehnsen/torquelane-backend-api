<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerAccount;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Staff see the whole record. A portal user sees their own account without
 * the shop's internal CRM fields (notes, tags, lead source).
 *
 * `approval_threshold_overrides` is null (inherit everything) or an object,
 * `{}` included: the distinction is stored and rendered as-is.
 *
 * @property CustomerAccount $resource
 */
final class CustomerAccountResource extends JsonResource
{
    public function __construct(CustomerAccount $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->resource;
        $staff = app(TenantManager::class)->context()?->isStaff() ?? false;

        return [
            'id' => $account->id,
            'organization_id' => $account->organization_id,
            'account_type' => $account->account_type,
            'display_name' => $account->display_name,
            'registered_name' => $account->registered_name,
            'tin' => $account->tin,
            'address' => $account->address,
            'first_name' => $account->first_name,
            'last_name' => $account->last_name,
            'nickname' => $account->nickname,
            'mobile' => $account->mobile,
            'email' => $account->email,
            'birthday' => $account->birthday?->toDateString(),
            'contact_name' => $account->contact_name,
            'contact_email' => $account->contact_email,
            'payment_terms_days' => $account->payment_terms_days,
            'credit_limit_cents' => $account->credit_limit_cents?->getMinorAmount()->toInt(),
            'approval_threshold_overrides' => $account->approval_threshold_overrides === null ? null : (object) $account->approval_threshold_overrides,
            'logo_url' => $account->logo_url,
            'brand_color' => $account->brand_color,
            'status' => $account->status,
            ...($staff ? [
                'tags' => $account->tags,
                'source' => $account->source,
                'notes' => $account->notes,
            ] : []),
            'created_at' => $account->created_at->toIso8601ZuluString(),
            'updated_at' => $account->updated_at->toIso8601ZuluString(),
        ];
    }
}
