<?php

declare(strict_types=1);

namespace App\Http\Errors;

/**
 * The closed set of machine-readable codes in the error envelope.
 *
 * Clients branch on `code`, never on `message`. Adding a case is an API change:
 * document it in CLAUDE.md and regenerate openapi.json.
 */
enum ErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    /** Also returned for records outside the caller's tenant scope. */
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case BadRequest = 'bad_request';
    case Validation = 'validation';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case ModuleDisabled = 'module_disabled';
    /** A portal user of a suspended customer account; also new work for one. */
    case AccountSuspended = 'account_suspended';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,
            self::Forbidden, self::ModuleDisabled, self::AccountSuspended => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::BadRequest => 400,
            self::Validation => 422,
            self::InvalidTransition, self::Conflict => 409,
            self::RateLimited => 429,
            self::ServerError => 500,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Unauthenticated.',
            self::Forbidden => 'This action is unauthorized.',
            self::NotFound => 'Not found.',
            self::MethodNotAllowed => 'Method not allowed.',
            self::BadRequest => 'Bad request.',
            self::Validation => 'The given data was invalid.',
            self::InvalidTransition => 'This transition is not allowed from the current state.',
            self::Conflict => 'The request conflicts with the current state.',
            self::ModuleDisabled => 'This module is not enabled for your organization.',
            self::AccountSuspended => 'This customer account is suspended.',
            self::RateLimited => 'Too many requests.',
            self::ServerError => 'Server error.',
        };
    }
}
