<?php

declare(strict_types=1);

/*
 * Used by `composer setup`. Creates .env from .env.example and generates an
 * APP_KEY only when the key is empty, so re-running setup never rotates the
 * key under existing encrypted data.
 */

$root = dirname(__DIR__);

if (! file_exists($root.'/.env')) {
    copy($root.'/.env.example', $root.'/.env');
    echo "Created .env from .env.example\n";
}

$env = (string) file_get_contents($root.'/.env');

if (preg_match('/^APP_KEY=\S+/m', $env) === 1) {
    echo "APP_KEY already set; leaving it alone\n";
    exit(0);
}

passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/artisan').' key:generate --ansi', $status);
exit($status);
