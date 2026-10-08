<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Auth\SessionDescription;
use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /me: who is signed in, where, and what they may do. The frontend gates
 * every control from `capabilities` and `modules.active` and renders
 * `branding`; it carries no role matrix of its own.
 *
 * @property SessionDescription $resource
 */
final class MeResource extends JsonResource
{
    public function __construct(SessionDescription $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $me = $this->resource;
        $context = $me->context;

        return [
            'user' => [
                'id' => $me->user->id,
                'name' => $me->user->name,
                'email' => $me->user->email,
                'title' => $me->user->title,
                'role' => $context->role->value,
                'role_label' => $context->role->label(),
                'side' => $context->scope->side->value,
                'status' => $me->user->status,
            ],
            'organization' => [
                'id' => $me->organization->id,
                'name' => $me->organization->name,
                'slug' => $me->organization->slug,
            ],
            'side' => $context->scope->side->value,
            'branches' => [
                'allowed' => array_map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'slug' => $branch->slug,
                    'status' => $branch->status,
                ], $me->branches),
                // One branch id, "all" (staff across every allowed branch), or null (portal).
                'selected' => $context->isStaff() ? ($context->selectedBranchId ?? 'all') : null,
                'restricted' => $context->branchRestricted,
            ],
            'customer_account' => $me->customerAccount === null ? null : [
                'id' => $me->customerAccount->id,
                'display_name' => $me->customerAccount->display_name,
                'account_type' => $me->customerAccount->account_type,
                'status' => $me->customerAccount->status,
            ],
            'capabilities' => array_map(fn (Capability $capability): string => $capability->value, $me->capabilities),
            'modules' => [
                'active' => self::values($me->activeModules),
                'organization' => self::values($me->organizationModules),
                'by_branch' => (object) array_map(self::values(...), $me->modulesByBranch),
            ],
            'branding' => [
                'display_name' => $me->branding->displayName,
                'logo_url' => $me->branding->logoUrl,
                'brand_color' => $me->branding->brandColor,
                'support_email' => $me->branding->supportEmail,
                'theme_tokens' => $me->branding->themeTokens === null ? null : (object) $me->branding->themeTokens,
            ],
        ];
    }

    /**
     * @param  list<Module>  $modules
     * @return list<string>
     */
    private static function values(array $modules): array
    {
        $values = [];
        foreach ($modules as $module) {
            $values[] = $module->value;
        }

        return $values;
    }
}
