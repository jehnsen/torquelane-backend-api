<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

use App\Domain\Shared\Calendar;
use DateTimeImmutable;

/**
 * Builds the day's reminder candidates from what is on record. Pure: it is
 * handed facts and returns candidates, and knows nothing of consent, quiet
 * hours or what was sent before (`ReminderGate` does).
 *
 *  - **Maintenance due**: ONE candidate per vehicle with any item in the
 *    maintenance engine's due-soon or overdue band, naming its items most
 *    urgent first. A vehicle with three items due is one reminder, not three.
 *  - **Detailing due again**: a package with a repeat interval, last done on a
 *    vehicle that many days ago or more, with nothing booked ahead. One per
 *    vehicle: the package longest overdue.
 *  - **Document expiring**: within the rule's lead days, and up to 30 days
 *    after it lapsed.
 *  - **Lapsed customer**: served before, not for `lapsedAfterDays`, nothing
 *    booked ahead, and no other reminder going to them in this run.
 *
 * Only the kinds given as enabled are built. Order: by kind, then account,
 * then subject, so a run is repeatable.
 */
final class CandidateBuilder
{
    /** A lapsed document is still worth a reminder for this long. */
    public const int EXPIRED_GRACE_DAYS = 30;

    /**
     * @param  list<ReminderKind>  $enabled
     * @param  list<ServiceDue>  $serviceDue
     * @param  list<DetailingVisit>  $visits
     * @param  list<ExpiringDocument>  $documents
     * @param  list<AccountActivity>  $activity
     * @return list<ReminderCandidate>
     */
    public static function build(
        array $enabled,
        array $serviceDue,
        array $visits,
        array $documents,
        array $activity,
        int $documentLeadDays,
        int $lapsedAfterDays,
        DateTimeImmutable $today,
    ): array {
        $candidates = [];
        if (in_array(ReminderKind::PmsDue, $enabled, true)) {
            array_push($candidates, ...self::maintenance($serviceDue));
        }
        if (in_array(ReminderKind::DocumentExpiry, $enabled, true)) {
            array_push($candidates, ...self::documents($documents, $documentLeadDays, $today));
        }
        if (in_array(ReminderKind::DetailingRepeat, $enabled, true)) {
            array_push($candidates, ...self::detailing($visits, $today));
        }
        if (in_array(ReminderKind::LapsedCustomer, $enabled, true)) {
            $busy = array_flip(array_map(fn (ReminderCandidate $c): string => $c->accountId, $candidates));
            array_push($candidates, ...self::lapsed($activity, $lapsedAfterDays, $today, $busy));
        }

        return $candidates;
    }

    /**
     * @param  list<ServiceDue>  $due
     * @return list<ReminderCandidate>
     */
    private static function maintenance(array $due): array
    {
        $byVehicle = [];
        foreach ($due as $item) {
            $byVehicle[$item->vehicleId][] = $item;
        }
        ksort($byVehicle);

        $candidates = [];
        foreach ($byVehicle as $vehicleId => $items) {
            usort($items, fn (ServiceDue $a, ServiceDue $b): int => [$b->overdue, $a->daysRemaining, $a->taskName] <=> [$a->overdue, $b->daysRemaining, $b->taskName]);
            $first = $items[0];
            $overdue = $first->overdue;
            $candidates[] = new ReminderCandidate(
                ReminderKind::PmsDue,
                $first->accountId,
                'vehicle',
                (string) $vehicleId,
                "pms_due:{$vehicleId}",
                null,
                [
                    'vehicle' => $first->plateNumber,
                    'service' => $first->taskName,
                    'services' => implode(', ', array_map(fn (ServiceDue $i): string => $i->taskName, $items)),
                    'status' => $overdue ? 'overdue' : 'due soon',
                    'due_in' => self::dayDelta($first->daysRemaining),
                ],
            );
        }
        usort($candidates, fn (ReminderCandidate $a, ReminderCandidate $b): int => [$a->accountId, $a->subjectId] <=> [$b->accountId, $b->subjectId]);

        return $candidates;
    }

