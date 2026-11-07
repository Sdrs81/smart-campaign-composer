<?php

declare(strict_types=1);

namespace MockProvider;

/**
 * Returned instead of echoing, so tests can assert status and headers without an HTTP server.
 */
final readonly class Response
{
    /**
     * @param  array<mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $body,
        public array $headers = [],
    ) {}

    public function emit(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode($this->body, JSON_THROW_ON_ERROR);
    }
}
