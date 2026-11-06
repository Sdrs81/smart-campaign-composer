<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base for domain errors that reach the client. Rendered once in bootstrap/app.php
 * as {error:{code,message,details}}.
 */
abstract class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    abstract public function errorCode(): string;

    abstract public function status(): int;

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
