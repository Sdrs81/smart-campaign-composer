<?php

declare(strict_types=1);

namespace App\Llm;

use App\Content\MessageContent;

/**
 * What ContentGenerator tells its consumer, one event at a time. The three types are the names of the
 * server-sent events the endpoint will send.
 */
final readonly class ContentEvent
{
    public const DELTA = 'delta';

    public const COMPLETED = 'final';

    public const FAILED = 'error';

    /**
     * @param  list<string>  $errors  path-based problems, set on a failure caused by invalid text
     */
    private function __construct(
        public string $type,
        public string $text = '',
        public ?MessageContent $sms = null,
        public ?MessageContent $email = null,
        public bool $repaired = false,
        public string $code = '',
        public string $message = '',
        public array $errors = [],
    ) {}

    /** A piece of the model's raw JSON, passed on as it arrives. */
    public static function delta(string $text): self
    {
        return new self(self::DELTA, text: $text);
    }

    /** The validated messages; $repaired is true when the first answer needed a repair round. */
    public static function completed(MessageContent $sms, MessageContent $email, bool $repaired): self
    {
        return new self(self::COMPLETED, sms: $sms, email: $email, repaired: $repaired);
    }

    /** @param  list<string>  $errors */
    public static function failed(string $code, string $message, array $errors = []): self
    {
        return new self(self::FAILED, code: $code, message: $message, errors: $errors);
    }
}
