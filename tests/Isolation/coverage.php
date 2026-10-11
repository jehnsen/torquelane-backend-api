<?php

declare(strict_types=1);

use Tests\Isolation\TenantIsolationSuite;

/*
 * R5: every GET route under /api/v1 is declared here, either
 *   'public'    => why it carries no tenant data, or
 *   'isolation' => the suite that probes it. TenantIsolationSuite generates
 *                  its probes from the route list itself, so a route declared
 *                  here is exercised for every demo user automatically.
 *
 * RouteCoverageTest fails when a GET route is missing from this list, or this
 * list names a route that no longer exists.
 */

$isolated = ['isolation' => TenantIsolationSuite::class];

return [
    'api/v1/health' => ['public' => 'Database and queue status for uptime monitors; no tenant data.'],
    'api/v1/sanctum/csrf-cookie' => ['public' => 'Sets the XSRF-TOKEN cookie for SPA login; no body, no tenant data.'],
    // Phase 9: a detailing branch's public booking page. What a branch publishes about itself (name, look, menu,
    // free starts), and a booking read back by the unguessable token in its confirmation link.
    'api/v1/public/detailing/{branchSlug}' => ['public' => 'A branch\'s public booking page: its name, theme and active menu; no customer or staff data (PublicAndDisplayTest).'],
    'api/v1/public/detailing/{branchSlug}/availability' => ['public' => 'Which starts are free on a day, with no detail of who is booked (PublicAndDisplayTest).'],
    'api/v1/public/detailing/bookings/{token}' => ['public' => 'One booking\'s confirmation by its 160-bit token (only its SHA-256 is stored); its own reference, package and times (PublicAndDisplayTest).'],
    'api/v1/display/detailing/monitor' => ['public' => 'No session: a per-branch display token (X-Display-Token, revocable) is the credential, and it reads that branch\'s monitor only (PublicAndDisplayTest).'],

    // Phase 11: what the links inside a CRM message open. The 40-character token in the link (only its SHA-256 is
    // stored) is the credential, and it reaches that one message's own subject.
    'api/v1/public/crm/reminders/{token}' => ['public' => 'A reminder\'s booking link by its token: the shop, the customer\'s first name as addressed, the vehicle it was about (CrmPublicLinksTest).'],
    'api/v1/public/crm/feedback/{token}' => ['public' => 'A feedback link by its token: the shop and whether it was answered (CrmPublicLinksTest).'],
    'api/v1/public/crm/unsubscribe/{token}' => ['public' => 'An unsubscribe link by its token: the shop and the kind of message (CrmPublicLinksTest).'],

    'api/v1/document-files/{document}' => ['public' => 'Signed 60-second URL issued after the document policy check; the signature is the credential (tested in DocumentTest).'],

    'api/v1/me' => $isolated,
    'api/v1/organization' => $isolated,
    'api/v1/branches' => $isolated,
    'api/v1/branches/{branch}' => $isolated,
    'api/v1/modules' => $isolated,
    'api/v1/users' => $isolated,
    'api/v1/users/{user}' => $isolated,
    'api/v1/invitations' => $isolated,
    'api/v1/customer-accounts' => $isolated,
    'api/v1/customer-accounts/{customer_account}' => $isolated,
    'api/v1/customer-accounts/{customer_account}/contacts' => $isolated,
    'api/v1/customer-accounts/{customer_account}/contacts/{contact}' => $isolated,
    'api/v1/customer-accounts/{customer_account}/consents' => $isolated,
    'api/v1/customer-accounts/{customer_account}/consents/current' => $isolated,
    'api/v1/bays' => $isolated,
    'api/v1/bays/{bay}' => $isolated,
    'api/v1/technicians' => $isolated,
    'api/v1/technicians/{technician}' => $isolated,
    'api/v1/vehicles' => $isolated,
    'api/v1/vehicles/{vehicle}' => $isolated,
    'api/v1/vehicles/{vehicle}/ownerships' => $isolated,
    'api/v1/vehicles/{vehicle}/health' => $isolated,
    'api/v1/vehicles/{vehicle}/readings' => $isolated,
    'api/v1/fleet/summary' => $isolated,
    'api/v1/service-tasks' => $isolated,
    'api/v1/service-tasks/{service_task}' => $isolated,
    'api/v1/documents' => $isolated,
    'api/v1/documents/{document}' => $isolated,
    'api/v1/documents/{document}/download' => $isolated,
    'api/v1/alerts' => $isolated,
    'api/v1/approval-settings' => $isolated,
    'api/v1/work-orders' => $isolated,
    'api/v1/work-orders/{work_order}' => $isolated,
    'api/v1/check-in/lookup' => $isolated,
    'api/v1/shop/arriving' => $isolated,
    'api/v1/shop/in-progress' => $isolated,
    'api/v1/shop/ready-for-collection' => $isolated,
    'api/v1/shop/approvals' => $isolated,
    'api/v1/shop/floor' => $isolated,
    'api/v1/shop/technicians' => $isolated,
    'api/v1/shop/revenue' => $isolated,

    // Phase 4
    'api/v1/work-orders/summary' => $isolated,
    'api/v1/documents/summary' => $isolated,
    'api/v1/shop/home' => $isolated,
    'api/v1/shop/reports' => $isolated,
    'api/v1/shop/clients' => $isolated,
    'api/v1/shop/clients/{customer_account}' => $isolated,
    'api/v1/analytics/dashboard' => $isolated,
    'api/v1/analytics/schedule' => $isolated,
    'api/v1/analytics/reports' => $isolated,
    'api/v1/analytics/auto-schedule' => $isolated,
    'api/v1/requests' => $isolated,
    'api/v1/customer-accounts/{customer_account}/approval-settings' => $isolated,
    'api/v1/vendors' => $isolated,
    'api/v1/vendors/{vendor}' => $isolated,
    'api/v1/fleet-parts' => $isolated,
    'api/v1/fleet-parts/{fleet_part}' => $isolated,
    'api/v1/demand-forecast' => $isolated,
    'api/v1/purchase-orders' => $isolated,
    'api/v1/purchase-orders/export' => $isolated,
    'api/v1/purchase-orders/{purchase_order}' => $isolated,
    'api/v1/purchase-orders/{purchase_order}/export' => $isolated,

    // Phase 6: the shop's inventory (staff only; branch-owned records are 404 outside the caller's branches)
    'api/v1/items' => $isolated,
    'api/v1/items/{item}' => $isolated,
    'api/v1/stock-locations' => $isolated,
    'api/v1/stock/on-hand' => $isolated,
    'api/v1/stock/moves' => $isolated,
    'api/v1/stock/alerts' => $isolated,
    'api/v1/stock/reorder' => $isolated,
    'api/v1/shop-purchase-orders' => $isolated,
    'api/v1/shop-purchase-orders/{shop_purchase_order}' => $isolated,
    'api/v1/goods-receipts' => $isolated,
    'api/v1/goods-receipts/{goods_receipt}' => $isolated,
    'api/v1/stock-counts' => $isolated,
    'api/v1/stock-counts/{stock_count}' => $isolated,
    'api/v1/stock-transfers' => $isolated,
    'api/v1/stock-transfers/{stock_transfer}' => $isolated,

    // Phase 7: order-to-cash. Invoices and payments are account-owned (a portal user sees their own, issued ones) and branch-owned for staff.
    'api/v1/billing/queue' => $isolated,
    'api/v1/invoices' => $isolated,
    'api/v1/invoices/{invoice}' => $isolated,
    'api/v1/invoices/{invoice}/pdf' => $isolated,
    'api/v1/payments' => $isolated,
    'api/v1/payments/{payment}' => $isolated,
    'api/v1/payments/{payment}/pdf' => $isolated,
    'api/v1/receivables/aging' => $isolated,
    'api/v1/receivables/revenue' => $isolated,
    'api/v1/customer-accounts/{customer_account}/balance' => $isolated,
    'api/v1/customer-accounts/{customer_account}/statement' => $isolated,
    'api/v1/customer-accounts/{customer_account}/statement/pdf' => $isolated,

    // Phase 8: the books. Staff-only; the chart is the organization's, entries belong to the branches they touch.
    'api/v1/ledger/accounts' => $isolated,
    'api/v1/ledger/posting-rules' => $isolated,
    'api/v1/ledger/settings' => $isolated,
    'api/v1/ledger/journal' => $isolated,
    'api/v1/ledger/journal/export' => $isolated,
    'api/v1/ledger/journal/{journal_entry}' => $isolated,
    'api/v1/ledger/periods' => $isolated,
    'api/v1/ledger/periods/checklist' => $isolated,
    'api/v1/ledger/reports/trial-balance' => $isolated,
    'api/v1/ledger/reports/general-ledger/{account}' => $isolated,
    'api/v1/ledger/reports/profit-and-loss' => $isolated,
    'api/v1/ledger/reports/balance-sheet' => $isolated,
    'api/v1/ledger/reports/daily-sales' => $isolated,

    // Phase 9: detailing. Staff-only, branch-owned, behind the `detailing` module.
    'api/v1/detailing/catalogue' => $isolated,
    'api/v1/detailing/duration' => $isolated,
    'api/v1/detailing/settings' => $isolated,
    'api/v1/detailing/display-tokens' => $isolated,
    'api/v1/detailing/recurring-schedules' => $isolated,
    'api/v1/detailing/schedule' => $isolated,
    'api/v1/detailing/monitor' => $isolated,
    'api/v1/detailing/availability' => $isolated,
    'api/v1/detailing/realtime' => $isolated,
    'api/v1/detailing/bookings' => $isolated,
    'api/v1/detailing/bookings/{detailing_booking}' => $isolated,
    'api/v1/detailing/bookings/{detailing_booking}/inspections' => $isolated,

    // Phase 10: equipment monitoring. Staff-only, branch-owned, behind the `equipment` module.
    'api/v1/equipment' => $isolated,
    'api/v1/equipment/options' => $isolated,
    'api/v1/equipment/profiles' => $isolated,
    'api/v1/equipment/{equipment}' => $isolated,

    // Phase 11: the CRM. Staff-only, the organization's, behind the `crm` module.
    'api/v1/crm/options' => $isolated,
    'api/v1/crm/customers' => $isolated,
    'api/v1/crm/customers/{customer_account}' => $isolated,
    'api/v1/crm/customers/{customer_account}/benefits' => $isolated,
    'api/v1/crm/tasks' => $isolated,
    'api/v1/crm/leads' => $isolated,
    'api/v1/crm/leads/{lead}' => $isolated,
    'api/v1/crm/settings' => $isolated,
    'api/v1/crm/templates' => $isolated,
    'api/v1/crm/reminder-rules' => $isolated,
    'api/v1/crm/messages' => $isolated,
    'api/v1/crm/feedback' => $isolated,
    'api/v1/crm/tiers' => $isolated,
    'api/v1/crm/prepaid-plans' => $isolated,
    'api/v1/crm/prepaid-packages' => $isolated,
    'api/v1/crm/segments' => $isolated,
    'api/v1/crm/segments/{segment}/members' => $isolated,
    'api/v1/crm/campaigns' => $isolated,
    'api/v1/crm/campaigns/{campaign}' => $isolated,
];
