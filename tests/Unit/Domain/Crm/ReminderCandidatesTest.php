<?php

declare(strict_types=1);

use App\Domain\Crm\QuietHours;
use App\Domain\Crm\Reminders\AccountActivity;
use App\Domain\Crm\Reminders\CandidateBuilder;
use App\Domain\Crm\Reminders\DetailingVisit;
use App\Domain\Crm\Reminders\ExpiringDocument;
use App\Domain\Crm\Reminders\GateDecision;
use App\Domain\Crm\Reminders\ReminderCandidate;
use App\Domain\Crm\Reminders\ReminderGate;
use App\Domain\Crm\Reminders\ReminderKind;
use App\Domain\Crm\Reminders\ServiceDue;
use App\Domain\Shared\Calendar;

/*
 * Service reminders: what is worth sending (CandidateBuilder) and whether it
 * may go (ReminderGate). Both pure.
 */

function reminderDay(): DateTimeImmutable
{
    return Calendar::parseDate('2026-10-12');
}

/**
 * @param  list<ReminderKind>|null  $enabled
 * @return list<ReminderCandidate>
 */
function candidates(array $due = [], array $visits = [], array $documents = [], array $activity = [], ?array $enabled = null, int $lead = 30, int $lapsed = 180): array
{
    return CandidateBuilder::build($enabled ?? ReminderKind::cases(), $due, $visits, $documents, $activity, $lead, $lapsed, reminderDay());
}

function summary(array $candidates): array
{
    return array_map(fn (ReminderCandidate $c): array => [$c->kind->value, $c->accountId, $c->dedupeKey], $candidates);
}

it('makes one maintenance reminder per vehicle, however many of its items are due', function () {
    $made = candidates(due: [
        new ServiceDue('acct-a', 'veh-1', 'NCR 1234', 'Engine oil change', false, 9),
        new ServiceDue('acct-a', 'veh-1', 'NCR 1234', 'Brake inspection', true, -4),
        new ServiceDue('acct-a', 'veh-1', 'NCR 1234', 'Air filter', false, 2),
        new ServiceDue('acct-b', 'veh-2', 'ABC 987', 'Tyre rotation', false, 12),
    ]);

    expect(summary($made))->toBe([
        ['pms_due', 'acct-a', 'pms_due:veh-1'],
        ['pms_due', 'acct-b', 'pms_due:veh-2'],
    ])
        // The overdue item leads; then the soonest.
        ->and($made[0]->values)->toBe(['vehicle' => 'NCR 1234', 'service' => 'Brake inspection', 'services' => 'Brake inspection, Air filter, Engine oil change', 'status' => 'overdue', 'due_in' => '4 days ago'])
        ->and($made[1]->values['status'])->toBe('due soon')
        ->and($made[1]->values['due_in'])->toBe('in 12 days')
        ->and($made[0]->subjectType)->toBe('vehicle');
});

it('reminds of a detailing package when its repeat interval has passed and nothing is booked', function () {
    $visit = fn (string $package, string $on, int $every, bool $booked = false, string $vehicle = 'veh-1'): DetailingVisit => new DetailingVisit('acct-a', 'br-1', $package, ucfirst($package), $vehicle, 'Fortuner NCR 1234', $on, $every, $booked);

    $made = candidates(visits: [
        $visit('wash', '2026-09-28', 14),          // due today
        $visit('coating', '2026-03-01', 180),      // 45 days past due: the one to mention
        $visit('wax', '2026-10-01', 30),           // not yet
        $visit('wash', '2026-09-01', 14, vehicle: 'veh-2'),
        $visit('wash', '2026-08-01', 14, true, 'veh-3'), // already booked
        $visit('once', '2026-01-01', 0, vehicle: 'veh-4'),
    ]);

    expect(summary($made))->toBe([
        ['detailing_repeat', 'acct-a', 'detailing_repeat:acct-a:veh-1'],
        ['detailing_repeat', 'acct-a', 'detailing_repeat:acct-a:veh-2'],
    ])
        ->and($made[0]->values)->toBe(['vehicle' => 'Fortuner NCR 1234', 'service' => 'Coating', 'last_visit' => '1 March 2026'])
        ->and($made[0]->branchId)->toBe('br-1');
});

it('reminds of a document inside the lead time and for thirty days after it lapsed', function () {
    $doc = fn (string $id, string $expires): ExpiringDocument => new ExpiringDocument('acct-a', $id, "Doc {$id}", 'Registration (OR/CR)', 'NCR 1234', $expires);

    $made = candidates(documents: [
        $doc('d1', '2026-11-11'), // 30 days: in
        $doc('d2', '2026-11-12'), // 31 days: not yet
        $doc('d3', '2026-10-12'), // today
        $doc('d4', '2026-09-12'), // 30 days ago: still in
        $doc('d5', '2026-09-11'), // 31 days ago: dropped
    ]);

    expect(array_column(summary($made), 2))->toBe(['document_expiry:d1', 'document_expiry:d3', 'document_expiry:d4'])
        ->and($made[0]->values)->toMatchArray(['status' => 'expiring', 'due_in' => 'in 30 days', 'expires_on' => '11 November 2026'])
        ->and($made[1]->values['due_in'])->toBe('today')
        ->and($made[2]->values)->toMatchArray(['status' => 'expired', 'due_in' => '30 days ago']);
});

