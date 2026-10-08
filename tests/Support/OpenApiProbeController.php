<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\ConflictException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scramble documents controller routes, not closures; this exists so a test
 * can check the error responses it infers are rendered as the envelope.
 */
final class OpenApiProbeController
{
    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => ['required', 'string']]);

        if ($request->boolean('duplicate')) {
            throw new ConflictException;
        }

        return new JsonResponse(['data' => ['ok' => true]], 201);
    }
}
