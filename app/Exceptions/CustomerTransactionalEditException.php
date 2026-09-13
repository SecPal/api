<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class CustomerTransactionalEditException extends RuntimeException
{
    private function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function badRequest(): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'BAD_REQUEST', 'Invalid request parameters');
    }

    public static function forbidden(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'FORBIDDEN', 'Insufficient permissions');
    }

    public static function notFound(): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'NOT_FOUND', 'Resource not found');
    }

    public static function conflict(?\Throwable $previous = null): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            'CUSTOMER_EDIT_CONFLICT',
            'The customer edit conflicts with the current resource state.',
            $previous,
        );
    }

    public static function stale(): self
    {
        return new self(
            Response::HTTP_PRECONDITION_FAILED,
            'CUSTOMER_EDIT_STALE',
            'The customer edit snapshot is stale.',
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
