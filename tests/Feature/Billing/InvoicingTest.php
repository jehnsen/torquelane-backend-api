<?php

declare(strict_types=1);

use App\Actions\Billing\ManageInvoices;
use App\Documents\BillingPdf;
use App\Domain\Invoicing\Invoicing;
use App\Events\InvoiceIssued;
use App\Events\InvoiceVoided;
use App\Events\PaymentReceived;
use App\Listeners\PublishBillingEvent;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceWorkOrder;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Order-to-cash (Phase 7). Seed (Demo\BillingSeed, today = 2026-10-08):
 * INV-2026-0001 Actimed, 3 jobs, issued 2026-07-25, part-paid by
 * PAY-2026-0001; INV-2026-0002 Northwind, issued 2026-08-19; INV-2026-0003
 * Actimed, issued 2026-09-18; a Sagrada draft. Every other closed job is in
 * the billing queue. Both demo branches are VAT-registered, prices exclusive
 * of VAT (12% on top). Actimed pays on 30-day terms.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

function billAs(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/**
 * Closed jobs of an account that are neither settled nor on an invoice, oldest first.
 *
 * @return list<string>
 */
function readyJobs(string $accountKey, int $count = 1): array
{
    return asSystem(fn (): array => array_values(WorkOrder::query()
        ->where('customer_account_id', test()->world->id($accountKey))
        ->where('status', 'closed')
        ->whereNull('collected_at')
        ->whereNotIn('id', InvoiceWorkOrder::query()->select('work_order_id')->whereNull('released_at'))
        ->orderBy('completed_on')->orderBy('id')
        ->limit($count)
        ->pluck('id')
        ->all()));
}

/**
 * @param  list<string>  $jobs
 * @return TestResponse<JsonResponse>
 */
function invoiceDraftFor(array $jobs): TestResponse
{
    return test()->postJson('/api/v1/invoices', ['work_order_ids' => $jobs]);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function issueInvoice(string $id, array $body = [], ?string $key = null): TestResponse
{
    return test()->postJson("/api/v1/invoices/{$id}/issue", $body, ['Idempotency-Key' => $key ?? 'issue-'.$id.'-'.bin2hex(random_bytes(4))]);
}

it('takes a job from draft through approval and work to an invoice, paid, with its PDFs', function () {
    Event::fake([InvoiceIssued::class, InvoiceVoided::class, PaymentReceived::class]);

    // Raised and sent: inside Actimed's auto-approval band, so it opens approved.
    billAs('advisor@mekanikomore.ph');
    $job = $this->postJson('/api/v1/work-orders', [
        'vehicle_id' => $this->world->id('veh-001'),
        'title' => 'Wiper blades',
        'type' => 'corrective',
        'lines' => [['description' => 'Wiper blades', 'quantity' => 1, 'unit_part_rate_cents' => 65000, 'labour_hours' => 0.25, 'labour_rate_cents' => 65000, 'urgency' => 'optional']],
    ])->assertCreated()->assertJsonMissingPath('warnings')->json('data.id');
    $this->postJson("/api/v1/work-orders/{$job}/send")->assertOk()->assertJsonPath('data.status', 'approved');

    // The work.
    $this->postJson("/api/v1/work-orders/{$job}/schedule", ['scheduled_for' => '2026-10-08', 'scheduled_time' => '13:00', 'bay_id' => $this->world->id('bay:bay-1')])->assertOk();
    billAs('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$job}/start")->assertOk();
    $this->postJson("/api/v1/work-orders/{$job}/complete", ['findings' => 'Blades torn; replaced.', 'odometer_at_service' => 45650])->assertOk();
    $closed = $this->postJson("/api/v1/work-orders/{$job}/close")
        ->assertOk()
        ->assertJsonPath('data.lifecycle_stage', 'ready_for_billing')
        ->assertJsonPath('data.invoice', null)
        ->json('data');

    // In the billing queue.
    billAs('advisor@mekanikomore.ph');
    $queue = $this->getJson('/api/v1/billing/queue?customer_account_id='.$this->world->id('fc-actimed').'&per_page=100')->assertOk();
    expect($queue->json('data.*.id'))->toContain($job);

    // Invoiced: a draft, unnumbered, billing the stored approved costs.
    $draft = invoiceDraftFor([$job])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.number', null)
        ->assertJsonPath('data.customer_name', 'Actimed')
        ->assertJsonPath('data.lines.0.description', 'Wiper blades — parts')
        ->assertJsonPath('data.lines.0.line_total_cents', 65000)
        ->assertJsonPath('data.lines.1.description', 'Wiper blades — labour')
        ->assertJsonPath('data.lines.1.quantity', '0.25')
        ->assertJsonPath('data.lines.1.line_total_cents', 16250)
        ->assertJsonPath('data.can_issue', true)
        ->json('data');
    // What the customer approved is exactly what is billed.
    expect($draft['totals']['total_due_cents'])->toBe($closed['approved_totals']['grand_total_cents'])
        ->and($draft['totals']['vat_amount_cents'])->toBe($closed['approved_totals']['tax_total_cents']);
    expect($this->getJson('/api/v1/billing/queue?per_page=100')->json('data.*.id'))->not->toContain($job);

    // Issued: numbered from the invoice series, due on the account's terms.
    $issued = issueInvoice($draft['id'])
        ->assertOk()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.number', 'INV-2026-0004')
        ->assertJsonPath('data.issue_date', '2026-10-08')
        ->assertJsonPath('data.due_date', '2026-11-07')
        ->assertJsonPath('data.payment_terms_days', 30)
        ->assertJsonPath('data.issued_by_name', 'Divina Lacson')
        ->assertJsonPath('data.seller.vat_registered', true)
        ->assertJsonPath('data.non_vat_notice', null)
        ->assertJsonPath('data.balance_cents', $draft['totals']['total_due_cents'])
        ->json('data');
    $this->getJson("/api/v1/work-orders/{$job}")
        ->assertJsonPath('data.lifecycle_stage', 'invoiced')
        ->assertJsonPath('data.invoice.number', 'INV-2026-0004');
    Event::assertDispatched(InvoiceIssued::class, fn (InvoiceIssued $e): bool => $e->documentId === $issued['id'] && $e->number === 'INV-2026-0004' && $e->name() === 'invoice.issued');

    // Paid at the counter.
    billAs('cashier@mekanikomore.ph');
    $payment = $this->postJson('/api/v1/payments', [
        'customer_account_id' => $this->world->id('fc-actimed'),
        'method' => 'gcash',
        'reference_no' => 'GC-7781',
        'amount_cents' => $issued['totals']['total_due_cents'],
        'allocations' => [['invoice_id' => $issued['id'], 'amount_cents' => $issued['totals']['total_due_cents']]],
    ], ['Idempotency-Key' => 'pay-wipers'])
        ->assertCreated()
        ->assertJsonPath('data.number', 'PAY-2026-0002')
        ->assertJsonPath('data.unallocated_cents', 0)
        ->assertJsonPath('data.received_by_name', 'Paolo Reyes')
        ->json('data');
    Event::assertDispatched(PaymentReceived::class, fn (PaymentReceived $e): bool => $e->number === 'PAY-2026-0002');

    $this->getJson("/api/v1/invoices/{$issued['id']}")
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.balance_cents', 0)
        ->assertJsonPath('data.payments.0.number', 'PAY-2026-0002')
        ->assertJsonPath('data.can_record_payment', false);
    // Completed: settled (collected_at is stamped when the invoice is paid); the vehicle is still at the shop.
    $order = $this->getJson("/api/v1/work-orders/{$job}")
        ->assertJsonPath('data.lifecycle_stage', 'completed')
        ->assertJsonPath('data.released_at', null)
        ->json('data');
    expect($order['collected_at'])->not->toBeNull();

    // Handed back at the counter: released, still completed.
    billAs('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$job}/collect")->assertOk()->assertJsonPath('data.lifecycle_stage', 'completed');
    expect($this->getJson("/api/v1/work-orders/{$job}")->json('data.released_at'))->not->toBeNull();

    // The printed documents, for staff and the customer alike.
    foreach (["/api/v1/invoices/{$issued['id']}/pdf", "/api/v1/payments/{$payment['id']}/pdf", '/api/v1/customer-accounts/'.$this->world->id('fc-actimed').'/statement/pdf?from=2026-07-01&to=2026-10-08'] as $uri) {
        foreach (['advisor@mekanikomore.ph', 'donmiguel@mekanikomor.ph'] as $who) {
            billAs($who);
            $pdf = $this->get($uri)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            expect(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-');
        }
    }

    expect(asSystem(fn () => AuditLog::query()->where('entity_id', $issued['id'])->orderBy('occurred_at')->orderBy('id')->pluck('action')->all()))->toBe(['created', 'issued'])
        ->and(asSystem(fn () => AuditLog::query()->where('entity_id', $payment['id'])->pluck('action')->all()))->toBe(['received']);
});

it('bills a seeded job at its approved total, VAT added on top', function () {
    billAs('advisor@mekanikomore.ph');
    [$job] = readyJobs('fc-northwind');
    $approved = $this->getJson("/api/v1/work-orders/{$job}")->json('data.approved_totals');

    $invoice = invoiceDraftFor([$job])->assertCreated()->json('data');

    expect($invoice['totals']['total_due_cents'])->toBe($approved['grand_total_cents'])
        ->and($invoice['totals']['vatable_sales_cents'])->toBe($approved['sub_total_cents'] + $approved['misc_total_cents'])
        ->and($invoice['totals']['vat_amount_cents'])->toBe($approved['tax_total_cents'])
        ->and($invoice['prices_include_vat'])->toBeFalse()
        ->and($invoice['vat_rate_pct'])->toBe('12');
});

it('extracts VAT where the branch\'s prices include it, and charges none where the branch is not VAT-registered', function () {
    billAs('owner@mekanikomore.ph');
    $branch = $this->world->id('mekanikomor-binan');
    [$first, $second] = readyJobs('fc-actimed', 2);

    $this->patchJson("/api/v1/branches/{$branch}", ['prices_include_vat' => true])->assertOk();
    $inclusive = invoiceDraftFor([$first])->assertCreated()->json('data');
    $gross = array_sum(array_column($inclusive['lines'], 'line_total_cents'));
    $vat = (int) round($gross * 12 / 112, 0, PHP_ROUND_HALF_UP);
    expect($inclusive['prices_include_vat'])->toBeTrue()
        ->and($inclusive['totals']['total_due_cents'])->toBe($gross)
        ->and($inclusive['totals']['vat_amount_cents'])->toBe($vat)
        ->and($inclusive['totals']['vatable_sales_cents'])->toBe($gross - $vat);

    $this->patchJson("/api/v1/branches/{$branch}", ['is_vat_registered' => false, 'prices_include_vat' => false, 'tin' => '123-456-789'])->assertOk();
    $nonVat = invoiceDraftFor([$second])->assertCreated()->json('data');
    $issued = issueInvoice($nonVat['id'])->assertOk()
        ->assertJsonPath('data.seller.vat_registered', false)
        ->assertJsonPath('data.totals.vat_amount_cents', 0)
        ->assertJsonPath('data.totals.vatable_sales_cents', 0)
        ->assertJsonPath('data.non_vat_notice', Invoicing::NON_VAT_NOTICE)
        ->json('data');
    expect($issued['totals']['non_vat_sales_cents'])->toBe($issued['totals']['total_due_cents']);

    // The printed invoice carries the required wording, and never claims accreditation.
    $html = asSystem(fn () => app(BillingPdf::class)->invoiceHtml(Invoice::query()->findOrFail($issued['id'])));
    expect($html)->toContain('THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.')
        ->toContain('Non-VAT Reg. TIN')
        ->not->toContain('VATable sales')
        ->and(mb_strtolower($html))->not->toContain('accredit');
});

it('prints a VAT-registered invoice with its VAT breakdown and the branch\'s own header and footer', function () {
    billAs('owner@mekanikomore.ph');
    $branch = $this->world->id('mekanikomor-binan');
    $this->patchJson("/api/v1/branches/{$branch}", [
        'registered_name' => 'MekanikoMoR Auto Services Corp.',
        'business_style' => 'MekanikoMoR',
        'tin' => '123-456-789',
        'branch_code' => '00002',
        'invoice_header' => 'Brgy. San Antonio, Biñan, Laguna',
        'invoice_footer' => 'Permit and series details as registered.',
    ])->assertOk()->assertJsonPath('data.invoice_footer', 'Permit and series details as registered.');

    $id = invoiceDraftFor(readyJobs('fc-actimed'))->json('data.id');
    $invoice = issueInvoice($id)->assertOk()
        ->assertJsonPath('data.seller.name', 'MekanikoMoR Auto Services Corp.')
        ->assertJsonPath('data.seller.tin', '123-456-789')
        ->assertJsonPath('data.seller.branch_code', '00002')
        ->json('data');

    $html = asSystem(fn () => app(BillingPdf::class)->invoiceHtml(Invoice::query()->findOrFail($invoice['id'])));
    expect($html)->toContain('VAT Reg. TIN: 123-456-789-00002')
        ->toContain('VATable sales')
        ->toContain('VAT (12%)')
        ->toContain('Permit and series details as registered.')
        ->toContain($invoice['number'])
        ->not->toContain('NOT VALID FOR CLAIM')
        ->and(mb_strtolower($html))->not->toContain('accredit');

    // The snapshot is frozen: correcting the branch later never reprints an issued invoice differently.
    $this->patchJson("/api/v1/branches/{$branch}", ['registered_name' => 'Renamed Corp.'])->assertOk();
    $this->getJson("/api/v1/invoices/{$invoice['id']}")->assertJsonPath('data.seller.name', 'MekanikoMoR Auto Services Corp.');
});

it('invoices a job once; a void keeps its number and frees the job', function () {
    billAs('advisor@mekanikomore.ph');
    [$job] = readyJobs('fc-actimed');

    $first = invoiceDraftFor([$job])->assertCreated()->json('data.id');
    invoiceDraftFor([$job])->assertStatus(409)->assertJsonPath('error.code', 'conflict');

    // Discarding a draft lets it go.
    $this->deleteJson("/api/v1/invoices/{$first}")->assertNoContent();
    $this->getJson("/api/v1/invoices/{$first}")->assertNotFound();
    $invoice = invoiceDraftFor([$job])->assertCreated()->json('data.id');
    issueInvoice($invoice)->assertOk()->assertJsonPath('data.number', 'INV-2026-0004');
    invoiceDraftFor([$job])->assertStatus(409);

    // Voiding needs billing:void (not the advisor's), and a reason.
    $this->postJson("/api/v1/invoices/{$invoice}/void", ['reason' => 'Wrong account'])->assertForbidden();
    billAs('owner@mekanikomore.ph');
    $this->postJson("/api/v1/invoices/{$invoice}/void")->assertUnprocessable();
    $this->postJson("/api/v1/invoices/{$invoice}/void", ['reason' => 'Billed to the wrong account.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.number', 'INV-2026-0004')
        ->assertJsonPath('data.void_reason', 'Billed to the wrong account.')
        ->assertJsonPath('data.work_orders.0.released', true);
    $this->postJson("/api/v1/invoices/{$invoice}/void", ['reason' => 'Again'])->assertStatus(409);
    $this->getJson("/api/v1/work-orders/{$job}")->assertJsonPath('data.lifecycle_stage', 'ready_for_billing')->assertJsonPath('data.invoice', null);

    // Invoiceable again, under the NEXT number: the void keeps 0004, nothing is reused or skipped.
    $again = invoiceDraftFor([$job])->assertCreated()->json('data.id');
    issueInvoice($again)->assertOk()->assertJsonPath('data.number', 'INV-2026-0005');
    expect(asSystem(fn () => Invoice::query()->whereNotNull('number')->orderBy('number')->pluck('status', 'number')->map(fn ($s) => $s->value)->all()))->toBe([
        'INV-2026-0001' => 'partially_paid',
        'INV-2026-0002' => 'issued',
        'INV-2026-0003' => 'issued',
        'INV-2026-0004' => 'void',
        'INV-2026-0005' => 'issued',
    ]);
});

it('returns the number when an issue rolls back: gap-free', function () {
    billAs('advisor@mekanikomore.ph');
    $draft = invoiceDraftFor(readyJobs('fc-actimed'))->json('data.id');

    $context = app(TenantContextResolver::class)->resolve($this->world->user('advisor@mekanikomore.ph'), null)->context;
    try {
        app(TenantManager::class)->actingAs($context, fn () => DB::transaction(function () use ($draft): void {
            $issued = app(ManageInvoices::class)->issue(Invoice::query()->findOrFail($draft));
            expect($issued->number)->toBe('INV-2026-0004');
            throw new RuntimeException('Printer jammed.');
        }));
    } catch (RuntimeException) {
    }

    $this->getJson("/api/v1/invoices/{$draft}")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.number', null);
    issueInvoice($draft)->assertOk()->assertJsonPath('data.number', 'INV-2026-0004');
});

it('requires an Idempotency-Key to issue, and replays a retry', function () {
    billAs('advisor@mekanikomore.ph');
    $draft = invoiceDraftFor(readyJobs('fc-actimed'))->json('data.id');

    $this->postJson("/api/v1/invoices/{$draft}/issue")->assertUnprocessable()->assertJsonPath('error.details.fields', fn (array $f) => isset($f['idempotency_key']));
    issueInvoice($draft, [], 'retry-me')->assertOk()->assertJsonPath('data.number', 'INV-2026-0004');
    issueInvoice($draft, [], 'retry-me')->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.number', 'INV-2026-0004');
    issueInvoice($draft, [], 'another-key')->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
});

it('dates an invoice no later than today and never behind the series', function () {
    billAs('advisor@mekanikomore.ph');
    $draft = invoiceDraftFor(readyJobs('fc-actimed'))->json('data.id');

    issueInvoice($draft, ['issue_date' => '2026-10-09'])->assertUnprocessable();
    // INV-2026-0003 was issued 2026-09-18.
    issueInvoice($draft, ['issue_date' => '2026-09-01'])->assertUnprocessable()->assertJsonPath('error.details.fields.issue_date.0', 'Invoices are numbered in date order; the last one issued is dated 2026-09-18.');
    issueInvoice($draft, ['issue_date' => '2026-09-30'])->assertOk()->assertJsonPath('data.issue_date', '2026-09-30')->assertJsonPath('data.due_date', '2026-10-30');
});

it('never changes an issued invoice: the API refuses, and so does the database', function () {
    billAs('advisor@mekanikomore.ph');
    $id = $this->world->id('invoice:actimed-current');

    $this->patchJson("/api/v1/invoices/{$id}", ['notes' => 'Edited'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->deleteJson("/api/v1/invoices/{$id}")->assertStatus(409);

    $refused = function (string $sql, array $bindings = []): string {
        try {
            DB::transaction(fn () => DB::statement($sql, $bindings));
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        return 'accepted';
    };
    expect($refused('update invoices set total_due_cents = total_due_cents + 1, vatable_sales_cents = vatable_sales_cents + 1 where id = ?', [$id]))->toBe('23001')
        ->and($refused('update invoices set buyer_name = ? where id = ?', ['Someone else', $id]))->toBe('23001')
        ->and($refused('delete from invoices where id = ?', [$id]))->toBe('23001')
        ->and($refused('update invoice_lines set discount_cents = 1, line_total_cents = line_total_cents - 1 where invoice_id = ?', [$id]))->toBe('23001')
        ->and($refused('delete from invoice_lines where invoice_id = ?', [$id]))->toBe('23001')
        // Totals that do not add up never store, draft or not.
        ->and($refused('update invoices set total_due_cents = 1 where id = ?', [$this->world->id('invoice:sagrada-draft')]))->toBe('23514');
});

it('raises a typed-in invoice, with discounts, and discards a draft', function () {
    billAs('advisor@mekanikomore.ph');
    $walkIn = $this->world->id('walk-in');

    $draft = $this->postJson('/api/v1/invoices', [
        'customer_account_id' => $walkIn,
        'branch_id' => $this->world->id('mekanikomor-binan'),
        'lines' => [
            ['description' => 'Diagnostic scan', 'quantity' => '1', 'unit_price_cents' => 150000, 'item_id' => $this->world->id('item:SVC-DIAG')],
            ['description' => 'Wiper blade (pair)', 'quantity' => '2', 'unit_price_cents' => 82000, 'discount_cents' => 4000],
            ['description' => 'Export detailing (zero-rated)', 'quantity' => '1', 'unit_price_cents' => 50000, 'tax_class' => 'zero_rated'],
        ],
        'notes' => 'Walk-in.',
        'line_total_cents' => 1,
    ])->assertCreated()
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.lines.1.line_total_cents', 160000)
        ->assertJsonPath('data.totals.vatable_sales_cents', 310000)
        ->assertJsonPath('data.totals.zero_rated_sales_cents', 50000)
        ->assertJsonPath('data.totals.discount_total_cents', 4000)
        ->assertJsonPath('data.totals.vat_amount_cents', 37200)
        ->assertJsonPath('data.totals.total_due_cents', 397200)
        ->json('data');

    // A discount bigger than its line is refused; a fair one re-totals.
    $this->patchJson("/api/v1/invoices/{$draft['id']}", ['discounts' => [['line_id' => $draft['lines'][0]['id'], 'discount_cents' => 150001]]])->assertUnprocessable();
    $this->patchJson("/api/v1/invoices/{$draft['id']}", ['discounts' => [['line_id' => $draft['lines'][0]['id'], 'discount_cents' => 50000]]])
        ->assertOk()
        ->assertJsonPath('data.totals.vatable_sales_cents', 260000)
        ->assertJsonPath('data.totals.vat_amount_cents', 31200);

    // A typed-in draft's lines can be replaced; a job-sourced one's cannot.
    $this->patchJson("/api/v1/invoices/{$draft['id']}", ['lines' => [['description' => 'Diagnostic scan', 'quantity' => '1', 'unit_price_cents' => 150000]]])
        ->assertOk()->assertJsonPath('data.totals.total_due_cents', 168000);
    $fromJobs = invoiceDraftFor(readyJobs('fc-actimed'))->json('data.id');
    $this->patchJson("/api/v1/invoices/{$fromJobs}", ['lines' => [['description' => 'x', 'quantity' => '1', 'unit_price_cents' => 1]]])->assertUnprocessable();

    $this->deleteJson("/api/v1/invoices/{$draft['id']}")->assertNoContent();
    expect(asSystem(fn () => AuditLog::query()->where('entity_id', $draft['id'])->pluck('action')->all()))->toBe(['created', 'edited', 'edited', 'discarded']);
});

it('refuses to invoice what is not finished, settled, or one account\'s and one branch\'s', function () {
    billAs('advisor@mekanikomore.ph');
    $open = asSystem(fn () => WorkOrder::query()->where('customer_account_id', $this->world->id('fc-actimed'))->where('status', 'in_progress')->value('id'));
    $settled = asSystem(fn () => WorkOrder::query()->where('customer_account_id', $this->world->id('fc-actimed'))->where('status', 'closed')->whereNotNull('collected_at')->value('id'));

    invoiceDraftFor([$open])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    invoiceDraftFor([$settled])->assertStatus(409);
    invoiceDraftFor([readyJobs('fc-actimed')[0], readyJobs('fc-northwind')[0]])->assertUnprocessable()->assertJsonPath('error.details.fields.work_order_ids.0', 'One invoice bills one customer account.');
    $this->postJson('/api/v1/invoices', ['work_order_ids' => []])->assertUnprocessable();

    // A technician bills nothing.
    billAs('bay@mekanikomore.ph');
    invoiceDraftFor(readyJobs('fc-actimed'))->assertForbidden();
});

it('warns, logs and still raises work for an account over its credit limit', function () {
    billAs('advisor@mekanikomore.ph');
    // Northwind owes INV-2026-0002 against a ₱5,000 limit (the seed).
    $vehicle = asSystem(fn () => Vehicle::query()->where('customer_account_id', $this->world->id('fc-northwind'))->orderBy('id')->value('id'));
    $this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-northwind').'/balance')
        ->assertOk()
        ->assertJsonPath('data.over_limit', true)
        ->assertJsonPath('data.credit_limit_cents', 500000);

    $response = $this->postJson('/api/v1/work-orders', ['vehicle_id' => $vehicle, 'title' => 'Tyre check', 'type' => 'inspection'])
        ->assertCreated()
        ->assertJsonPath('warnings.0.code', 'credit_limit_exceeded')
        ->assertJsonPath('warnings.0.details.credit_limit_cents', 500000);
    expect($response->json('warnings.0.message'))->toContain('against a credit limit of ₱5,000.00')
        ->and(asSystem(fn () => AuditLog::query()->where('entity_id', $response->json('data.id'))->pluck('action')->all()))->toBe(['created', 'credit_limit_override']);

    // Actimed has no limit: no warning.
    $this->postJson('/api/v1/work-orders', ['vehicle_id' => $this->world->id('veh-001'), 'title' => 'Tyre check', 'type' => 'inspection'])
        ->assertCreated()->assertJsonMissingPath('warnings');
});

it('releases a vehicle at the counter without settling its job', function () {
    billAs('advisor@mekanikomore.ph');
    [$job] = readyJobs('fc-actimed');
    expect($this->getJson('/api/v1/shop/ready-for-collection')->json('data.*.id'))->toContain($job);

    $this->postJson("/api/v1/work-orders/{$job}/collect")
        ->assertOk()
        ->assertJsonPath('data.lifecycle_stage', 'ready_for_billing')
        ->assertJsonPath('data.collected_at', null);
    expect($this->getJson("/api/v1/work-orders/{$job}")->json('data.released_at'))->not->toBeNull()
        ->and($this->getJson('/api/v1/shop/ready-for-collection')->json('data.*.id'))->not->toContain($job)
        // Still to be billed.
        ->and($this->getJson('/api/v1/billing/queue?per_page=100&customer_account_id='.$this->world->id('fc-actimed'))->json('data.*.id'))->toContain($job);
});

it('queues billing events only once their transaction commits', function () {
    Queue::fake();
    billAs('advisor@mekanikomore.ph');
    $draft = invoiceDraftFor(readyJobs('fc-actimed'))->json('data.id');

    // Rolled back: nothing announced.
    $context = app(TenantContextResolver::class)->resolve($this->world->user('advisor@mekanikomore.ph'), null)->context;
    try {
        app(TenantManager::class)->actingAs($context, fn () => DB::transaction(function () use ($draft): void {
            app(ManageInvoices::class)->issue(Invoice::query()->findOrFail($draft));
            throw new RuntimeException('Rolled back.');
        }));
    } catch (RuntimeException) {
    }
    Queue::assertNothingPushed();

    // Committed: `invoice.issued` goes to the queue, for the listener an e-invoicing integration plugs into.
    issueInvoice($draft)->assertOk();
    Queue::assertPushed(CallQueuedListener::class, fn ($job): bool => $job->class === PublishBillingEvent::class && $job->data[0] instanceof InvoiceIssued);
});
