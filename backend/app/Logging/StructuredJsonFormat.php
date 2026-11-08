<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\RedactingFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as MonologLogger;

/**
 * Channel tap: one JSON object per line, with secrets and personal data masked in the final
 * output. The masking is a net under the rule "do not log them": exception messages, SQL
 * bindings and provider error bodies can still carry a key or a recipient (the mock provider
 * even echoes a rejected recipient), and they must not reach the log.
 */
class StructuredJsonFormat
{
    /** @var list<string> context keys whose whole value is masked */
    private const SENSITIVE_KEYS = ['password', 'secret', 'token', 'api_key', 'apikey', 'x-api-key', 'authorization', 'cookie', 'phone', 'email', 'to'];

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        // Laravel's logger wraps a Monolog logger for the channel drivers that use this tap.
        if (! $monolog instanceof MonologLogger) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $handler->setFormatter(new RedactingFormatter(new JsonFormatter, self::SENSITIVE_KEYS, SensitiveText::PATTERNS, SensitiveText::MASK));
        }
    }
}
