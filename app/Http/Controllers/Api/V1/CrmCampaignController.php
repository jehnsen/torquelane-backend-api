<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Crm\ManageCampaigns;
use App\Domain\Crm\SegmentFilter;
use App\Http\Requests\Crm\CrmRequest;
use App\Http\Resources\CrmPresenter;
use App\Models\CrmCampaign;
use App\Models\CrmSegment;
use App\Models\CrmSetting;
use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Segments and campaigns (staff, `crm` module). */
final class CrmCampaignController
{
    public function __construct(private readonly ManageCampaigns $campaigns) {}

    /**
     * List segments
     *
     * `crm:view`. Each with how many customers it holds right now.
     */
    public function segments(): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return new JsonResponse(['data' => array_values(CrmSegment::query()->orderBy('name')->get()
            ->map(fn (CrmSegment $segment): array => CrmPresenter::segment($segment, count($this->campaigns->members(SegmentFilter::fromArray($segment->filters)))))->all())]);
    }

    /**
     * Who a filter would match
     *
     * `crm:view`. For the segment form: the customers `filters` matches now, before it is saved.
     */
    public function preview(CrmRequest $request): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return $this->memberList($this->campaigns->members(SegmentFilter::fromArray($request->segmentData()['filters'] ?? [])));
    }

    /**
     * A segment's customers
     *
     * `crm:view`. Worked out now from the segment's definition.
     */
    public function members(CrmSegment $segment): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return $this->memberList($this->campaigns->members(SegmentFilter::fromArray($segment->filters)));
    }

    /**
     * Add a segment
     *
     * `crm:manage`. Conditions on spend, last visit, vehicle make and size, tier, branch, account type and tag.
     */
    public function storeSegment(CrmRequest $request): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::segment($this->campaigns->saveSegment(null, $request->segmentData()))], 201);
    }

    /**
     * Edit a segment
     *
     * `crm:manage`. A campaign already sending keeps the audience it started with.
     */
    public function updateSegment(CrmRequest $request, CrmSegment $segment): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::segment($this->campaigns->saveSegment($segment, $request->segmentData()))]);
    }

    /**
     * Delete a segment
     *
     * `crm:manage`. Not one a campaign uses (409).
     */
    public function destroySegment(CrmSegment $segment): Response
    {
        Gate::authorize('manage', CrmSetting::class);
        $this->campaigns->deleteSegment($segment);

        return response()->noContent();
    }

    /**
     * List campaigns
     *
     * `crm:view`. Newest first, each with what became of its audience.
     */
    public function campaigns(): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return new JsonResponse(['data' => array_values(CrmCampaign::query()->with(['segment', 'template'])->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (CrmCampaign $campaign): array => CrmPresenter::campaign($campaign, $this->campaigns->progress($campaign)))->all())]);
    }

    /**
     * Show a campaign
     *
     * With its progress: sent, queued, failed, skipped (by reason), still to go.
     */
    public function showCampaign(CrmCampaign $campaign): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::campaign($campaign->load(['segment', 'template']), $this->campaigns->progress($campaign))]);
    }

    /**
     * Draft a campaign
     *
     * `crm:manage`. A segment, an active MARKETING template, and optionally
     * when to send and an hourly limit of its own.
     */
    public function storeCampaign(CrmRequest $request): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::campaign($this->campaigns->saveCampaign(null, $request->campaign())->load(['segment', 'template']))], 201);
    }

    /**
     * Edit a draft campaign
     *
     * `crm:manage`. Only a draft (409 otherwise).
     */
    public function updateCampaign(CrmRequest $request, CrmCampaign $campaign): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::campaign($this->campaigns->saveCampaign($campaign, $request->campaign())->load(['segment', 'template']))]);
    }

    /**
     * Schedule or cancel a campaign
     *
     * `crm:manage`. `status`: `scheduled` (a draft goes out at `scheduled_at`,
     * or now), `draft` (back from scheduled), `cancelled`. Sending is done by
     * the scheduler, to customers with marketing consent only, within the
     * hourly limit; everyone else is logged as skipped.
     */
    public function campaignStatus(CrmRequest $request, CrmCampaign $campaign): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);
        $updated = $this->campaigns->setCampaignStatus($campaign, (string) $request->word('status'));

        return new JsonResponse(['data' => CrmPresenter::campaign($updated->load(['segment', 'template']), $this->campaigns->progress($updated))]);
    }

    /**
     * @param  list<CustomerAccount>  $members
     */
    private function memberList(array $members): JsonResponse
    {
        return new JsonResponse(['data' => array_map(fn (CustomerAccount $a): array => ['id' => $a->id, 'display_name' => $a->display_name, 'account_type' => $a->account_type, 'tags' => $a->tags], $members), 'meta' => ['total' => count($members)]]);
    }
}
