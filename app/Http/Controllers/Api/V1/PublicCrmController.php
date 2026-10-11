<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Crm\PublicLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the links inside a CRM message open. No session: the 40-character
 * token in the link is the credential (only its SHA-256 is stored), it is
 * rate-limited, and it reaches that one message's own subject.
 */
final class PublicCrmController
{
    public function __construct(private readonly PublicLinks $links) {}

    /**
     * A reminder's booking link
     *
     * What the reminder was about and how to book: the branch's online
     * booking page (`booking.mode` = `online`), or a booking request the shop
     * calls back on (`request`).
     */
    public function reminder(string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->links->reminder($token)]);
    }

    /**
     * Ask to be booked
     *
     * Leaves a booking request on the shop's task list, once per reminder.
     * `preferred_date` (today or later) and a `note` are optional.
     */
    public function requestBooking(Request $request, string $token): JsonResponse
    {
        $request->validate(['preferred_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'note' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $date = $request->input('preferred_date');
        $note = $request->input('note');

        return new JsonResponse(['data' => $this->links->requestBooking($token, is_string($date) ? $date : null, is_string($note) && trim($note) !== '' ? trim($note) : null)], 201);
    }

    /**
     * A feedback link
     *
     * Whose feedback it is for, and whether it has been given.
     */
    public function feedback(string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->links->feedback($token)]);
    }

    /**
     * Give feedback
     *
     * A `score` from 0 to 10 and an optional `comment`. Once: a second answer changes nothing.
     */
    public function answerFeedback(Request $request, string $token): JsonResponse
    {
        $request->validate(['score' => ['required', 'integer', 'min:0', 'max:10'], 'comment' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $comment = $request->input('comment');

        return new JsonResponse(['data' => $this->links->answerFeedback($token, $request->integer('score'), is_string($comment) && trim($comment) !== '' ? trim($comment) : null)], 201);
    }

    /**
     * An unsubscribe link
     *
     * Which shop and which kind of message; whether the address is already opted out.
     */
    public function unsubscribe(string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->links->unsubscribe($token, false)]);
    }

    /**
     * Unsubscribe
     *
     * Opts the address out of the channel and withdraws consent for that kind of message.
     */
    public function confirmUnsubscribe(string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->links->unsubscribe($token, true)], 201);
    }
}
