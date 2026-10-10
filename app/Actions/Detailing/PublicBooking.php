<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\SizeClass;
use App\Domain\Detailing\SlotGrid;
use App\Domain\Modules\Module;
use App\Domain\Shared\Calendar;
use App\Exceptions\AccountSuspendedException;
use App\Exceptions\ConflictException;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Consent;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\DetailingPackage;
use App\Models\DetailingSetting;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Notifications\DetailingBookingNotification;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking from a branch's public page (`/book/{branchSlug}`): nobody is
 * signed in, so everything here runs in the named system context
 * `public booking` and names its organization and branch explicitly.
 *
 * A branch is reachable by its slug when it is active, its organization is
 * active, and the `detailing` module is on for both. A slug is unique within
 * an organization, not across them: one that matches more than one such
 * branch resolves to none.
 *
 * A booking made here lands `pending`, as in the source, for a customer
 * account found by its email address within the organization or opened on the
 * spot as an individual (with the consent to keep service records the form
 * asks for). The response is the same whether the email was known or not.
 */
final class PublicBooking
{
    /** How far ahead the public may book. */
    public const int MAX_DAYS_AHEAD = 90;

    public const string SOURCE = 'online_booking';

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly DetailingJournal $journal,
        private readonly DetailingQueries $queries,
        private readonly TakeBooking $bookings,
        private readonly BookingMail $mail,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(Branch, DetailingSetting): T  $callback
     * @return T
     */
    public function at(string $slug, Closure $callback): mixed
    {
        return $this->tenancy->system('public booking', function () use ($slug, $callback): mixed {
            $branch = $this->branch($slug);

            return $callback($branch, $this->journal->settings($branch->organization_id, $branch->id));
        });
    }

    /**
     * The package a public request names: on this branch's menu and still offered.
     */
    public function package(Branch $branch, string $packageId): DetailingPackage
    {
        return DetailingPackage::query()->where('branch_id', $branch->id)->where('is_active', true)->with(['prices', 'category'])->findOrFail($packageId);
    }

    /**
     * @param  array{
     *     package_id: string, size_class: string, date: string, start_minutes: int,
     *     first_name: string, last_name: string, email: string, phone: string,
     *     vehicle_make: string, vehicle_model: string, vehicle_year?: int|null, plate_number?: string|null,
     *     notes?: string|null, ip?: string|null,
     * }  $data
     * @return array{token: string, booking: array<string, mixed>}
     */
    public function book(string $slug, array $data): array
    {
        return $this->at($slug, function (Branch $branch, DetailingSetting $settings) use ($data): array {
            if (! $settings->public_booking_enabled) {
                throw new ConflictException($settings->public_booking_notice ?? 'Online booking is not available right now. Please call the shop.', ['reason' => 'public_booking_disabled']);
            }
            $today = Calendar::toDate(CarbonImmutable::now());
            $last = Calendar::toDate(Calendar::addDays(Calendar::parseDate($today), self::MAX_DAYS_AHEAD));
            if ($data['date'] < $today || $data['date'] > $last) {
                throw ValidationException::withMessages(['date' => sprintf('Pick a day from today to %s.', $last)]);
            }
            if (! SlotGrid::isSlot($data['start_minutes']) || $data['start_minutes'] >= $settings->closing_minutes) {
                throw ValidationException::withMessages(['start_time' => 'Pick one of the times offered.']);
            }
            $package = $this->package($branch, $data['package_id']);
            $token = bin2hex(random_bytes(20));

            return DB::transaction(function () use ($branch, $package, $data, $token): array {
                $account = $this->account($branch, $data);
                $vehicle = trim(implode(' ', array_filter([
                    isset($data['vehicle_year']) ? (string) $data['vehicle_year'] : null,
                    trim($data['vehicle_make']),
                    trim($data['vehicle_model']),
                ])));
                $plate = trim($data['plate_number'] ?? '');

                $booking = $this->bookings->handle($branch, $account, null, $package, SizeClass::from($data['size_class']), [
                    'date' => $data['date'],
                    'start_minutes' => $data['start_minutes'],
                    'notes' => $data['notes'] ?? null,
                    'customer_name' => trim($data['first_name'].' '.$data['last_name']),
                    'customer_email' => mb_strtolower(trim($data['email'])),
                    'customer_phone' => trim($data['phone']),
                    'vehicle_label' => $plate === '' ? $vehicle : "{$vehicle} · ".mb_strtoupper($plate),
                    'status' => BookingStatus::Pending,
                    'source' => DetailingBooking::SOURCE_PUBLIC,
                    'public_token_hash' => hash('sha256', $token),
                ]);
                $this->mail->send($booking, DetailingBookingNotification::RECEIVED, $token);

                return ['token' => $token, 'booking' => self::confirmation($booking->load('slots'))];
            });
        });
    }

    /**
     * A booking by the token in its confirmation link, with the branch it is
     * at. An unknown token is as missing as any other record.
     *
     * @return array{branch: array<string, mixed>, booking: array<string, mixed>}
     */
    public function byToken(string $token): array
    {
        return $this->tenancy->system('public booking', function () use ($token): array {
            $booking = DetailingBooking::query()->where('public_token_hash', hash('sha256', $token))->with(DetailingQueries::WITH)->first()
                ?? throw (new ModelNotFoundException)->setModel(DetailingBooking::class);

            return ['branch' => self::brand(Branch::query()->findOrFail($booking->branch_id)), 'booking' => self::confirmation($booking)];
        });
    }

