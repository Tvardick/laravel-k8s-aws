<?php

use Illuminate\Support\Facades\DB;

// Simulates a database that is down. The message contains details that must not leak.
function breakDatabase(): void
{
    DB::shouldReceive('select')
        ->andThrow(new RuntimeException('SQLSTATE[08006] connection to host db failed for user notes'));
}

it('returns ok from health', function () {
    $this->get('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('health stays ok when the database is down', function () {
    breakDatabase();

    $this->get('/health')->assertOk();
});

it('health does not start a session', function () {
    $response = $this->get('/health');

    expect($response->headers->getCookies())->toBeEmpty();
});

it('returns ok from ready when the database works', function () {
    $this->get('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('ready returns 503 when the database is down', function () {
    breakDatabase();

    $this->get('/ready')->assertStatus(503);
});

it('ready does not leak the error message', function () {
    breakDatabase();

    $this->get('/ready')->assertExactJson(['status' => 'unavailable']);
});
