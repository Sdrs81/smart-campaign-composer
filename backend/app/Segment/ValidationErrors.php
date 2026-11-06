<?php

declare(strict_types=1);

namespace App\Segment;

/** Path-based parse failures, e.g. "conditions[1].value: ...". Read by the repair prompt and the UI. */
final readonly class ValidationErrors
{
    /** @param  non-empty-list<array{path: string, message: string}>  $errors */
    public function __construct(public array $errors) {}

    /** @return non-empty-list<string> */
    public function messages(): array
    {
        return array_map(fn (array $error): string => $error['path'].': '.$error['message'], $this->errors);
    }
}
