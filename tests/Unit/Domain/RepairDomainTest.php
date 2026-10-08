<?php

declare(strict_types=1);

use App\Domain\Access\Capability;
use App\Domain\Access\Role;
use App\Domain\Approvals\Approvals;
use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Approvals\ApproverBand;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Billing\BillableLine;
use App\Domain\Billing\Billing;
use App\Domain\WorkOrders\LifecycleStage;
use App\Domain\WorkOrders\PartsSource;
use App\Domain\WorkOrders\WorkOrderMachine;
use App\Domain\WorkOrders\WorkOrderReference;
use App\Domain\WorkOrders\WorkOrderStatus;

/*
 * The repair rules beyond what the golden fixtures pin: the API's own
 * additions (branch override, branch manager, VAT registration) and the
 * exact-centavo behaviour the float fixtures cannot express.
 */

function billableLine(string $qty, int $rate, string $hours = '0', int $labourRate = 0, LineApprovalStatus $status = LineApprovalStatus::Pending): BillableLine
{
    return Billing::recalc(new BillableLine($qty, $rate, $hours, $labourRate, 0, 0, $status));
}

it('folds organization → branch → account, an unset field inheriting and never reading as zero', function () {
    $organization = ApprovalSettings::defaults();

    $effective = ApprovalSettings::effective(
        $organization,
        ['sla_hours' => 8, 'vat_rate_pct' => null],
        ['auto_approve_under_cents' => 0, 'ops_approval_under_cents' => null, 'vat_rate_pct' => '0'],
    );

    expect($effective->slaHours)->toBe(8)                           // branch override
        ->and($effective->autoApproveUnderCents)->toBe(0)            // account override: a real zero
        ->and($effective->opsApprovalUnderCents)->toBe(5_000_000)    // null inherits
        ->and($effective->vatRatePct)->toBe('12')                    // VAT is not an account override
        ->and($effective->defaultLabourRateCents)->toBe(65_000);
});

it('bills 0% VAT at a branch that is not VAT-registered', function () {
    $settings = ApprovalSettings::effective(ApprovalSettings::defaults(), null, null, isVatRegistered: false);
    $totals = Billing::totals([billableLine('1', 100_000)], $settings->vatRatePct, 0);

    expect($settings->vatRatePct)->toBe('0')
        ->and($totals->taxTotalCents)->toBe(0)
        ->and($totals->grandTotalCents)->toBe(100_000);
});

it('refuses an unknown settings key rather than letting a typo inherit', function () {
    ApprovalSettings::defaults()->overriddenBy(['sla_hour' => 2]);
})->throws(InvalidArgumentException::class);

it('prices a line exactly and rounds each stored amount once, half-up', function () {
    // 3 × ₱0.335 cannot occur (rates are whole centavos); 1.5 h × ₱650.33 can.
    $priced = billableLine('1.5', 65_033, '0.333', 65_000);

    expect($priced->partCostCents)->toBe(97_550)        // 97,549.5 → 97,550
        ->and($priced->labourCostCents)->toBe(21_645)    // 21,645 exactly
        ->and(Billing::lineCents($priced))->toBe(119_195);
});

it('taxes the pre-tax subtotal plus the misc fee, rounding once per total', function () {
    $totals = Billing::totals([billableLine('1', 3_333), billableLine('1', 3_333), billableLine('1', 3_334)], '12', 4_999);

    // (10,000 + 4,999) × 12% = 1,799.88 → 1,800.
    expect($totals->subTotalCents)->toBe(10_000)
        ->and($totals->miscTotalCents)->toBe(4_999)
        ->and($totals->taxTotalCents)->toBe(1_800)
        ->and($totals->grandTotalCents)->toBe(16_799);
});

it('reconstructs rates for a legacy line without a backfill guess', function () {
    expect(Billing::withRates(10_000, 97_500, null, null, null, null))->toBe([
        'quantity' => '1',
        'unit_part_rate_cents' => 10_000,
        'labour_hours' => '1',
        'labour_rate_cents' => 97_500,
    ])->and(Billing::withRates(10_000, 0, '3', null, null, null)['unit_part_rate_cents'])->toBe(3_333);
});

