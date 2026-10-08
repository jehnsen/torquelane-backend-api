<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Errors\ErrorCode;
use RuntimeException;

/**
 * Base for errors the application raises on purpose. The exception renderer
 * turns each into the envelope with its own code; anything that is not an
 * ApiException (or a known framework exception) renders as `server_error`.
 */
abstract class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = '', private readonly ?array $details = null)
    {
        parent::__construct($message !== '' ? $message : $this->errorCode()->defaultMessage());
    }

    abstract public function errorCode(): ErrorCode;

    /**
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        return $this->details;
    }
}