    /**
     * A branch as its public page shows it: its name and look, how to reach it.
     *
     * @return array<string, mixed>
     */
    public static function brand(Branch $branch): array
    {
        return [
            'slug' => $branch->slug,
            'name' => $branch->brand_name ?? $branch->name,
            'address' => $branch->address,
            'contact_phone' => $branch->contact_phone,
            'contact_email' => $branch->contact_email,
            'logo_url' => $branch->logo_url,
            'brand_color' => $branch->brand_color,
            'theme_tokens' => $branch->theme_tokens,
        ];
    }

    /**
     * What the public page may know about a booking: its own times and
     * package. No staff, no ids but the reference.
     *
     * @return array<string, mixed>
     */
    public static function confirmation(DetailingBooking $booking): array
    {
        $first = $booking->slots->first();

        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'customer_name' => $booking->customer_name,
            'vehicle_label' => $booking->vehicle_label,
            'package_name' => $booking->package_name,
            'size_class' => $booking->size_class->value,
            'size_label' => $booking->size_class->label(),
            'price_cents' => $booking->package_price_cents,
            'duration_text' => $booking->duration_text,
            'scheduled_date' => $booking->scheduled_date->toDateString(),
            'start_label' => SlotGrid::label(SlotGrid::nearest($booking->start_minutes)),
            'first_day' => $first?->date->toDateString(),
            'days' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'from_label' => $day['slots'][0]['label'],
                'to_label' => SlotGrid::label($day['slots'][count($day['slots']) - 1]['slot'] + SlotGrid::SLOT_MINUTES),
            ], DetailingQueries::days($booking)),
            'finish' => DetailingQueries::finish($booking),
            'created_at' => $booking->created_at->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * Starts the public may pick on a day: free or not, and when the job
     * would end. Nothing about who else is booked.
     *
     * @return array<string, mixed>
     */
    public function availability(Branch $branch, DetailingSetting $settings, DetailingPackage $package, SizeClass $size, string $date): array
    {
        $full = $this->queries->availability($branch, $package, $size, $date);
        $today = Calendar::toDate(CarbonImmutable::now());
        $nowMinutes = (int) Calendar::local(CarbonImmutable::now())->format('G') * 60 + (int) Calendar::local(CarbonImmutable::now())->format('i');

        $starts = [];
        foreach ($full['starts'] as $start) {
            $minutes = $start['start_minutes'];
            if (! is_int($minutes) || $minutes >= $settings->closing_minutes) {
                continue;
            }
            $past = $date < $today || ($date === $today && $minutes <= $nowMinutes);
            $starts[] = [
                'time' => $start['time'],
                'label' => $start['label'],
                'available' => $start['available'] === true && ! $past,
                'reason' => $past ? 'past' : $start['reason'],
                'spans_days' => $start['spans_days'],
                'ends' => $start['ends'],
            ];
        }

        return ['date' => $date, 'package_id' => $package->id, 'size_class' => $size->value, 'duration_text' => $full['duration_text'], 'starts' => $starts];
    }

    private function branch(string $slug): Branch
    {
        $matches = [];
        foreach (Branch::query()->where('slug', mb_strtolower($slug))->where('status', 'active')->get() as $branch) {
            $organization = Organization::query()->find($branch->organization_id);
            $active = $organization instanceof Organization && ! $organization->isSuspended()
                && OrganizationModule::query()->where('organization_id', $branch->organization_id)->where('module', Module::Detailing)->where('enabled', true)->exists()
                && BranchModule::query()->where('branch_id', $branch->id)->where('module', Module::Detailing)->where('enabled', true)->exists();
            if ($active) {
                $matches[] = $branch;
            }
        }

        return count($matches) === 1 ? $matches[0] : throw (new ModelNotFoundException)->setModel(Branch::class);
    }

    /**
     * The customer's account: the organization's account with that email, else a new individual one.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone: string, ip?: string|null}  $data
     */
    private function account(Branch $branch, array $data): CustomerAccount
    {
        $email = mb_strtolower(trim($data['email']));
        // One opening at a time per address, so two tabs do not open two accounts.
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["public-booking-account:{$branch->organization_id}:{$email}"]);

        $account = CustomerAccount::query()->where('organization_id', $branch->organization_id)->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();
        if ($account instanceof CustomerAccount) {
            if ($account->isSuspended()) {
                throw new AccountSuspendedException('This booking cannot be taken online. Please call the shop.');
            }

            return $account;
        }

        $account = new CustomerAccount;
        $account->forceFill([
            'organization_id' => $branch->organization_id,
            'account_type' => CustomerAccount::INDIVIDUAL,
            'display_name' => trim($data['first_name'].' '.$data['last_name']),
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'email' => $email,
            'mobile' => trim($data['phone']),
            'source' => self::SOURCE,
            'status' => 'active',
        ])->save();
        $this->audit->record($account, 'created', null, AuditTrail::snapshot($account));

        // The form's own tick: the customer's decision, made online by the customer.
        $consent = new Consent;
        $consent->forceFill([
            'organization_id' => $branch->organization_id,
            'customer_account_id' => $account->id,
            'purpose' => ConsentPurpose::ServiceRecords,
            'granted' => true,
            'channel' => ConsentChannel::Portal,
            'captured_at' => CarbonImmutable::now('UTC'),
            'evidence' => 'Ticked on the public booking form of '.($branch->brand_name ?? $branch->name).(isset($data['ip']) ? " (from {$data['ip']})" : ''),
        ])->save();
        $this->audit->record($consent, 'granted', null, AuditTrail::snapshot($consent));

        return $account;
    }
}
