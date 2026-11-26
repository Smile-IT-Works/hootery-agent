<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\ViewException;
use Siw\HooteryAgent\HooteryAgent;

class IgnoredTestException extends RuntimeException {}

beforeEach(function () {
    config(['hootery.url' => 'https://hootery.example.com', 'hootery.token' => '1|secret']);
    Http::fake(fn () => Http::response(['accepted' => 1, 'rejected' => 0], 202));

    Route::get('/boom', fn () => throw new RuntimeException('Deadlock found', 1213));
    Route::get('/reported', function () {
        report(new LogicException('Handled on the side'));

        return 'ok';
    });
    Route::get('/ignored', fn () => throw new IgnoredTestException('Not worth reporting'));
});

/**
 * The events of the one ingest post the agent made.
 *
 * @return list<array<string, mixed>>
 */
function postedEvents(): array
{
    $posts = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/ingest'));

    expect($posts)->toHaveCount(1);

    return $posts->first()[0]->data()['events'];
}

/**
 * The exception events of the one ingest post.
 *
 * @return list<array<string, mixed>>
 */
function postedExceptions(): array
{
    return array_values(array_filter(postedEvents(), fn (array $event): bool => $event['type'] === 'exception'));
}

it('sends an exception that ends a request together with that request', function () {
    $this->get('/boom')->assertServerError();

    expect(postedEvents())->toHaveCount(2)
        ->and(collect(postedEvents())->pluck('type')->sort()->values()->all())->toBe(['exception', 'request'])
        ->and(collect(postedEvents())->firstWhere('type', 'request')['status_code'])->toBe(500);
});

it('records the class, message, code and the app-relative place it was thrown', function () {
    $this->travelTo('2026-11-26 12:00:00.5');

    $this->get('/boom');

    $exception = postedExceptions()[0];
    expect($exception)
        ->exception_class->toBe(RuntimeException::class)
        ->message->toBe('Deadlock found')
        ->occurred_at->toBe('2026-11-26T12:00:00.500000Z')
        ->and($exception['payload'])
        ->code->toBe('1213')
        ->file->toEndWith('tests/Feature/ExceptionCaptureTest.php')
        ->line->toBeInt()
        ->php_version->toBe(PHP_VERSION)
        ->laravel_version->toBe(app()->version());
});

it('records a trace of places and calls, never argument values', function () {
    $this->get('/boom');

    $trace = postedExceptions()[0]['payload']['trace'];
    expect($trace)->not->toBeEmpty()
        ->and($trace[0])->toHaveKeys(['file', 'call'])
        ->and(json_encode($trace))->not->toContain('Deadlock found');
});

it('caps the trace at 50 frames', function () {
    $deep = function (int $depth) use (&$deep): never {
        $depth === 0 ? throw new RuntimeException('Deep') : $deep($depth - 1);
    };
    Route::get('/deep', fn () => $deep(80));

    $this->get('/deep');

    expect(postedExceptions()[0]['payload']['trace'])->toHaveCount(50);
});

it('cuts a message longer than the server accepts', function () {
    Route::get('/long', fn () => throw new RuntimeException(str_repeat('č', 70_000)));

    $this->get('/long');

    expect(mb_strlen(postedExceptions()[0]['message']))->toBe(65_535);
});

it('sends an exception reported by hand during a successful request', function () {
    $this->get('/reported')->assertOk();

    expect(postedExceptions())->toHaveCount(1)
        ->and(postedExceptions()[0]['exception_class'])->toBe(LogicException::class)
        ->and(collect(postedEvents())->firstWhere('type', 'request')['status_code'])->toBe(200);
});

it('skips exceptions the app does not report', function () {
    app(ExceptionHandler::class)->dontReport(IgnoredTestException::class);

    $this->get('/ignored')->assertServerError();

    expect(postedExceptions())->toBeEmpty();
});

it('records the real cause of an exception thrown while rendering a view', function () {
    Route::get('/view-boom', fn () => throw new ViewException('Wrapped', 0, 1, __FILE__, __LINE__, new DomainException('The real cause')));

    $this->get('/view-boom');

    expect(postedExceptions()[0])
        ->exception_class->toBe(DomainException::class)
        ->message->toBe('The real cause');
});

it('holds at most 100 exceptions between sends and drops the oldest', function () {
    $agent = app(HooteryAgent::class);
    foreach (range(1, 150) as $number) {
        $agent->exceptionReported(new RuntimeException("Exception {$number}"));
    }

    $this->get('/reported');

    expect(postedExceptions())->toHaveCount(100)
        ->and(postedExceptions()[0]['message'])->toBe('Exception 52')
        ->and(postedExceptions()[99]['message'])->toBe('Handled on the side');
});

it('records nothing while the agent is not enabled', function () {
    config(['hootery.enabled' => false]);

    $this->get('/boom')->assertServerError();

    Http::assertNothingSent();
    config(['hootery.enabled' => true]);
    $this->get('/reported');
    expect(postedExceptions())->toHaveCount(1);
});

it('keeps the app working when the agent itself fails while capturing', function () {
    config(['hootery.enabled' => 'not-a-boolean']);

    $this->get('/boom')->assertServerError();
    $this->get('/reported')->assertOk()->assertSee('ok');

    Http::assertNothingSent();
});

it('makes paths relative to the app, so traces do not carry the server layout', function () {
    app()->setBasePath(dirname(__DIR__, 2));

    $this->get('/boom');

    $exception = postedExceptions()[0];
    expect($exception['payload']['file'])->toBe('tests/Feature/ExceptionCaptureTest.php')
        ->and(json_encode($exception['payload']['trace']))->not->toContain(dirname(__DIR__, 2));
});

it('leaves the app logging its own exceptions as before', function () {
    Log::spy();

    $this->get('/boom');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'Deadlock found');
    expect(postedExceptions())->toHaveCount(1);
});

it('gives a request and its exceptions one shared trace id', function () {
    $this->get('/boom');

    $traceIds = collect(postedEvents())->pluck('trace_id')->unique();
    expect($traceIds)->toHaveCount(1)
        ->and(Str::isUuid($traceIds->first()))->toBeTrue();
});

it('starts a new trace id for every request', function () {
    $this->get('/reported');
    $this->get('/reported');

    $posts = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/ingest'));
    $firstIds = collect($posts[0][0]->data()['events'])->pluck('trace_id')->unique();
    $secondIds = collect($posts[1][0]->data()['events'])->pluck('trace_id')->unique();

    expect($firstIds)->toHaveCount(1)
        ->and($secondIds)->toHaveCount(1)
        ->and($firstIds->first())->not->toBe($secondIds->first());
});

it('names an anonymous exception class without the file it was declared in', function () {
    Route::get('/anonymous', fn () => throw new class('Anonymous') extends RuntimeException {});

    $this->get('/anonymous');

    expect(postedExceptions()[0]['exception_class'])->toBe('RuntimeException@anonymous');
});

it('records nothing in a console process, which has no request to send with', function () {
    app()['env'] = 'production';
    app(HooteryAgent::class)->exceptionReported(new RuntimeException('In a queue worker'));
    app()['env'] = 'testing';

    $this->get('/reported');

    expect(postedExceptions())->toHaveCount(1)
        ->and(postedExceptions()[0]['message'])->toBe('Handled on the side');
});