it('runs the bands on the pre-tax amount, strictly under auto, inclusive of the ops ceiling', function () {
    $settings = ApprovalSettings::defaults();

    expect(Approvals::requiredApprover(499_999, $settings))->toBe(ApproverBand::Auto)
        ->and(Approvals::requiredApprover(500_000, $settings))->toBe(ApproverBand::Operations)
        ->and(Approvals::requiredApprover(5_000_000, $settings))->toBe(ApproverBand::Operations)
        ->and(Approvals::requiredApprover(5_000_001, $settings))->toBe(ApproverBand::FleetManager);
});

it('gives a branch manager unlimited approval authority, like the provider admin', function () {
    $settings = ApprovalSettings::defaults();

    expect(Approvals::canApprove(Role::BranchManager, 100_000_000, $settings))->toBeTrue()
        ->and(Approvals::canApprove(Role::Operations, 5_000_001, $settings))->toBeFalse()
        ->and(Approvals::canApprove(Role::ServiceAdvisor, 1, $settings))->toBeFalse()
        ->and(Approvals::canApprove(Role::Cashier, 1, $settings))->toBeFalse();
});

it('flags variance strictly past the threshold, exactly', function () {
    expect(Approvals::varianceExceeds(1_000_000, 1_150_000, '15'))->toBeFalse()
        ->and(Approvals::varianceExceeds(1_000_000, 1_150_001, '15'))->toBeTrue()
        ->and(Approvals::varianceExceeds(333, 382, '15'))->toBeFalse()   // limit 382.95: 382 within, 383 over
        ->and(Approvals::varianceExceeds(333, 383, '15'))->toBeTrue()
        ->and(Approvals::varianceExceeds(10_000, 10_051, '0.5'))->toBeTrue();
});

it('derives the order status from its lines, deferred counting as not approved', function () {
    $a = LineApprovalStatus::Approved;
    $d = LineApprovalStatus::Declined;
    $f = LineApprovalStatus::Deferred;

    expect(Approvals::deriveOrderStatus([$a, $a]))->toBe(WorkOrderStatus::Approved)
        ->and(Approvals::deriveOrderStatus([$d, $f]))->toBe(WorkOrderStatus::Declined)
        ->and(Approvals::deriveOrderStatus([$a, $f]))->toBe(WorkOrderStatus::PartiallyApproved)
        ->and(Approvals::deriveOrderStatus([$a, LineApprovalStatus::Pending]))->toBe(WorkOrderStatus::PendingApproval);
});

it('sums the STORED line costs for approval, not a re-derivation', function () {
    $stored = new BillableLine('2', 100, '0', 0, 999, 0, LineApprovalStatus::Approved, PartsSource::OwnStock);

    expect(Approvals::approvedValue([$stored]))->toBe(999);
});

it('keeps declined and cancelled distinct, and closed split on collection', function () {
    expect(WorkOrderMachine::canTransition(WorkOrderStatus::Declined, WorkOrderStatus::Draft))->toBeTrue()
        ->and(WorkOrderMachine::canTransition(WorkOrderStatus::Cancelled, WorkOrderStatus::Draft))->toBeFalse()
        ->and(WorkOrderMachine::lifecycleStage(WorkOrderStatus::Closed, false))->toBe(LifecycleStage::ReadyForBilling)
        ->and(WorkOrderMachine::lifecycleStage(WorkOrderStatus::Closed, true))->toBe(LifecycleStage::Completed)
        ->and(WorkOrderMachine::checkTransition(WorkOrderStatus::Draft, 1, WorkOrderStatus::PendingApproval)->capability)->toBe(Capability::WorkOrderUpdate)
        ->and(WorkOrderMachine::checkTransition(WorkOrderStatus::Closed, 1, WorkOrderStatus::Cancelled)->reason)->toBe('A closed work order cannot become cancelled.');
});

it('shows an unnumbered draft for what it is', function () {
    expect(WorkOrderReference::display(WorkOrderReference::DRAFT))->toBe('Draft — not yet numbered')
        ->and(WorkOrderMachine::isInHouse(''))->toBeTrue()
        ->and(WorkOrderMachine::assignOnApproval('', 'branch-1'))->toBe(['assigned_branch_id' => 'branch-1', 'vendor' => '']);
});