    /**
     * @param  list<ExpiringDocument>  $documents
     * @return list<ReminderCandidate>
     */
    private static function documents(array $documents, int $leadDays, DateTimeImmutable $today): array
    {
        $candidates = [];
        foreach ($documents as $document) {
            $days = Calendar::differenceInCalendarDays(Calendar::parseDate($document->expiresOn), $today);
            if ($days > $leadDays || $days < -self::EXPIRED_GRACE_DAYS) {
                continue;
            }
            $candidates[] = new ReminderCandidate(
                ReminderKind::DocumentExpiry,
                $document->accountId,
                'document',
                $document->documentId,
                "document_expiry:{$document->documentId}",
                null,
                [
                    'document' => $document->name,
                    'document_kind' => $document->kindLabel,
                    'vehicle' => $document->plateNumber ?? '',
                    'expires_on' => Calendar::parseDate($document->expiresOn)->format('j F Y'),
                    'status' => $days < 0 ? 'expired' : 'expiring',
                    'due_in' => self::dayDelta($days),
                ],
            );
        }
        usort($candidates, fn (ReminderCandidate $a, ReminderCandidate $b): int => [$a->accountId, $a->subjectId] <=> [$b->accountId, $b->subjectId]);

        return $candidates;
    }

    /**
     * @param  list<DetailingVisit>  $visits
     * @return list<ReminderCandidate>
     */
    private static function detailing(array $visits, DateTimeImmutable $today): array
    {
        /** @var array<string, array{visit: DetailingVisit, overdue: int}> $worst */
        $worst = [];
        foreach ($visits as $visit) {
            if ($visit->hasUpcomingBooking || $visit->repeatIntervalDays < 1) {
                continue;
            }
            $dueOn = Calendar::addDays(Calendar::parseDate($visit->completedOn), $visit->repeatIntervalDays);
            $overdue = Calendar::differenceInCalendarDays($today, $dueOn);
            if ($overdue < 0) {
                continue;
            }
            $key = "{$visit->accountId}:{$visit->vehicleKey}";
            if (! isset($worst[$key]) || $overdue > $worst[$key]['overdue']) {
                $worst[$key] = ['visit' => $visit, 'overdue' => $overdue];
            }
        }
        ksort($worst);

        $candidates = [];
        foreach ($worst as $key => ['visit' => $visit]) {
            $candidates[] = new ReminderCandidate(
                ReminderKind::DetailingRepeat,
                $visit->accountId,
                'detailing_package',
                $visit->packageId,
                "detailing_repeat:{$key}",
                $visit->branchId,
                [
                    'vehicle' => $visit->vehicleLabel,
                    'service' => $visit->packageName,
                    'last_visit' => Calendar::parseDate($visit->completedOn)->format('j F Y'),
                ],
            );
        }

        return $candidates;
    }

    /**
     * @param  list<AccountActivity>  $activity
     * @param  array<string, int>  $busy  accounts already getting a reminder
     * @return list<ReminderCandidate>
     */
    private static function lapsed(array $activity, int $lapsedAfterDays, DateTimeImmutable $today, array $busy): array
    {
        $candidates = [];
        foreach ($activity as $account) {
            if ($account->lastVisitOn === null || $account->hasUpcomingWork || isset($busy[$account->accountId])) {
                continue;
            }
            $days = Calendar::differenceInCalendarDays($today, Calendar::parseDate($account->lastVisitOn));
            if ($days < $lapsedAfterDays) {
                continue;
            }
            $candidates[] = new ReminderCandidate(
                ReminderKind::LapsedCustomer,
                $account->accountId,
                'account',
                $account->accountId,
                "lapsed:{$account->accountId}",
                null,
                ['last_visit' => Calendar::parseDate($account->lastVisitOn)->format('j F Y'), 'days_since' => (string) $days],
            );
        }
        usort($candidates, fn (ReminderCandidate $a, ReminderCandidate $b): int => $a->accountId <=> $b->accountId);

        return $candidates;
    }

    private static function dayDelta(int $days): string
    {
        return match (true) {
            $days === 0 => 'today',
            $days > 0 => sprintf('in %d %s', $days, $days === 1 ? 'day' : 'days'),
            default => sprintf('%d %s ago', -$days, $days === -1 ? 'day' : 'days'),
        };
    }
}
