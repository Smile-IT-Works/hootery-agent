<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['hootery.url' => 'https://hootery.example.com', 'hootery.token' => '1|secret']);
    // One fake for the whole test: each test sets the reply it needs, so no
    // later fake can be shadowed by an earlier, matching one.
    $this->serverReply = Http::response(['accepted' => 1, 'rejected' => 0], 202);
    Http::fake(fn (Request $request) => is_callable($this->serverReply) ? ($this->serverReply)($request) : $this->serverReply);

    Route::get('/tournaments/{tournament}', fn (string $tournament) => "Tournament {$tournament}")->name('tournaments.show');
    Route::post('/orders', fn () => response('Created', 201));
});

/**
 * The events of the one ingest post the agent made.
 *
 * @return list<array<string, mixed>>
 */
function sentEvents(): array
{
    $posts = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/ingest'));

    expect($posts)->toHaveCount(1);

    return $posts->first()[0]->data()['events'];
}

it('sends one request event after each request, with the token', function () {
    $this->get('/tournaments/17')->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://hootery.example.com/api/ingest'
        && $request->hasHeader('Authorization', 'Bearer 1|secret')
        && $request->hasHeader('Accept', 'application/json'));

    expect(sentEvents())->toHaveCount(1);
});

it('records the route pattern as the path and the real path in the payload', function () {
    $this->get('/tournaments/17?invite=secret-token')->assertOk();

    expect(sentEvents()[0])
        ->type->toBe('request')
        ->method->toBe('GET')
        ->path->toBe('/tournaments/{tournament}')
        ->status_code->toBe(200)
        ->and(sentEvents()[0]['payload'])
        ->url_path->toBe('/tournaments/17')
        ->route_name->toBe('tournaments.show');
});

it('never sends the query string', function () {
    $this->get('/tournaments/17?invite=secret-token')->assertOk();

    expect(json_encode(sentEvents()))->not->toContain('secret-token');
});

it('records the real path of a request no route matched', function () {
    $this->get('/no-such-page')->assertNotFound();

    expect(sentEvents()[0])
        ->path->toBe('/no-such-page')
        ->status_code->toBe(404);
});

it('records the status code and method of any request', function () {
    $this->post('/orders')->assertCreated();

    expect(sentEvents()[0])
        ->method->toBe('POST')
        ->path->toBe('/orders')
        ->status_code->toBe(201);
});

it('records when the request started, in UTC with microseconds, and how long it took', function () {
    $this->travelTo('2026-11-26 12:00:00.123456');

    $this->get('/tournaments/17')->assertOk();

    $event = sentEvents()[0];
    expect($event['occurred_at'])->toBe('2026-11-26T12:00:00.123456Z')
        ->and($event['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($event['payload']['peak_memory_bytes'])->toBeInt()->toBeGreaterThan(0);
});

it('sends nothing while the agent is not enabled', function (array $config) {
    config($config);

    $this->get('/tournaments/17')->assertOk();

    Http::assertNothingSent();
})->with([
    'switched off' => [['hootery.enabled' => false]],
    'no url' => [['hootery.url' => null]],
    'no token' => [['hootery.token' => null]],
]);

it('keeps the app working when the server cannot be reached', function () {
    $this->serverReply = Http::failedConnection();

    $this->get('/tournaments/17')->assertOk()->assertSee('Tournament 17');
});

it('keeps the app working when the server answers with an error', function (int $status) {
    $this->serverReply = Http::response('Nope', $status);

    $this->get('/tournaments/17')->assertOk();

    expect(Http::recorded())->toHaveCount(1);
})->with([429, 500, 503]);

it('logs a refused send with its status, so a wrong token shows up in the app log', function () {
    Log::spy();
    $this->serverReply = Http::response(['message' => 'Unauthenticated.'], 401);

    $this->get('/tournaments/17')->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Hootery agent could not deliver events'
        && $context['status'] === 401
        && $context['url'] === 'https://hootery.example.com/api/ingest');
});

it('logs a server it cannot reach', function () {
    Log::spy();
    $this->serverReply = Http::failedConnection();

    $this->get('/tournaments/17')->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Hootery agent could not deliver events'
        && $context['status'] === null
        && $context['error'] !== '');
});

it('logs a failing send at most once every ten minutes', function () {
    Log::spy();
    $this->serverReply = Http::response('Nope', 500);

    $this->get('/tournaments/17');
    $this->get('/tournaments/18');
    Log::shouldHaveReceived('warning')->once();

    $this->travel(601)->seconds();
    $this->get('/tournaments/19');
    Log::shouldHaveReceived('warning')->twice();
});

it('logs nothing when the send succeeds', function () {
    Log::spy();

    $this->get('/tournaments/17')->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('measures how long the request took', function () {
    Route::get('/slow', function () {
        test()->travel(250)->milliseconds();

        return 'ok';
    });

    $this->get('/slow')->assertOk();

    expect(sentEvents()[0]['duration_ms'])->toBe(250);
});

it('reports the peak memory of this request, not of the whole worker', function () {
    $large = str_repeat('x', 64 * 1024 * 1024);
    unset($large);
    $workerPeak = memory_get_peak_usage(true);

    $this->get('/tournaments/17')->assertOk();

    expect(memory_get_peak_usage(true))->toBeLessThan($workerPeak);
});

it('masks secrets carried in the path, such as a password reset token', function () {
    Route::get('/reset-password/{token}', fn () => 'reset');
    Route::get('/invites/{invite}/accept/{signature}', fn () => 'accept');

    $this->get('/reset-password/live-reset-token-123')->assertOk();
    $this->get('/invites/7/accept/abc-signature')->assertOk();

    $posts = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/ingest'));
    expect($posts[0][0]->data()['events'][0]['payload']['url_path'])->toBe('/reset-password/{token}')
        ->and($posts[1][0]->data()['events'][0]['payload']['url_path'])->toBe('/invites/7/accept/{signature}')
        ->and(json_encode($posts->map(fn ($post) => $post[0]->data())->all()))->not->toContain('live-reset-token-123')->not->toContain('abc-signature');
});

it('cuts a path longer than the server accepts', function () {
    $this->get('/'.str_repeat('a', 3000))->assertNotFound();

    expect(mb_strlen(sentEvents()[0]['path']))->toBe(2048)
        ->and(mb_strlen(sentEvents()[0]['payload']['url_path']))->toBe(2048);
});

it('logs a send that fails for any other reason', function () {
    Log::spy();
    $this->serverReply = fn () => throw new RuntimeException('A host middleware broke');

    $this->get('/tournaments/17')->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Hootery agent could not deliver events'
        && $context['error'] === 'A host middleware broke');
});

it('masks a secret route parameter even when route binding turned it into an object', function () {
    Route::bind('token', fn (string $value): object => (object) ['value' => $value]);
    Route::get('/magic/{token}', fn () => 'in')->middleware(SubstituteBindings::class);

    $this->get('/magic/live-magic-token')->assertOk();

    expect(sentEvents()[0]['payload']['url_path'])->toBe('/magic/{token}')
        ->and(json_encode(sentEvents()))->not->toContain('live-magic-token');
});

it('logs events the server rejected, with its reasons, so a drifted clock shows up in the app log', function () {
    Log::spy();
    $this->serverReply = Http::response(['accepted' => 0, 'rejected' => 1, 'errors' => ['0' => ['occurred_at' => ['The events.0.occurred_at field must be a date after or equal to -7 days.']]]], 202);

    $this->get('/tournaments/17')->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Hootery server rejected events'
        && $context['rejected'] === 1
        && isset($context['errors']['0']['occurred_at']));
});
