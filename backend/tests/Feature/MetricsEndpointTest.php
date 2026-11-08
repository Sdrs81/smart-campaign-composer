<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\LlmCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['default', 'sms', 'email'] as $queue) {
        Queue::connection('redis')->clear($queue);
    }
});

afterEach(function (): void {
    foreach (['default', 'sms', 'email'] as $queue) {
        Queue::connection('redis')->clear($queue);
    }
});

function metricsDelivery(Campaign $campaign, string $channel, string $status): void
{
    $delivery = Delivery::create([
        'campaign_id' => $campaign->id,
        'customer_id' => Customer::factory()->create()->id,
        'channel' => $channel,
        'idempotency_key' => hash('sha256', uniqid('', true)),
    ]);
    Delivery::query()->whereKey($delivery->id)->update(['status' => $status]);
}

function metricsLlmCall(string $purpose, string $outcome, int $latencyMs): void
{
    LlmCall::create([
        'purpose' => $purpose,
        'model' => 'fake',
        'latency_ms' => $latencyMs,
        'attempt' => 1,
        'outcome' => $outcome,
    ]);
}

it('serves Prometheus text with a HELP and TYPE line per metric', function (): void {
    $response = $this->get('/api/metrics')->assertOk();
    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('text/plain; version=0.0.4; charset=utf-8');

    foreach ([
        'deliveries_total' => 'gauge',
        'llm_calls_total' => 'counter',
        'llm_latency_ms_avg' => 'gauge',
        'queue_depth' => 'gauge',
        'campaigns' => 'gauge',
    ] as $name => $type) {
        expect($body)->toContain("# HELP {$name} ")->toContain("# TYPE {$name} {$type}\n");
    }
});

it('reports delivery counts per channel and status, with zeros for the empty ones', function (): void {
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    $otherCampaign = Campaign::create(['brief' => 'Yeni yil']);
    foreach (['sent', 'sent', 'sent', 'failed'] as $status) {
        metricsDelivery($campaign, 'sms', $status);
    }
    metricsDelivery($otherCampaign, 'sms', 'sent');
    metricsDelivery($campaign, 'email', 'pending');

    $body = $this->get('/api/metrics')->getContent();

    expect($body)->toContain("deliveries_total{channel=\"sms\",status=\"sent\"} 4\n")
        ->toContain("deliveries_total{channel=\"sms\",status=\"failed\"} 1\n")
        ->toContain("deliveries_total{channel=\"sms\",status=\"pending\"} 0\n")
        ->toContain("deliveries_total{channel=\"email\",status=\"pending\"} 1\n")
        ->toContain("deliveries_total{channel=\"email\",status=\"sent\"} 0\n");
});

it('reports LLM calls by purpose and outcome and the average latency', function (): void {
    metricsLlmCall('segment', 'succeeded', 100);
    metricsLlmCall('segment', 'invalid', 300);
    metricsLlmCall('content', 'succeeded', 200);
    metricsLlmCall('content', 'succeeded', 400);

    $body = $this->get('/api/metrics')->getContent();

    expect($body)->toContain("llm_calls_total{purpose=\"content\",outcome=\"succeeded\"} 2\n")
        ->toContain("llm_calls_total{purpose=\"segment\",outcome=\"invalid\"} 1\n")
        ->toContain("llm_calls_total{purpose=\"segment\",outcome=\"succeeded\"} 1\n")
        ->toContain("llm_latency_ms_avg 250\n");
});

it('leaves the latency without a sample when there were no LLM calls', function (): void {
    $body = $this->get('/api/metrics')->getContent();

    expect($body)->not->toMatch('/^llm_latency_ms_avg /m')
        ->and($body)->not->toMatch('/^llm_calls_total\{/m');
});

it('reports the number of campaigns in every status', function (): void {
    foreach ([CampaignStatus::Draft, CampaignStatus::Draft, CampaignStatus::Completed] as $status) {
        $campaign = Campaign::create(['brief' => 'Sepet terk']);
        Campaign::query()->whereKey($campaign->id)->update(['status' => $status->value]);
    }

    $body = $this->get('/api/metrics')->getContent();

    expect($body)->toContain("campaigns{status=\"draft\"} 2\n")
        ->toContain("campaigns{status=\"completed\"} 1\n")
        ->toContain("campaigns{status=\"sending\"} 0\n");
});

it('reports the depth of the default and channel queues from Redis', function (): void {
    Queue::connection('redis')->pushRaw('{"job":"a"}', 'sms');
    Queue::connection('redis')->pushRaw('{"job":"b"}', 'sms');
    Queue::connection('redis')->pushRaw('{"job":"c"}', 'email');

    $body = $this->get('/api/metrics')->getContent();

    expect($body)->toContain("queue_depth{queue=\"sms\"} 2\n")
        ->toContain("queue_depth{queue=\"email\"} 1\n")
        ->toContain("queue_depth{queue=\"default\"} 0\n");
});
