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
];
