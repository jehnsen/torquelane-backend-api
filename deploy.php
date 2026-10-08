<?php

declare(strict_types=1);

namespace Deployer;

/*
 * Zero-downtime deploys to the CloudPanel VPS: each deploy is a fresh release
 * folder; `current` is switched atomically once it is ready. See docs/deploy.md.
 *
 *   vendor/bin/dep deploy staging        (or the "Deploy staging" GitHub Action)
 *   vendor/bin/dep rollback staging
 *
 * Everything host-specific comes from the environment, so nothing about the
 * server is committed:
 *   DEPLOY_HOST        e.g. 203.0.113.10 or vps.example.com
 *   DEPLOY_USER        the CloudPanel site user, e.g. torquelane-api-staging
 *   DEPLOY_PATH        e.g. /home/torquelane-api-staging/htdocs/api-staging.example.com
 *   DEPLOY_REPOSITORY  e.g. git@github.com:<owner>/torquelane-api.git
 *   DEPLOY_PHP         optional, PHP binary on the server (default: php8.4)
 *   DEPLOY_HEALTH_URL  optional, e.g. https://api-staging.example.com/api/v1/health
 */

require 'recipe/laravel.php';

function env_or_fail(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new \RuntimeException("Set {$name} before deploying (see docs/deploy.md).");
    }

    return $value;
}

set('application', 'torquelane-api');
set('repository', fn () => env_or_fail('DEPLOY_REPOSITORY'));
set('keep_releases', 5);
set('bin/php', fn () => getenv('DEPLOY_PHP') ?: 'php8.4');
// CloudPanel runs PHP-FPM as the site user, which also owns the files.
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');

// Placeholders keep `dep list` usable without the variables; deploy:check-env
// stops a real deploy before it touches the server.
host('staging')
    ->setHostname(getenv('DEPLOY_HOST') ?: 'DEPLOY_HOST-unset')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'DEPLOY_USER-unset')
    ->setDeployPath(getenv('DEPLOY_PATH') ?: '/DEPLOY_PATH-unset')
    ->set('branch', 'main')
    ->set('labels', ['stage' => 'staging']);

desc('Refuses to deploy without the DEPLOY_* environment');
task('deploy:check-env', function (): void {
    foreach (['DEPLOY_HOST', 'DEPLOY_USER', 'DEPLOY_PATH', 'DEPLOY_REPOSITORY'] as $name) {
        env_or_fail($name);
    }
})->once();

before('deploy', 'deploy:check-env');

/*
 * The recipe's flow, in order:
 *   deploy:prepare → deploy:vendors (composer install --no-dev) →
 *   artisan:storage:link → artisan:optimize (config/route/event/view cache) →
 *   artisan:migrate (--force, BEFORE the switch: migrations must keep the
 *   previous release working) → deploy:publish (atomic `current` symlink) →
 *   artisan:reload (restarts queue workers after their current job).
 */

desc('Fails the deploy if the new release does not report a reachable database');
task('deploy:health', function (): void {
    $url = getenv('DEPLOY_HEALTH_URL');
    if ($url === false || $url === '') {
        writeln('<comment>DEPLOY_HEALTH_URL not set; skipping the post-deploy health check.</comment>');

        return;
    }

    // 200 = ok or degraded; 503 = database unreachable. -f fails on >= 400.
    run('curl -fsS --max-time 10 '.escapeshellarg($url));
});

after('deploy:publish', 'deploy:health');
after('deploy:failed', 'deploy:unlock');
