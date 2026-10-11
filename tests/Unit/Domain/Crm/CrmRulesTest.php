<?php

declare(strict_types=1);

use App\Domain\Crm\AccountProfile;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\Crm\LeadStage;
use App\Domain\Crm\Loyalty;
use App\Domain\Crm\MembershipBenefits;
use App\Domain\Crm\MessageChannel;
use App\Domain\Crm\MessagePurpose;
use App\Domain\Crm\Nps;
use App\Domain\Crm\Prepaid;
use App\Domain\Crm\SegmentFilter;
use App\Domain\Crm\TemplateRenderer;
use App\Domain\Crm\Timeline;
use App\Domain\Crm\TimelineEntry;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\Postings\Postings;
use App\Domain\Ledger\Postings\PrepaidRedemptionFacts;
use App\Domain\Ledger\Postings\PrepaidSaleFacts;
use App\Domain\Ledger\RuleKey;
use App\Domain\Receivables\PaymentMethod;
use App\Domain\Shared\Calendar;

/* The CRM's small pure rules: templates, the timeline, lead stages, prepaid money, benefits, points, NPS, segments. */

it('fills a template and leaves no placeholder behind', function () {
    $body = 'Hi {{customer_name}}, your {{ Vehicle }} is {{status}}. Book: {{link}} {{unknown}}';

    expect(TemplateRenderer::render($body, ['customer_name' => 'Ana', 'vehicle' => 'NCR 1234', 'status' => 'due soon', 'link' => 'https://x/r/abc']))
        ->toBe('Hi Ana, your NCR 1234 is due soon. Book: https://x/r/abc ')
        ->and(TemplateRenderer::placeholders($body))->toBe(['customer_name', 'vehicle', 'status', 'link', 'unknown'])
        // A value is inserted as it is: no second pass.
        ->and(TemplateRenderer::render('{{a}}', ['a' => '{{b}}', 'b' => 'x']))->toBe('{{b}}');
});

it('knows which consent each purpose needs, and what an address is', function () {
    expect(MessagePurpose::ServiceReminder->consent())->toBe(ConsentPurpose::ServiceReminders)
        ->and(MessagePurpose::Feedback->consent())->toBe(ConsentPurpose::ServiceReminders)
        ->and(MessagePurpose::Marketing->consent())->toBe(ConsentPurpose::Marketing)
        ->and(MessagePurpose::Transactional->consent())->toBeNull()
        ->and(MessageChannel::Email->normalize('  Ana@Example.PH '))->toBe('ana@example.ph')
        ->and(MessageChannel::Sms->normalize('+63 917-555 0101'))->toBe('639175550101')
        ->and(MessageChannel::Email->accepts('not-an-email'))->toBeFalse()
        ->and(MessageChannel::Email->accepts(null))->toBeFalse()
        ->and(MessageChannel::Sms->accepts('0917 555 0101'))->toBeTrue()
        ->and(MessageChannel::Sms->accepts('555'))->toBeFalse();
});

it('merges a timeline newest first, with a fixed order for one instant', function () {
    $at = fn (string $time): DateTimeImmutable => new DateTimeImmutable($time);
    $merged = Timeline::merge(
        [new TimelineEntry($at('2026-10-01T09:00:00Z'), 'work_order', 'w1', 'WO-1'), new TimelineEntry($at('2026-10-05T09:00:00Z'), 'work_order', 'w2', 'WO-2')],
        [new TimelineEntry($at('2026-10-05T09:00:00Z'), 'payment', 'p1', 'PAY-1'), new TimelineEntry($at('2026-10-05T17:00:00+08:00'), 'note', 'n1', 'Note')],
        [],
    );

    expect(array_map(fn (TimelineEntry $e): string => $e->id, $merged))->toBe(['n1', 'p1', 'w2', 'w1'])
        ->and(Timeline::merge())->toBe([]);
});

