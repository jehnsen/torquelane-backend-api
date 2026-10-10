<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Detailing\DetailingQueries;
use App\Actions\Detailing\PublicBooking;
use App\Captcha\CaptchaVerifier;
use App\Domain\Detailing\SizeClass;
use App\Domain\Detailing\SlotGrid;
use App\Domain\Shared\Calendar;
use App\Http\Requests\Detailing\BranchRequest;
use App\Http\Requests\Detailing\PublicBookingRequest;
use App\Http\Resources\DetailingPackageResource;
use App\Models\Branch;
use App\Models\DetailingPackage;
use App\Models\DetailingSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A detailing branch's public booking page (`/book/{branchSlug}` in the
 * frontend). Nobody is signed in: these routes carry no tenant data but what
 * the branch publishes about itself (its name and look, its menu, which
 * starts are free), are rate-limited, and take one kind of write: a booking
 * request, which lands pending for staff to confirm.
 */
final class PublicBookingController
{
    public function __construct(
        private readonly PublicBooking $public,
        private readonly CaptchaVerifier $captcha,
    ) {}

    /**
     * A branch's public booking page
     *
     * Its name and look (`theme_tokens`), whether online booking is on (and
     * the notice shown when it is not), the vehicle sizes, and its menu:
     * every active package with a price per size. `captcha.provider` says
     * which challenge to draw, if any.
     */
    public function show(Request $request, string $branchSlug, DetailingQueries $queries): JsonResponse
    {
        return new JsonResponse(['data' => $this->public->at($branchSlug, function (Branch $branch, DetailingSetting $settings) use ($request, $queries): array {
            $menu = $settings->public_booking_enabled ? $queries->catalogue($branch, false) : null;
            $today = Calendar::toDate(CarbonImmutable::now());

            return [
                'branch' => PublicBooking::brand($branch),
                'booking' => [
                    'enabled' => $settings->public_booking_enabled,
                    'notice' => $settings->public_booking_enabled ? null : ($settings->public_booking_notice ?? 'Online booking is not available right now. Please call the shop.'),
                    'first_date' => $today,
                    'last_date' => Calendar::toDate(Calendar::addDays(Calendar::parseDate($today), PublicBooking::MAX_DAYS_AHEAD)),
                    'closing_label' => SlotGrid::label($settings->closing_minutes),
                ],
                'sizes' => array_map(fn (SizeClass $size): array => ['size_class' => $size->value, 'label' => $size->label()], SizeClass::cases()),
                'packages' => $menu === null ? [] : array_values($menu['packages']->map(fn (DetailingPackage $package): array => (new DetailingPackageResource($package, withMaterials: false))->resolve($request))->all()),
                'captcha' => $this->captcha->client(),
            ];
        })]);
    }

    /**
     * Free starts, for the public
     *
     * `package_id`, `size_class`, `date`: the times of that day a booking may
     * start (before closing, not in the past), each free or not, and when the
     * job would end. Nothing about other bookings.
     */
    public function availability(BranchRequest $request, string $branchSlug): JsonResponse
    {
        $packageId = $request->packageId();
        $size = $request->size();
        if ($packageId === null || $size === null) {
            throw ValidationException::withMessages(['package_id' => 'Say which package and which vehicle size.']);
        }

        return new JsonResponse(['data' => $this->public->at($branchSlug, function (Branch $branch, DetailingSetting $settings) use ($request, $packageId, $size): array {
            if (! $settings->public_booking_enabled) {
                return ['date' => $request->day(), 'package_id' => $packageId, 'size_class' => $size->value, 'duration_text' => '', 'starts' => []];
            }

            return $this->public->availability($branch, $settings, $this->public->package($branch, $packageId), $size, $request->day());
        })]);
    }

    /**
     * Request a booking
     *
     * Lands `pending`; the shop confirms it, and the customer is emailed
     * both times. 409 when the slot has just filled (`details.reason` =
     * `full`), is blocked, or online booking is off
     * (`public_booking_disabled`). The `token` opens the confirmation page.
     */
    public function store(PublicBookingRequest $request, string $branchSlug): JsonResponse
    {
        if (! $this->captcha->passes($request->captchaToken(), $request->ip())) {
            throw ValidationException::withMessages(['captcha_token' => 'Please complete the check that you are not a robot.']);
        }

        return new JsonResponse(['data' => $this->public->book($branchSlug, $request->booking())], 201);
    }

    /**
     * A booking's confirmation page
     *
     * By the token of its confirmation link: its reference, status, package
     * and times. An unknown token is 404.
     */
    public function confirmation(string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->public->byToken($token)], headers: ['Cache-Control' => 'no-store']);
    }
}
