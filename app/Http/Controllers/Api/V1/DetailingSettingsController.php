<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Detailing\DetailingBranches;
use App\Actions\Detailing\DetailingJournal;
use App\Actions\Detailing\DetailingQueries;
use App\Actions\Detailing\ManageDisplayTokens;
use App\Actions\Detailing\ManageRecurringSchedules;
use App\Actions\Detailing\SaveDetailingSettings;
use App\Domain\Detailing\SlotGrid;
use App\Http\Requests\Detailing\BlockSlotsRequest;
use App\Http\Requests\Detailing\BranchRequest;
use App\Http\Requests\Detailing\DisplayTokenRequest;
use App\Http\Requests\Detailing\RecurringScheduleRequest;
use App\Http\Requests\Detailing\SaveSettingsRequest;
use App\Http\Resources\DetailingSettingResource;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\DetailingBlockedSlot;
use App\Models\DetailingDisplayToken;
use App\Models\DetailingRecurringSchedule;
use App\Models\DetailingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A detailing branch's settings (staff): its pool and closing time, whether
 * the public may book online, the slots it has blocked, the tokens of its
 * wall screens, and its customers' standing weekly slots.
 */
final class DetailingSettingsController
{
    public function __construct(
        private readonly DetailingBranches $branches,
        private readonly DetailingJournal $journal,
        private readonly SaveDetailingSettings $settings,
        private readonly DetailingQueries $queries,
    ) {}

    /**
     * Show the settings
     *
     * `detailing:view`. A branch that has saved none yet shows the defaults
     * (`saved: false`).
     */
    public function show(BranchRequest $request): DetailingSettingResource
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewIn', [DetailingSetting::class, $branch->id]);

