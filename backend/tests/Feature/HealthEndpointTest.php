<?php

declare(strict_types=1);

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

it('returns 200 with dependency states when everything is up', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'up', 'redis' => 'up'],
            'llm_driver' => config('llm.driver'),
        ]);
});

it('returns 503 with the error body when redis is down', function () {
    redisDown();

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'dependency_unavailable')
        ->assertJsonPath('error.message', 'Required dependency is down: redis.')
        ->assertJsonPath('error.details.checks.redis', 'down')
        ->assertJsonPath('error.details.checks.database', 'up');
});

it('returns 503 when the database is down', function () {
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
