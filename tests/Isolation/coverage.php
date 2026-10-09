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
];
