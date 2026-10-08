<?php

declare(strict_types=1);

/*
 * R5: every GET route under /api/v1 is declared here, either
 *   'public'    => why it carries no tenant data, or
 *   'isolation' => the test class proving an out-of-scope caller gets 404 and
 *                  a sibling customer account sees nothing (Phase 1 onwards).
 *
 * RouteCoverageTest fails when a GET route is missing from this list, or this
 * list names a route that no longer exists.
 */

return [
    'api/v1/health' => ['public' => 'Database and queue status for uptime monitors; no tenant data.'],
];