        return $this->resource($branch);
    }

    /**
     * Save the settings
     *
     * `detailing:manage`. Only what is sent changes. The pool cannot drop
     * below what existing bookings already hold in one slot, and a booking
     * takes no more than the pool by default (422).
     */
    public function update(SaveSettingsRequest $request): DetailingSettingResource
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingSetting::class, $branch->id]);
        $this->settings->handle($branch, $request->settings());

        return $this->resource($branch);
    }

    /**
     * Block slots
     *
     * `detailing:manage`. Closes grid slots of a day to new bookings;
     * bookings already there stay.
     */
    public function block(BlockSlotsRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingSetting::class, $branch->id]);

        return new JsonResponse(['data' => array_map(self::blocked(...), $this->settings->block($branch, $request->day(), $request->slots(), $request->label()))], 201);
    }

    /**
     * Unblock a slot
     */
    public function unblock(DetailingBlockedSlot $blockedSlot): Response
    {
        Gate::authorize('manageIn', [DetailingSetting::class, $blockedSlot->branch_id]);
        $this->settings->unblock($blockedSlot);

        return response()->noContent();
    }

    /**
     * List display tokens
     *
     * `detailing:manage`. The tokens of the branch's wall screens, revoked
     * ones included. A token is never shown again after it is made.
     */
    public function tokens(BranchRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingSetting::class, $branch->id]);

        return new JsonResponse(['data' => array_values(DetailingDisplayToken::query()->where('branch_id', $branch->id)->orderByDesc('id')->get()->map(self::token(...))->all())]);
    }

    /**
     * Issue a display token
     *
     * `detailing:manage`. `token` is in the response this once: it reads this
     * branch's shop monitor and nothing else.
     */
    public function issueToken(DisplayTokenRequest $request, ManageDisplayTokens $tokens): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingSetting::class, $branch->id]);
        $issued = $tokens->issue($branch, $request->tokenName());

        return new JsonResponse(['data' => self::token($issued['token']) + ['token' => $issued['plain']]], 201);
    }

    /**
     * Revoke a display token
     */
    public function revokeToken(DetailingDisplayToken $displayToken, ManageDisplayTokens $tokens): JsonResponse
    {
        Gate::authorize('manageIn', [DetailingSetting::class, $displayToken->branch_id]);

        return new JsonResponse(['data' => self::token($tokens->revoke($displayToken))]);
    }

    /**
     * List standing weekly slots
     *
     * `detailing:view`. Of the branch, or of one customer with `customer_account_id`.
     */
    public function recurring(BranchRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewIn', [DetailingSetting::class, $branch->id]);
        $accountId = $request->query('customer_account_id');

        $schedules = DetailingRecurringSchedule::query()->where('branch_id', $branch->id)
            ->when(is_string($accountId) && $accountId !== '', fn ($query) => $query->where('customer_account_id', strtolower((string) $accountId)))
            ->with(['package', 'customerAccount', 'vehicle'])
            ->orderBy('day_of_week')->orderBy('start_minutes')->orderBy('id')->get();

        return new JsonResponse(['data' => array_values($schedules->map(self::schedule(...))->all())]);
    }

    /**
     * Add a standing weekly slot
     *
     * `detailing:manage`. A preference the shop books from; it books nothing itself.
     */
    public function storeRecurring(RecurringScheduleRequest $request, ManageRecurringSchedules $schedules): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingSetting::class, $branch->id]);
        $accountId = $request->accountId() ?? throw ValidationException::withMessages(['customer_account_id' => 'Say whose slot it is.']);
        $account = CustomerAccount::query()->findOrFail($accountId);

        return new JsonResponse(['data' => self::schedule($schedules->save($branch, $account, null, $request->schedule())->load(['package', 'customerAccount', 'vehicle']))], 201);
    }

    /**
     * Edit a standing weekly slot
     */
    public function updateRecurring(RecurringScheduleRequest $request, DetailingRecurringSchedule $recurringSchedule, ManageRecurringSchedules $schedules): JsonResponse
    {
        Gate::authorize('manageIn', [DetailingSetting::class, $recurringSchedule->branch_id]);
        $saved = $schedules->save(
            $this->branches->resolve($recurringSchedule->branch_id),
            CustomerAccount::query()->findOrFail($recurringSchedule->customer_account_id),
            $recurringSchedule,
            $request->schedule(),
        );

        return new JsonResponse(['data' => self::schedule($saved->load(['package', 'customerAccount', 'vehicle']))]);
    }

    /**
     * Remove a standing weekly slot
     */
    public function destroyRecurring(DetailingRecurringSchedule $recurringSchedule, ManageRecurringSchedules $schedules): Response
    {
        Gate::authorize('manageIn', [DetailingSetting::class, $recurringSchedule->branch_id]);
        $schedules->delete($recurringSchedule);

        return response()->noContent();
    }

    private function resource(Branch $branch): DetailingSettingResource
    {
        return new DetailingSettingResource(
            $this->journal->settings($branch->organization_id, $branch->id),
            count($this->queries->detailers($branch)),
            $this->settings->peakHeads($branch),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function blocked(DetailingBlockedSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'branch_id' => $slot->branch_id,
            'date' => $slot->date->toDateString(),
            'slot' => $slot->slot,
            'time' => SlotGrid::time($slot->slot),
            'slot_label' => SlotGrid::label($slot->slot),
            'label' => $slot->label,
            'created_by_name' => $slot->created_by_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function token(DetailingDisplayToken $token): array
    {
        return [
            'id' => $token->id,
            'branch_id' => $token->branch_id,
            'name' => $token->name,
            'token_hint' => $token->token_hint,
            'is_active' => $token->revoked_at === null,
            'last_used_at' => $token->last_used_at?->utc()->toIso8601ZuluString(),
            'revoked_at' => $token->revoked_at?->utc()->toIso8601ZuluString(),
            'revoked_by_name' => $token->revoked_by_name,
            'created_by_name' => $token->created_by_name,
            'created_at' => $token->created_at->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schedule(DetailingRecurringSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'branch_id' => $schedule->branch_id,
            'customer_account_id' => $schedule->customer_account_id,
            'customer_name' => $schedule->customerAccount->display_name,
            'vehicle_id' => $schedule->vehicle_id,
            'vehicle_label' => $schedule->vehicle === null ? null : trim(implode(' ', array_filter([$schedule->vehicle->make, $schedule->vehicle->model, $schedule->vehicle->plate_number]))),
            'package_id' => $schedule->package_id,
            'package_name' => $schedule->package->name,
            'size_class' => $schedule->size_class->value,
            'day_of_week' => $schedule->day_of_week,
            'day_label' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$schedule->day_of_week],
            'start_minutes' => $schedule->start_minutes,
            'start_time' => SlotGrid::time($schedule->start_minutes),
            'start_label' => SlotGrid::label($schedule->start_minutes),
            'is_active' => $schedule->is_active,
            'notes' => $schedule->notes,
        ];
    }
}
