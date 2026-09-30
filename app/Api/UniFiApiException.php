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
    ) {
        parent::__construct($message);
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
