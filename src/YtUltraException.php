<?php

declare(strict_types=1);

namespace YtUltra;

use RuntimeException;
use Throwable;

/**
 * Exception thrown for any expected API failure.
 *
 * The HTTP status is carried alongside the message so the front controller can
 * map failures to a sensible response without leaking internals.
 */
final class YtUltraException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 502,
        ?Throwable $previous = null,
        private readonly ?string $upstreamCode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Upstream error code (for example "5023" or "5016"), when there was one.
     */
    public function getUpstreamCode(): ?string
    {
        return $this->upstreamCode;
    }
}
