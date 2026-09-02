<?php
declare(strict_types=1);

namespace foun10\EasySearch\Meili;

use RuntimeException;

/**
 * Anything the Meilisearch HTTP API refused or could not answer.
 *
 * Carries the HTTP status because the callers act on it: a 404 on an index is
 * a normal "not built yet" and makes the engine report itself unavailable,
 * while a 401 or a connection failure is a configuration problem worth
 * showing.
 */
class MeiliException extends RuntimeException
{
    public function __construct(
        string $message,
        protected readonly int $status = 0,
        protected readonly string $errorCode = ''
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
