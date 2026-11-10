<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Delivery\ChannelRateLimiter;
use App\Delivery\QueueDepths;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\DeliveryStatus;
use App\Models\Campaign;
use App\Models\Delivery;
use App\Models\LlmCall;

/**
 * Prometheus text exposition, computed from the tables and Redis on every scrape (K21):
 * no counter state of its own, so it cannot drift from the rows. Counts that go down
 * (deliveries leave `pending`) are gauges; `llm_calls_total` only grows, so it is a counter.
 */
class PrometheusMetrics
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    public function __construct(
        private readonly QueueDepths $queueDepths,
        private readonly ChannelRateLimiter $rateLimiter,
    ) {}

    public function __invoke(): string
    {
        return implode('', [
            $this->deliveries(),
            $this->sendPeak(),
            $this->llmCalls(),
            $this->llmLatency(),
            $this->queueDepth(),
            $this->campaigns(),
        ]);
    }

    private function deliveries(): string
    {
        $counts = [];
        foreach (Delivery::query()->toBase()->selectRaw('channel, status, COUNT(*) AS deliveries')->groupBy('channel', 'status')->get() as $group) {
            $counts[$group->channel][$group->status] = (int) $group->deliveries;
        }

        $samples = [];
        foreach (Channel::cases() as $channel) {
            foreach (DeliveryStatus::cases() as $status) {
                $samples[] = [['channel' => $channel->value, 'status' => $status->value], $counts[$channel->value][$status->value] ?? 0];
            }
        }

        return $this->metric('deliveries_total', 'gauge', 'Deliveries by channel and current status.', $samples);
    }

    private function sendPeak(): string
    {
        $samples = [];
        foreach (Channel::cases() as $channel) {
            $samples[] = [['channel' => $channel->value], $this->rateLimiter->peakPerSecond($channel)];
        }

        return $this->metric('send_peak_per_second', 'gauge', 'Most sends allowed in one clock second per channel, measured by the sender.', $samples);
    }

    private function llmCalls(): string
    {
        $samples = [];
        foreach (LlmCall::query()->toBase()->selectRaw('purpose, outcome, COUNT(*) AS calls')->groupBy('purpose', 'outcome')->orderBy('purpose')->orderBy('outcome')->get() as $group) {
            $samples[] = [['purpose' => $group->purpose, 'outcome' => $group->outcome], (int) $group->calls];
        }

        return $this->metric('llm_calls_total', 'counter', 'LLM calls by purpose and outcome.', $samples);
    }

    private function llmLatency(): string
    {
        $average = LlmCall::query()->toBase()->avg('latency_ms');

        // No sample without calls: zero would claim the model answers instantly.
        $samples = $average === null ? [] : [[[], round((float) $average, 1)]];

        return $this->metric('llm_latency_ms_avg', 'gauge', 'Average LLM call latency in milliseconds.', $samples);
    }

    private function queueDepth(): string
    {
        $samples = [];
        foreach (($this->queueDepths)() as $queue => $depth) {
            $samples[] = [['queue' => $queue], $depth];
        }

        return $this->metric('queue_depth', 'gauge', 'Jobs waiting, delayed or being worked in each queue.', $samples);
    }

    private function campaigns(): string
    {
        $counts = Campaign::query()->toBase()->selectRaw('status, COUNT(*) AS campaigns')->groupBy('status')->pluck('campaigns', 'status');

        $samples = [];
        foreach (CampaignStatus::cases() as $status) {
            $samples[] = [['status' => $status->value], (int) ($counts[$status->value] ?? 0)];
        }

        return $this->metric('campaigns', 'gauge', 'Campaigns by status.', $samples);
    }

    /**
     * @param  list<array{0: array<string, string>, 1: int|float}>  $samples  label set and value
     */
    private function metric(string $name, string $type, string $help, array $samples): string
    {
        $text = "# HELP {$name} {$help}\n# TYPE {$name} {$type}\n";

        foreach ($samples as [$labels, $value]) {
            $text .= $name.$this->labels($labels).' '.$value."\n";
        }

        return $text;
    }

    /** @param  array<string, string>  $labels */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $name => $value) {
            // The text format requires escaping backslash, double quote and newline inside a label value.
            $pairs[] = $name.'="'.addcslashes($value, "\\\"\n").'"';
        }

        return '{'.implode(',', $pairs).'}';
    }
}