it('moves a lead between open stages, loses and re-opens it, and wins it only by conversion', function () {
    expect(LeadStage::New->canMoveTo(LeadStage::Negotiating))->toBeTrue()
        ->and(LeadStage::Negotiating->canMoveTo(LeadStage::Contacted))->toBeTrue()
        ->and(LeadStage::QuoteSent->canMoveTo(LeadStage::Lost))->toBeTrue()
        ->and(LeadStage::Lost->canMoveTo(LeadStage::New))->toBeTrue()
        ->and(LeadStage::New->canMoveTo(LeadStage::New))->toBeFalse()
        ->and(LeadStage::Negotiating->canMoveTo(LeadStage::Won))->toBeFalse()
        ->and(LeadStage::Won->canMoveTo(LeadStage::Lost))->toBeFalse()
        ->and(array_column(LeadStage::board(), 'value'))->toBe(['new', 'contacted', 'site_visit', 'quote_sent', 'negotiating', 'won', 'lost'])
        ->and(LeadStage::SiteVisit->isOpen())->toBeTrue()
        ->and(LeadStage::Won->isOpen())->toBeFalse();
});

it('earns a prepaid package credit by credit, to exactly its price', function (int $price, int $credits) {
    $earned = 0;
    for ($used = 0; $used < $credits; $used++) {
        $earned += Prepaid::redemptionCents($price, $credits, $used, 1);
        expect(Prepaid::unearnedCents($price, $credits, $used + 1))->toBe($price - $earned);
    }

    expect($earned)->toBe($price)->and(Prepaid::unearnedCents($price, $credits, 0))->toBe($price);
})->with([
    'divides evenly' => [500_000, 5],
    'a centavo left over' => [100_000, 3],
    'ten washes for ₱3,333.33' => [333_333, 10],
    'one credit' => [99_999, 1],
]);