it('calls a customer lapsed only when they were served, long ago, with nothing ahead and no other reminder', function () {
    $made = candidates(
        due: [new ServiceDue('acct-busy', 'veh-9', 'XYZ 111', 'Oil', false, 3)],
        activity: [
            new AccountActivity('acct-gone', '2026-04-15', false),   // exactly 180 days
            new AccountActivity('acct-recent', '2026-04-16', false), // 179
            new AccountActivity('acct-never', null, false),
            new AccountActivity('acct-booked', '2025-01-01', true),
            new AccountActivity('acct-busy', '2025-01-01', false),
        ],
    );

    expect(summary($made))->toBe([
        ['pms_due', 'acct-busy', 'pms_due:veh-9'],
        ['lapsed_customer', 'acct-gone', 'lapsed:acct-gone'],
    ])
        ->and($made[1]->values)->toBe(['last_visit' => '15 April 2026', 'days_since' => '180']);
});

it('builds only the kinds that are switched on', function () {
    $due = [new ServiceDue('a', 'v', 'P', 'Oil', false, 1)];
    $activity = [new AccountActivity('b', '2020-01-01', false)];

    expect(candidates(due: $due, activity: $activity, enabled: []))->toBe([])
        ->and(summary(candidates(due: $due, activity: $activity, enabled: [ReminderKind::LapsedCustomer])))->toBe([['lapsed_customer', 'b', 'lapsed:b']]);
});

it('lets a message through only with an address, consent, no opt-out and no recent copy', function () {
    $quiet = new QuietHours(1260, 480);
    $noon = new DateTimeImmutable('2026-10-12T12:00:00+08:00');
    $decide = fn (bool $address = true, bool $needed = true, bool $consented = true, bool $suppressed = false, ?string $last = null, ?DateTimeImmutable $now = null): GateDecision => ReminderGate::decide($address, $needed, $consented, $suppressed, $last === null ? null : new DateTimeImmutable($last), 14, $quiet, $now ?? $noon);

    expect($decide()->send)->toBeTrue()
        ->and($decide()->sendAfter)->toEqual($noon)
        ->and($decide(address: false)->skipReason)->toBe('no_address')
        ->and($decide(consented: false)->skipReason)->toBe('no_consent')
        // A purpose that needs no consent goes without one.
        ->and($decide(needed: false, consented: false)->send)->toBeTrue()
        ->and($decide(suppressed: true)->skipReason)->toBe('opted_out')
        // No address is reported before no consent, and consent before the opt-out.
        ->and($decide(address: false, consented: false, suppressed: true)->skipReason)->toBe('no_address')
        ->and($decide(consented: false, suppressed: true)->skipReason)->toBe('no_consent')
        // The cooldown counts Manila calendar days: 13 days ago is too soon, 14 is not.
        ->and($decide(last: '2026-09-29T23:30:00+08:00')->skipReason)->toBe('recently_sent')
        ->and($decide(last: '2026-09-28T23:30:00+08:00')->send)->toBeTrue();
});

it('holds a message through quiet hours instead of dropping it', function () {
    $quiet = new QuietHours(1260, 480); // 21:00 to 08:00, Manila
    $at = fn (string $time): DateTimeImmutable => new DateTimeImmutable("2026-10-12T{$time}:00+08:00");

    expect($quiet->isQuiet($at('20:59')))->toBeFalse()
        ->and($quiet->isQuiet($at('21:00')))->toBeTrue()
        ->and($quiet->isQuiet($at('07:59')))->toBeTrue()
        ->and($quiet->isQuiet($at('08:00')))->toBeFalse()
        ->and($quiet->nextAllowed($at('22:15'))->format('c'))->toBe('2026-10-13T08:00:00+08:00')
        ->and($quiet->nextAllowed($at('03:00'))->format('c'))->toBe('2026-10-12T08:00:00+08:00')
        ->and($quiet->nextAllowed($at('09:00')))->toEqual($at('09:00'))
        // Whatever zone the clock is read in, the window is Manila's.
        ->and($quiet->isQuiet(new DateTimeImmutable('2026-10-12T14:00:00Z')))->toBeTrue()
        // A window inside one day, and none at all.
        ->and((new QuietHours(720, 780))->isQuiet($at('12:30')))->toBeTrue()
        ->and((new QuietHours(720, 780))->isQuiet($at('13:00')))->toBeFalse()
        ->and((new QuietHours(0, 0))->isQuiet($at('02:00')))->toBeFalse();

    $held = ReminderGate::decide(true, true, true, false, null, 14, $quiet, $at('23:00'));
    expect($held->send)->toBeTrue()->and($held->sendAfter?->format('c'))->toBe('2026-10-13T08:00:00+08:00');
});
