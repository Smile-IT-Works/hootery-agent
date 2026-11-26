<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['hootery.url' => 'https://hootery.example.com/', 'hootery.token' => '1|secret', 'hootery.timeout' => 2.5]);
});

it('connects with the token and names the project and environment', function () {
    app()->detectEnvironment(fn (): string => 'production');
    Http::fake(['hootery.example.com/api/ping' => Http::response(['project' => 'tribuna', 'environment' => 'production'])]);

    $this->artisan('hootery:test')
        ->expectsOutputToContain('Connected to tribuna/production.')
        ->doesntExpectOutputToContain('but the token belongs to')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://hootery.example.com/api/ping'
        && $request->hasHeader('Authorization', 'Bearer 1|secret')
        && $request->hasHeader('Accept', 'application/json'));
});

it('warns when the token belongs to another environment than the app runs as', function () {
    app()->detectEnvironment(fn (): string => 'staging');
    Http::fake(['*' => Http::response(['project' => 'tribuna', 'environment' => 'production'])]);

    $this->artisan('hootery:test')
        ->expectsOutputToContain('Connected to tribuna/production.')
        ->expectsOutputToContain('This app runs as staging, but the token belongs to tribuna/production.')
        ->assertSuccessful();
});

it('refuses when the agent is switched off', function () {
    config(['hootery.enabled' => false]);
    Http::fake();

    $this->artisan('hootery:test')
        ->expectsOutputToContain('The agent is switched off (HOOTERY_ENABLED=false).')
        ->assertFailed();

    Http::assertNothingSent();
});

it('asks for the url and token when either is missing', function (string $key) {
    config([$key => null]);
    Http::fake();

    $this->artisan('hootery:test')
        ->expectsOutputToContain('Set HOOTERY_URL and HOOTERY_TOKEN to connect the agent.')
        ->assertFailed();

    Http::assertNothingSent();
})->with(['hootery.url', 'hootery.token']);

it('reports a rejected token', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('hootery:test')
        ->expectsOutputToContain('The server rejected the token. Check HOOTERY_TOKEN.')
        ->assertFailed();
});

it('reports a server it cannot reach', function () {
    Http::fake(['*' => Http::failedConnection('Could not resolve host: hootery.example.com')]);

    $this->artisan('hootery:test')
        ->expectsOutputToContain('Could not reach the Hootery server at https://hootery.example.com/: Could not resolve host: hootery.example.com')
        ->assertFailed();
});

it('reports any other answer by its status', function () {
    Http::fake(['*' => Http::response('Oops', 500)]);

    $this->artisan('hootery:test')
        ->expectsOutputToContain('The Hootery server answered with HTTP 500.')
        ->assertFailed();
});

it('never follows a redirect, so the token goes to the configured server only', function () {
    Http::fake(['*' => Http::response('', 301, ['Location' => 'https://elsewhere.example.com/api/ping'])]);

    $this->artisan('hootery:test')
        ->expectsOutputToContain('The Hootery server answered with HTTP 301.')
        ->assertFailed();

    expect(Http::recorded())->toHaveCount(1);
});

it('fails on an answer that is not a Hootery server\'s, such as a page from a wrong host', function () {
    Http::fake(['*' => Http::response('<html>Welcome</html>', 200)]);

    $this->artisan('hootery:test')
        ->expectsOutputToContain('The server at https://hootery.example.com/ did not answer like a Hootery server (HTTP 200).')
        ->doesntExpectOutputToContain('Connected')
        ->assertFailed();
});
