<?php

declare(strict_types=1);

namespace App\Api;

/**
 * A UniFi API error. Contains a user-facing message and an optional
 * HTTP status / upstream error code. Technical details are logged, never shown.
 */
class UniFiApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?array $responseBody = null,
        private readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct($message);
    }

    /**
     * True when a non-idempotent request (POST) failed after it may already
     * have reached the controller – it must not be repeated blindly because
     * that could create duplicates.
     */
    public function outcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function responseBody(): ?array
    {
        return $this->responseBody;
    }
}
