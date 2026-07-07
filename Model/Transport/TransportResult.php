<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Transport;

/**
 * Immutable outcome of a single payload delivery attempt.
 */
class TransportResult
{
    public function __construct(
        private readonly bool $success,
        private readonly ?int $statusCode,
        private readonly ?string $responseBody,
        private readonly ?string $errorMessage
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
