<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ConflictException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a designated POST route safe to retry.
 *
 * Usage, after auth: `->middleware(['auth:sanctum', 'idempotent'])`, or
 * `idempotent:required` to reject a request that omits the header.
 *
 * The key is scoped to (user, method + path, Idempotency-Key). The first
 * request claims the key with an INSERT … ON CONFLICT DO NOTHING, so two
 * concurrent requests cannot both run the action. The claimant's response is
 * stored for 24h. After that:
 *   - same key, same body  → the stored response, with `Idempotent-Replayed: true`
 *   - same key, other body → 409 conflict
 *   - same key, first request still running → 409 conflict
 * 5xx and non-replayable (streamed) responses are not stored; the claim is
 * released so the client's retry runs the action again.
 */
final class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    public const TTL_HOURS = 24;

    /** A claim with no response after this long belongs to a request that died; it may be taken over. */
    public const ABANDONED_AFTER_SECONDS = 120;

    private const KEY_PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    private const IN_PROGRESS = 'A request with this Idempotency-Key is still being processed. Retry shortly.';

    /** Response headers worth replaying. Everything else is regenerated per response. */
    private const STORED_HEADERS = ['Content-Type', 'Location'];

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'The Idempotency-Key header is required for this endpoint.',
                ]);
            }

            /** @var Response */
            return $next($request);
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'The Idempotency-Key header must be 1 to 255 printable ASCII characters.',
            ]);
        }

        $user = $request->user();
        if ($user === null) {
            throw new LogicException('The idempotent middleware must run after authentication.');
        }

        $userId = $user->getAuthIdentifier();
        if (! is_string($userId)) {
            throw new LogicException('The idempotent middleware expects ULID user ids.');
        }
        $scopeHash = hash('sha256', $request->method().' '.$request->path());
        $fingerprint = $this->fingerprint($request);

        $claimId = $this->claim($userId, $scopeHash, $key, $fingerprint);

        if ($claimId === null) {
            $existing = IdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('scope_hash', $scopeHash)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null && ! $existing->isExpired() && ! $existing->isAbandoned(self::ABANDONED_AFTER_SECONDS)) {
                return $this->replayOrReject($existing, $fingerprint);
            }

            // Expired, abandoned, or deleted under us: take the key over.
            // Deleting by id only removes the claim we inspected; a fresh
            // claim made meanwhile has a different id and survives, and then
            // our second claim loses to it.
            if ($existing !== null) {
                IdempotencyKey::query()->whereKey($existing->getKey())->delete();
            }

            $claimId = $this->claim($userId, $scopeHash, $key, $fingerprint)
                ?? throw new ConflictException(self::IN_PROGRESS);
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            IdempotencyKey::query()->whereKey($claimId)->delete();

            throw $e;
        }

        $this->complete($claimId, $response);

        return $response;
    }

    /**
     * @return string|null the new claim's id, or null when the key is already held
     */
    private function claim(string $userId, string $scopeHash, string $key, string $fingerprint): ?string
    {
        $id = (string) Str::ulid();
        $now = now();

        $inserted = IdempotencyKey::query()->insertOrIgnore([
            'id' => $id,
            'user_id' => $userId,
            'scope_hash' => $scopeHash,
            'idempotency_key' => $key,
            'request_hash' => $fingerprint,
            'created_at' => $now,
            'expires_at' => $now->copy()->addHours(self::TTL_HOURS),
        ]);

        return $inserted === 1 ? $id : null;
    }

    private function replayOrReject(IdempotencyKey $existing, string $fingerprint): Response
    {
        if (! hash_equals($existing->request_hash, $fingerprint)) {
            throw new ConflictException('This Idempotency-Key was already used with a different request.');
        }

        if ($existing->isPending()) {
            throw new ConflictException(self::IN_PROGRESS);
        }

        return $existing->toReplayResponse()->withHeaders([self::REPLAYED_HEADER => 'true']);
    }

    private function complete(string $claimId, Response $response): void
    {
        $content = $response->getContent();

        if ($response->getStatusCode() >= 500 || $content === false) {
            IdempotencyKey::query()->whereKey($claimId)->delete();

            return;
        }

        $headers = [];
        foreach (self::STORED_HEADERS as $name) {
            $value = $response->headers->get($name);
            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        IdempotencyKey::query()->whereKey($claimId)->update([
            'response_status' => $response->getStatusCode(),
            'response_headers' => json_encode($headers, JSON_THROW_ON_ERROR),
            'response_body' => $content,
            'completed_at' => now(),
        ]);
    }

    /**
     * A hash of what the request asks for, independent of key order and
     * whitespace in the JSON body.
     */
    private function fingerprint(Request $request): string
    {
        $files = array_map(
            static fn (UploadedFile $file): string => (string) hash_file('sha256', $file->getRealPath()),
            array_filter(
                Arr::dot($request->allFiles()),
                static fn (mixed $file): bool => $file instanceof UploadedFile,
            ),
        );

        $payload = [
            'query' => self::canonical($request->query()),
            'body' => self::canonical($request->isJson() ? $request->json()->all() : $request->post()),
            'files' => self::canonical($files),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
