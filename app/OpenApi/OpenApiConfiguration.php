<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;

/**
 * openapi.json is committed and CI fails when it is stale, so the document
 * must not depend on anything machine-specific (APP_URL in particular). The
 * server is therefore the relative path, not a host.
 */
final class OpenApiConfiguration
{
    public static function boot(): void
    {
        Scramble::registerExtension(ErrorEnvelopeResponses::class);

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->servers = [
                (new Server('/api/v1'))->setDescription('Relative to the environment host, e.g. https://api-staging.<domain>'),
            ];
        });
    }
}
