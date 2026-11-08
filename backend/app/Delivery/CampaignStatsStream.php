<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Models\Campaign;
use Closure;
use Generator;

/**
 * Server-sent frames for the live campaign status (K21): a snapshot only when it changed,
 * a comment heartbeat while it did not, and an end once the campaign is final or the
 * stream is old. The client's EventSource reconnects by itself after the `retry` delay.
 */
class CampaignStatsStream
{
    public const EVENT = 'stats';

    public const RECONNECT_MILLISECONDS = 3000;

    public const POLL_SECONDS = 1;

    public const HEARTBEAT_SECONDS = 15;

    public const MAX_SECONDS = 600;

    private readonly Closure $sleep;

    /** @param  (Closure(int): mixed)|null  $sleep  Replaced in tests so they do not wait. */
    public function __construct(private readonly CampaignStats $stats, ?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @return Generator<int, string>
     */
    public function __invoke(Campaign $campaign): Generator
    {
        $startedAt = now();
        $lastFrameAt = $startedAt;
        $lastSnapshotHash = null;

        yield 'retry: '.self::RECONNECT_MILLISECONDS."\n\n";

        while (true) {
            $campaign->refresh();
            $snapshot = json_encode(($this->stats)($campaign), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $snapshotHash = hash('sha256', $snapshot);

            if ($snapshotHash !== $lastSnapshotHash) {
                $lastSnapshotHash = $snapshotHash;
                $lastFrameAt = now();

                yield 'event: '.self::EVENT."\ndata: {$snapshot}\n\n";
            } elseif ($lastFrameAt->diffInSeconds(now(), absolute: true) >= self::HEARTBEAT_SECONDS) {
                $lastFrameAt = now();

                // A comment line keeps proxies and the browser from timing out an idle connection.
                yield ": heartbeat\n\n";
            }

            if ($campaign->status->isTerminal() || $startedAt->diffInSeconds(now(), absolute: true) >= self::MAX_SECONDS) {
                return;
            }

            ($this->sleep)(self::POLL_SECONDS);
        }
    }
}