it('shares a prepaid price the same way however the credits are taken', function () {
    // ₱1,000 over 3: 333.33, 333.33, then 333.34.
    expect([Prepaid::redemptionCents(100_000, 3, 0, 1), Prepaid::redemptionCents(100_000, 3, 1, 1), Prepaid::redemptionCents(100_000, 3, 2, 1)])->toBe([33_333, 33_333, 33_334])
        ->and(Prepaid::redemptionCents(100_000, 3, 0, 2))->toBe(66_666)
        ->and(Prepaid::redemptionCents(100_000, 3, 1, 2))->toBe(66_667)
        ->and(fn () => Prepaid::redemptionCents(100_000, 3, 3, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Prepaid::redemptionCents(100_000, 3, 0, 0))->toThrow(InvalidArgumentException::class);
});

it('posts a prepaid sale to Unearned Revenue and a redemption out of it, balanced', function () {
    $sale = Postings::postingsFor(new PrepaidSaleFacts('pkg-1', 'PP-2026-0001', 'br-1', 'acct-1', 'Ana Reyes', '2026-10-12', 100_000, PaymentMethod::Gcash));
    $redeemed = Postings::postingsFor(new PrepaidRedemptionFacts('red-1', 'PP-2026-0001', 'br-1', 'acct-1', '2026-10-20', 33_333, 'Wash 1 of 3'));
    $sides = fn ($draft): array => array_map(fn (PostingLine $l): array => [$l->account->rule?->value, $l->debitCents, $l->creditCents], $draft->lines);

    expect($sale->event)->toBe(LedgerEvent::PrepaidSold)
        ->and($sides($sale))->toBe([[RuleKey::ClearingGcash->value, 100_000, 0], [RuleKey::UnearnedRevenue->value, 0, 100_000]])
        ->and($sale->paymentMethod)->toBe('gcash')
        ->and($sale->sourceType())->toBe('prepaid_package')
        ->and($redeemed->event)->toBe(LedgerEvent::PrepaidRedeemed)
        ->and($sides($redeemed))->toBe([[RuleKey::UnearnedRevenue->value, 33_333, 0], [RuleKey::SalesDetailing->value, 0, 33_333]])
        ->and($redeemed->sourceType())->toBe('prepaid_redemption')
        ->and($redeemed->totalCents)->toBe(33_333);
});

it('takes a tier\'s percentage off a line and makes its free add-ons free', function () {
    $vip = new MembershipBenefits('VIP', '10.00', true, ['addon-coffee']);

    expect($vip->discountCents(250_000))->toBe(25_000)
        ->and($vip->discountCents(33_335))->toBe(3_334)   // 3,333.5 rounds up
        ->and($vip->discountCents(15_000, 'addon-coffee'))->toBe(15_000)
        ->and($vip->discountCents(15_000, 'addon-wax'))->toBe(1_500)
        ->and((new MembershipBenefits('Basic', '0', false, []))->discountCents(99_999))->toBe(0);
});

it('counts whole loyalty points only', function () {
    expect(Loyalty::pointsFor(259_900, 10_000))->toBe(25)
        ->and(Loyalty::pointsFor(9_999, 10_000))->toBe(0)
        ->and(Loyalty::pointsFor(-5_000, 10_000))->toBe(0)
        ->and(Loyalty::pointsFor(50_000, 0))->toBe(0);
});

it('scores NPS as promoters less detractors', function () {
    expect(Nps::summarize([10, 9, 9, 8, 7, 6, 3, 10]))->toBe(['responses' => 8, 'promoters' => 4, 'passives' => 2, 'detractors' => 2, 'nps' => 25])
        ->and(Nps::summarize([]))->toBe(['responses' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0, 'nps' => null])
        ->and(Nps::summarize([0, 6]))->toMatchArray(['nps' => -100])
        ->and([Nps::band(9), Nps::band(8), Nps::band(7), Nps::band(6)])->toBe(['promoter', 'passive', 'passive', 'detractor']);
});

it('matches a segment on every condition given, and any value of a list', function () {
    $today = Calendar::parseDate('2026-10-12');
    $ana = new AccountProfile('ana', 'individual', ['VIP', 'referral'], 1_250_000, '2026-09-20', ['toyota', 'honda'], ['large'], 'tier-vip', ['br-detailing']);
    $never = new AccountProfile('new', 'company', [], 0, null, [], [], null, []);
    $matches = fn (array $filters, AccountProfile $account): bool => SegmentFilter::fromArray($filters)->matches($account, $today);

    expect($matches([], $ana))->toBeTrue()
        ->and($matches([], $never))->toBeTrue()
        ->and($matches(['min_spend_cents' => 1_000_000], $ana))->toBeTrue()
        ->and($matches(['min_spend_cents' => 1_250_001], $ana))->toBeFalse()
        ->and($matches(['max_spend_cents' => 500_000], $ana))->toBeFalse()
        // 22 days since the last visit.
        ->and($matches(['visited_within_days' => 22], $ana))->toBeTrue()
        ->and($matches(['visited_within_days' => 21], $ana))->toBeFalse()
        ->and($matches(['not_visited_for_days' => 22], $ana))->toBeTrue()
        ->and($matches(['not_visited_for_days' => 23], $ana))->toBeFalse()
        // Never served: not "within", always "not for".
        ->and($matches(['visited_within_days' => 9999], $never))->toBeFalse()
        ->and($matches(['not_visited_for_days' => 9999], $never))->toBeTrue()
        ->and($matches(['makes' => ['Honda', 'Ford']], $ana))->toBeTrue()
        ->and($matches(['makes' => ['Ford']], $ana))->toBeFalse()
        ->and($matches(['size_classes' => ['small', 'large']], $ana))->toBeTrue()
        ->and($matches(['tier_ids' => ['tier-vip']], $ana))->toBeTrue()
        ->and($matches(['tier_ids' => ['none']], $ana))->toBeFalse()
        ->and($matches(['tier_ids' => ['none']], $never))->toBeTrue()
        ->and($matches(['branch_ids' => ['br-repair']], $ana))->toBeFalse()
        ->and($matches(['account_type' => 'company'], $ana))->toBeFalse()
        ->and($matches(['tags' => ['vip']], $ana))->toBeTrue()
        ->and($matches(['tags' => ['dealer']], $ana))->toBeFalse()
        // Every condition must hold.
        ->and($matches(['makes' => ['toyota'], 'min_spend_cents' => 2_000_000], $ana))->toBeFalse();

    expect(SegmentFilter::fromArray(['makes' => ['Toyota', ' ', 'Toyota'], 'min_spend_cents' => '5000', 'junk' => 1, 'tier_ids' => []])->toArray())
        ->toBe(['min_spend_cents' => 5000, 'makes' => ['toyota']]);
});
