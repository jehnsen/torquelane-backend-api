<?php

declare(strict_types=1);

use App\Domain\Detailing\MonitorChannel;
use App\Events\DetailingBookingChanged;
use App\Models\Consent;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Notifications\DetailingBookingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The two doors into detailing that no user walks through: a branch's public
 * booking page (no session; rate-limited; captcha-ready) and the shop monitor
 * on a wall screen (a per-branch display token; read-only; revocable). And
 * the announcement both listen for: a booking change, broadcast after commit.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T08:30:00+08:00'));
    $this->world = World::build();
    $this->essential = $this->world->id('detailing:package:essential');
});

/** @param array<string, mixed> $overrides */
function publicBooking(array $overrides = [], string $slug = 'samahuzai-binan'): TestResponse
{
    return test()->postJson("/api/v1/public/detailing/{$slug}/bookings", $overrides + [
        'package_id' => test()->essential,
        'size_class' => 'medium',
        'date' => '2026-10-13',
        'start_time' => '13:00',
        'first_name' => 'Carla',
        'last_name' => 'Mendoza',
        'email' => 'Carla.Mendoza@example.com',
        'phone' => '0917 555 0142',
        'vehicle_make' => 'Honda',
        'vehicle_model' => 'Civic',
        'vehicle_year' => 2021,
        'plate_number' => 'nda 4521',
        'consent_service_records' => true,
    ]);
}

// ------------------------------------------------------------ public booking

it('shows a branch\'s public page: its look, its menu priced per size, and nothing internal', function () {
    $page = $this->getJson('/api/v1/public/detailing/samahuzai-binan')->assertOk()->json('data');

    expect($page['branch'])->toMatchArray(['slug' => 'samahuzai-binan', 'name' => 'Samahuzai'])
        ->and($page['branch']['theme_tokens'])->toMatchArray(['font-heading' => 'Cormorant Garamond', 'font-body' => 'DM Sans', 'brand' => '44 54% 54%', 'page' => '240 5% 4%'])
        ->and($page['booking'])->toMatchArray(['enabled' => true, 'notice' => null, 'first_date' => '2026-10-12', 'last_date' => '2027-01-10', 'closing_label' => '5:00 PM'])
        ->and(array_column($page['sizes'], 'size_class'))->toBe(['small', 'medium', 'large', 'xl'])
        ->and(array_column($page['packages'], 'name'))->toBe(['The Essential', 'The Executive', 'The Obsidian Elite', 'Paint Correction', 'Ceramic Coating', 'Interior Rescue'])
        ->and(array_column($page['packages'][0]['prices'], 'price_cents'))->toBe([130_000, 150_000, 180_000, 210_000])
        ->and($page['packages'][0]['duration']['interpreted'])->toBe('3 hrs')
        ->and($page['packages'][4]['duration']['interpreted'])->toBe('2 days of work (16 hrs)')
        // The bill of materials is the shop's business.
        ->and($page['packages'][0]['materials'])->toBeNull()
        ->and($page['captcha'])->toBe(['provider' => null, 'site_key' => null]);

    // A slug that is not a detailing branch is as missing as one that does not exist.
    $this->getJson('/api/v1/public/detailing/mekanikomor-binan')->assertNotFound();
    $this->getJson('/api/v1/public/detailing/nowhere')->assertNotFound();
});

it('offers the public only starts before closing that are still ahead, and says nothing of who else is booked', function () {
    $day = $this->getJson("/api/v1/public/detailing/samahuzai-binan/availability?package_id={$this->essential}&size_class=medium&date=2026-10-12")->assertOk()->json('data');
    $starts = collect($day['starts'])->keyBy('time');

    expect(array_keys($day['starts'][0]))->toBe(['time', 'label', 'available', 'reason', 'spans_days', 'ends'])
        ->and($starts->keys()->first())->toBe('08:00')
        ->and($starts->keys()->last())->toBe('16:30')
        // It is 8:30 AM: 8:00 and 8:30 have gone.
        ->and($starts['08:30'])->toMatchArray(['available' => false, 'reason' => 'past'])
        ->and($starts['11:00'])->toMatchArray(['available' => true, 'reason' => null, 'spans_days' => false])
        ->and($starts['11:00']['ends']['label'])->toBe('3:00 PM');
});

