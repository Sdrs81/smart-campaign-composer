<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

// Runs against the real Postgres and Redis containers (`make test`); only an outage
// has to be simulated.
function redisDown(): void
{
    $connection = Mockery::mock();
    $connection->shouldReceive('ping')->andThrow(new RuntimeException('Connection refused'));

    Redis::shouldReceive('connection')->andReturn($connection);
}

// The mock provider is a separate container; its answer is faked so every state is deterministic.
// Http::fake keeps the first matching stub, so a test fakes the provider once.
function providerAnswers(mixed $answer): void
{
    Http::fake(['*/health' => $answer]);
}

beforeEach(function () {
    clearQueues();
});

afterEach(function () {
    clearQueues();
});

function clearQueues(): void
{
    foreach (['default', 'sms', 'email'] as $queue) {
        Queue::connection('redis')->clear($queue);
    }
}

it('returns 200 with dependency states, queue depths and the LLM mode when everything is up', function () {
    providerAnswers(Http::response(['status' => 'ok']));
    Queue::connection('redis')->pushRaw('{"job":"a"}', 'sms');

    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'up', 'redis' => 'up', 'provider' => 'up'],
            'queues' => ['default' => 0, 'sms' => 1, 'email' => 0],
            'llm_driver' => config('llm.driver'),
        ]);
});

it('asks the provider health endpoint', function () {
    providerAnswers(Http::response(['status' => 'ok']));

    $this->getJson('/api/health')->assertOk();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/health') && $request->method() === 'GET');
});

it('answers 200 with status degraded when only the provider is down', function (callable $providerFailure) {
    providerAnswers($providerFailure);

    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.provider', 'down')
        ->assertJsonPath('checks.database', 'up')
        ->assertJsonPath('checks.redis', 'up');
})->with([
    'unreachable' => [fn () => throw new ConnectionException('Connection refused')],
    'unhealthy answer' => [fn () => Http::response(['error' => 'down'], 500)],
]);

it('probes the provider once while its state is cached and again after it expires', function () {
    providerAnswers(Http::response(['status' => 'ok']));

    $this->getJson('/api/health')->assertOk();
    $this->getJson('/api/health')->assertOk();
    Http::assertSentCount(1);

    $this->travel(16)->seconds();
    $this->getJson('/api/health')->assertOk();
    Http::assertSentCount(2);
});

it('caches a down provider too, so a stalled lookup is paid once', function () {
    // A request that throws is not recorded by Http::fake, so the probes are counted in the fake itself.
    $probes = 0;
    providerAnswers(function () use (&$probes) {
        $probes++;

        throw new ConnectionException('Resolving timed out');
    });

    $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.provider', 'down');
    $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.provider', 'down');

    expect($probes)->toBe(1);
});

it('probes the provider directly when redis is down, without touching the cache', function () {
    providerAnswers(Http::response(['status' => 'ok']));
    redisDown();

    $this->getJson('/api/health')->assertStatus(503);
    $this->getJson('/api/health')->assertStatus(503);

    Http::assertSentCount(2);
});

it('never calls the model API', function () {
    Http::fake(['*/health' => Http::response(['status' => 'ok']), '*' => Http::response([], 500)]);

    $this->getJson('/api/health')->assertOk();

    Http::assertSentCount(1);
});

it('returns 503 with the error body when redis is down', function () {
    providerAnswers(Http::response(['status' => 'ok']));
    redisDown();

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'dependency_unavailable')
        ->assertJsonPath('error.message', 'Required dependency is down: redis.')
        ->assertJsonPath('error.details.checks.redis', 'down')
        ->assertJsonPath('error.details.checks.database', 'up');
});

it('does not list the provider as a required dependency when a critical one is down too', function () {
    redisDown();
    providerAnswers(Http::response([], 500));

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('error.message', 'Required dependency is down: redis.')
        ->assertJsonPath('error.details.checks.provider', 'down');
});

it('returns 503 when the database is down', function () {
    providerAnswers(Http::response(['status' => 'ok']));
    config([
        'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/missing.sqlite'],
        'database.default' => 'broken',
    ]);

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('error.details.checks.database', 'down');
});

it('renders unknown api routes in the same error shape', function () {
    $this->getJson('/api/does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure(['error' => ['code', 'message', 'details']])
        ->assertJsonPath('error.code', 'not_found');
});

it('renders validation failures with field errors in details', function () {
    Route::get('/api/_test/validate', fn () => request()->validate(['name' => 'required']));

    $this->getJson('/api/_test/validate')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['name']]]]);
});

it('hides internals of unexpected errors when debug is off', function () {
    config(['app.debug' => false]);
    Route::get('/api/_test/boom', fn () => throw new RuntimeException('secret detail'));

    $this->getJson('/api/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertJsonMissing(['message' => 'secret detail']);
});