it('takes a booking request from the public page: pending, an account opened with consent, and an email', function () {
    Notification::fake();

    $made = publicBooking()->assertCreated()->json('data');
    expect($made['token'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($made['booking'])->toMatchArray([
            'reference' => 'BK-2026-0004',
            'status' => 'pending',
            'customer_name' => 'Carla Mendoza',
            'vehicle_label' => '2021 Honda Civic · NDA 4521',
            'package_name' => 'The Essential',
            'size_label' => 'Medium',
            'price_cents' => 150_000,
            'scheduled_date' => '2026-10-13',
            'start_label' => '1:00 PM',
        ])
        ->and($made['booking']['days'])->toBe([['date' => '2026-10-13', 'from_label' => '1:00 PM', 'to_label' => '4:00 PM']])
        // Nothing but the reference identifies anything.
        ->and(json_encode($made))->not->toMatch('/"(id|branch_id|customer_account_id)":/');

    $account = asSystem(fn () => CustomerAccount::query()->where('email', 'carla.mendoza@example.com')->sole());
    $consent = asSystem(fn () => Consent::query()->where('customer_account_id', $account->id)->sole());
    $booking = asSystem(fn () => DetailingBooking::query()->where('reference', 'BK-2026-0004')->sole());
    expect($account->account_type)->toBe('individual')
        ->and($account->source)->toBe('online_booking')
        ->and($consent->purpose->value)->toBe('service_records')
        ->and($consent->granted)->toBeTrue()
        ->and($consent->channel->value)->toBe('portal')
        ->and($booking->source)->toBe('public')
        ->and($booking->customer_account_id)->toBe($account->id)
        ->and($booking->created_by_name)->toBe('Online booking');

    Notification::assertSentTo(new AnonymousNotifiable, DetailingBookingNotification::class, function (DetailingBookingNotification $mail, array $channels, AnonymousNotifiable $to) use ($made): bool {
        $text = implode("\n", $mail->toMail($to)->introLines);

        return $to->routes['mail'] === 'carla.mendoza@example.com'
            && $mail->kind === DetailingBookingNotification::RECEIVED
            && str_contains($text, 'BK-2026-0004') && str_contains($text, 'It is not confirmed yet')
            && $mail->booking['url'] === "http://localhost:3000/book/samahuzai-binan/confirmation/{$made['token']}"
            && $mail->booking['total'] === '₱1,500.00';
    });

    // The confirmation link reads it back; a wrong token reads nothing.
    $page = $this->getJson("/api/v1/public/detailing/bookings/{$made['token']}")->assertOk()->json('data');
    expect($page['booking']['reference'])->toBe('BK-2026-0004')->and($page['branch']['name'])->toBe('Samahuzai');
    $this->getJson('/api/v1/public/detailing/bookings/'.str_repeat('0', 40))->assertNotFound();

    // The same address again books under the same account, and the answer looks the same.
    $again = publicBooking(['date' => '2026-10-16', 'email' => 'CARLA.MENDOZA@example.com'])->assertCreated()->json('data');
    expect(array_keys($again['booking']))->toBe(array_keys($made['booking']))
        ->and(asSystem(fn () => CustomerAccount::query()->where('email', 'carla.mendoza@example.com')->count()))->toBe(1);

    // Staff confirming it is what tells the customer.
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $this->postJson("/api/v1/detailing/bookings/{$booking->id}/status", ['status' => 'confirmed'])->assertOk();
    Notification::assertSentTo(new AnonymousNotifiable, DetailingBookingNotification::class, fn (DetailingBookingNotification $mail): bool => $mail->kind === DetailingBookingNotification::CONFIRMED && $mail->booking['reference'] === 'BK-2026-0004');
});

it('refuses what the page should not have offered, and needs the consent', function () {
    publicBooking(['consent_service_records' => false])->assertStatus(422)->assertJsonPath('error.details.fields.consent_service_records.0', fn ($message) => is_string($message));
    publicBooking(['date' => '2026-10-11'])->assertStatus(422)->assertJsonPath('error.details.fields.date.0', 'Pick a day from today to 2027-01-10.');
    publicBooking(['start_time' => '13:10'])->assertStatus(422)->assertJsonPath('error.details.fields.start_time.0', 'Pick one of the times offered.');
    publicBooking(['start_time' => '17:30'])->assertStatus(422);
    publicBooking(['package_id' => $this->world->id('detailing:package:essential'), 'size_class' => 'huge'])->assertStatus(422);

    // The coating holds both detailers all of the 14th.
    $this->travel(2)->minutes();
    publicBooking(['date' => '2026-10-14'])->assertStatus(409)->assertJsonPath('error.details.reason', 'full');
    expect(asSystem(fn () => CustomerAccount::query()->where('email', 'carla.mendoza@example.com')->count()))->toBe(0);
});

it('shows the notice and takes nothing while online booking is off', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $this->putJson('/api/v1/detailing/settings', ['public_booking_enabled' => false, 'public_booking_notice' => 'We are fully booked this month. Call 0964 886 3698.'])->assertOk();
    $this->app['auth']->forgetGuards();

    $page = $this->getJson('/api/v1/public/detailing/samahuzai-binan')->assertOk()->json('data');
    expect($page['booking'])->toMatchArray(['enabled' => false, 'notice' => 'We are fully booked this month. Call 0964 886 3698.'])
        ->and($page['packages'])->toBe([])
        ->and($page['branch']['name'])->toBe('Samahuzai');
    publicBooking()->assertStatus(409)->assertJsonPath('error.details.reason', 'public_booking_disabled')
        ->assertJsonPath('error.message', 'We are fully booked this month. Call 0964 886 3698.');
});

it('limits how fast one address can ask, and checks the captcha when one is configured', function () {
    foreach (range(1, 5) as $i) {
        publicBooking(['date' => '2026-10-13', 'start_time' => '25:00'])->assertStatus(422);
    }
    publicBooking()->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

    $this->travel(2)->minutes();
    config(['services.captcha.driver' => 'turnstile', 'services.captcha.site_key' => 'site-key', 'services.captcha.secret' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

    expect($this->getJson('/api/v1/public/detailing/samahuzai-binan')->json('data.captcha'))->toBe(['provider' => 'turnstile', 'site_key' => 'site-key']);
    publicBooking()->assertStatus(422)->assertJsonPath('error.details.fields.captcha_token.0', 'Please complete the check that you are not a robot.');
    publicBooking(['captcha_token' => 'wrong'])->assertStatus(422);
    publicBooking(['captcha_token' => 'right'])->assertCreated();
});

// ---------------------------------------------------- the monitor on a screen

it('lets a display token read its branch\'s monitor and nothing else, until it is revoked', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $issued = $this->postJson('/api/v1/detailing/display-tokens', ['name' => 'Lobby TV'])->assertCreated()->json('data');
    expect($issued['token'])->toStartWith('tld_')->and($issued['token_hint'])->toBe(substr($issued['token'], -4))->and($issued['is_active'])->toBeTrue();
    // The list never shows it again.
    expect($this->getJson('/api/v1/detailing/display-tokens')->assertOk()->json('data.0'))->not->toHaveKey('token')->toMatchArray(['name' => 'Lobby TV', 'last_used_at' => null]);
    $this->app['auth']->forgetGuards();

    $screen = ['X-Display-Token' => $issued['token']];
    $view = $this->getJson('/api/v1/display/detailing/monitor', $screen)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    expect($view['branch'])->toMatchArray(['name' => 'Samahuzai'])
        ->and($view['branch']['theme_tokens']['brand'])->toBe('44 54% 54%')
        ->and($view['display'])->toBe(['name' => 'Lobby TV'])
        ->and($view['monitor']['date'])->toBe('2026-10-12')
        ->and(array_column($view['monitor']['jobs'], 'reference'))->toBe(['BK-2026-0001'])
        ->and($view['realtime'])->toMatchArray(['enabled' => false, 'poll_seconds' => 20, 'channel' => 'private-detailing.branch.'.$this->world->id('samahuzai-binan')]);

    // The token is not a session: every staff route still needs one.
    $this->getJson('/api/v1/detailing/bookings', $screen)->assertUnauthorized();
    $this->getJson('/api/v1/display/detailing/monitor')->assertUnauthorized();
    $this->getJson('/api/v1/display/detailing/monitor', ['X-Display-Token' => 'tld_'.str_repeat('a', 48)])->assertUnauthorized();

    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    expect($this->getJson('/api/v1/detailing/display-tokens')->json('data.0.last_used_at'))->toBe('2026-10-12T00:30:00Z');
    $this->postJson("/api/v1/detailing/display-tokens/{$issued['id']}/revoke")->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.revoked_by_name', 'Liza Samonte');
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/display/detailing/monitor', $screen)->assertUnauthorized();
});

it('signs a socket onto its own branch\'s channel only', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'app-key', 'broadcasting.connections.reverb.secret' => 'app-secret',
        'broadcasting.client.key' => 'app-key', 'broadcasting.client.host' => 'ws.example.test', 'broadcasting.client.port' => 443, 'broadcasting.client.scheme' => 'https']);
    $detailing = $this->world->id('samahuzai-binan');
    $repair = $this->world->id('mekanikomor-binan');
    $channel = MonitorChannel::wire($detailing);
    $signature = 'app-key:'.hash_hmac('sha256', "1234.5678:{$channel}", 'app-secret');

    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $token = $this->postJson('/api/v1/detailing/display-tokens', ['name' => 'Bay TV'])->json('data.token');
    expect($this->getJson('/api/v1/detailing/realtime')->assertOk()->json('data'))
        ->toMatchArray(['enabled' => true, 'key' => 'app-key', 'host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https', 'channel' => $channel, 'event' => '.booking.changed']);
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertOk()->assertExactJson(['auth' => $signature]);
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.1'])->assertStatus(422);
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => 'not-a-socket', 'channel_name' => $channel])->assertStatus(422);

    // Staff pinned elsewhere cannot listen to this branch.
    Sanctum::actingAs($this->world->user('cashier@mekanikomore.ph'));
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertNotFound();
    $this->app['auth']->forgetGuards();

    // A display: its own branch's channel, no other.
    $screen = ['X-Display-Token' => $token];
    $this->postJson('/api/v1/display/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel], $screen)->assertOk()->assertExactJson(['auth' => $signature]);
    $this->postJson('/api/v1/display/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => MonitorChannel::wire($repair)], $screen)->assertStatus(422);
    $this->postJson('/api/v1/display/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertUnauthorized();
});

it('announces a booking change on the branch\'s private channel, after commit, with no customer data', function () {
    Event::fake([DetailingBookingChanged::class]);
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $body = ['customer_account_id' => $this->world->id('walk-in'), 'package_id' => $this->essential, 'size_class' => 'medium', 'date' => '2026-10-16', 'start_time' => '16:00'];

    // A refused booking rolls back and announces nothing.
    $this->postJson('/api/v1/detailing/bookings', ['date' => '2026-10-14'] + $body)->assertStatus(409);
    Event::assertNotDispatched(DetailingBookingChanged::class);

    $id = $this->postJson('/api/v1/detailing/bookings', $body)->assertCreated()->json('data.id');
    Event::assertDispatched(DetailingBookingChanged::class, function (DetailingBookingChanged $event) use ($id): bool {
        return $event->bookingId === $id
            && $event->change === 'booked'
            // 4:00 PM + 3 hours rolls into the next morning: both days hear it.
            && $event->dates === ['2026-10-16', '2026-10-17']
            && $event->broadcastOn()[0]->name === 'private-detailing.branch.'.$this->world->id('samahuzai-binan')
            && $event->broadcastAs() === 'booking.changed'
            && $event->broadcastWith() === ['booking_id' => $id, 'change' => 'booked', 'dates' => ['2026-10-16', '2026-10-17']];
    });

    $this->postJson("/api/v1/detailing/bookings/{$id}/status", ['status' => 'confirmed'])->assertOk();
    Event::assertDispatched(DetailingBookingChanged::class, fn (DetailingBookingChanged $event): bool => $event->bookingId === $id && $event->change === 'status');
});
