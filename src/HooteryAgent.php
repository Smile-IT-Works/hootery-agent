<?php

declare(strict_types=1);

namespace Siw\HooteryAgent;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\ViewException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Collects this app's events during a request and sends them to the Hootery
 * server once the response has gone out. A failed send is dropped, never
 * retried and never reported, so the agent can neither slow the app down for
 * long nor break it, nor loop on its own errors.
 */
class HooteryAgent
{
    public const int MAX_BUFFERED_EVENTS = 100;

    public const int MAX_TRACE_FRAMES = 50;

    public const int MAX_MESSAGE_CHARACTERS = 65_535;

    public const int MAX_PATH_CHARACTERS = 2_048;

    /**
     * Route parameter names whose values are secrets and never leave the app.
     */
    public const string SECRET_PARAMETER_PATTERN = '/token|signature|secret|hash|password|key/i';

    /**
     * The events recorded since the last send.
     *
     * @var list<array<string, mixed>>
     */
    private array $events = [];

    /**
     * The id shared by every event recorded until the next send, which links
     * a request to the exceptions that happened in it.
     */
    private ?string $traceId = null;

    /**
     * Whether the agent may send anything: it is switched on and knows the
     * server and this environment's token.
     */
    public function isEnabled(): bool
    {
        return config()->boolean('hootery.enabled')
            && filled(config('hootery.url'))
            && filled(config('hootery.token'));
    }

    /**
     * Record an exception the app reported, to be sent with the request it
     * happened in. Called by the exception handler, so exceptions the app
     * does not report are never seen. A process without web requests (a
     * queue worker, a console command) records nothing, as nothing would
     * send it; in a request the buffer keeps only the newest events.
     */
    public function exceptionReported(Throwable $exception): void
    {
        try {
            if (! $this->isEnabled() || $this->runsWithoutRequests()) {
                return;
            }

            $this->events[] = $this->exceptionEvent($exception);

            if (count($this->events) > self::MAX_BUFFERED_EVENTS) {
                array_shift($this->events);
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * Record the finished request and send it with everything recorded during
     * it. Called by the HTTP kernel after the response is sent.
     */
    public function requestFinished(CarbonInterface $startedAt, Request $request, Response $response): void
    {
        try {
            if (! $this->isEnabled()) {
                return;
            }

            if (! $request->is(...(array) config('hootery.ignore_paths', []))) {
                $this->events[] = $this->requestEvent($startedAt, $request, $response);
            }

            if ($this->events !== []) {
                $this->send();
            }
        } catch (Throwable) {
            $this->events = [];
            $this->traceId = null;
        }
    }

    /**
     * The request as an ingest event. The path is the route pattern, so all
     * visits to one page group together; the real path goes in the payload,
     * and the query string is never sent.
     *
     * @return array<string, mixed>
     */
    private function requestEvent(CarbonInterface $startedAt, Request $request, Response $response): array
    {
        $route = $request->route();
        $realPath = '/'.ltrim($request->path(), '/');

        $event = [
            'type' => 'request',
            'trace_id' => $this->traceId(),
            'occurred_at' => $startedAt->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'method' => $request->getMethod(),
            'path' => mb_substr($route instanceof Route ? '/'.ltrim($route->uri(), '/') : $realPath, 0, self::MAX_PATH_CHARACTERS),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => (int) round(abs($startedAt->diffInMilliseconds(now()))),
            'payload' => [
                'url_path' => mb_substr($route instanceof Route ? $this->maskSecretParameters($realPath, $route) : $realPath, 0, self::MAX_PATH_CHARACTERS),
                'route_name' => $route instanceof Route ? $route->getName() : null,
                'route_action' => $route instanceof Route ? $route->getActionName() : null,
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ],
        ];

        // A long-lived worker (Octane, queue) keeps one peak for its lifetime;
        // resetting it makes the next request report its own.
        memory_reset_peak_usage();

        return $event;
    }

    /**
     * The real path with the values of secret-looking route parameters (a
     * password reset token, a signature) replaced by the parameter's name.
     */
    private function maskSecretParameters(string $realPath, Route $route): string
    {
        foreach ($route->originalParameters() as $name => $value) {
            if (is_string($value) && $value !== '' && preg_match(self::SECRET_PARAMETER_PATTERN, $name) === 1) {
                $realPath = str_replace('/'.rawurlencode($value), '/{'.$name.'}', $realPath);
                $realPath = str_replace('/'.$value, '/{'.$name.'}', $realPath);
            }
        }

        return $realPath;
    }

    /**
     * Whether this process serves no web requests (a queue worker, a console
     * command), so nothing would ever send what it records.
     */
    private function runsWithoutRequests(): bool
    {
        return app()->runningInConsole() && ! app()->runningUnitTests();
    }

    /**
     * The trace id of the events being recorded, started with the first one.
     */
    private function traceId(): string
    {
        return $this->traceId ??= (string) Str::uuid7();
    }

    /**
     * The exception as an ingest event. A view exception is unwrapped to the
     * exception that caused it. The trace names places and calls only, never
     * argument values, and paths are relative to the app.
     *
     * @return array<string, mixed>
     */
    private function exceptionEvent(Throwable $exception): array
    {
        while ($exception instanceof ViewException && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        return [
            'type' => 'exception',
            'trace_id' => $this->traceId(),
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'exception_class' => Str::before($exception::class, "\0"),
            'message' => mb_substr($exception->getMessage(), 0, self::MAX_MESSAGE_CHARACTERS),
            'payload' => [
                'code' => (string) $exception->getCode(),
                'file' => $this->relativePath($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => $this->trace($exception),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ];
    }

    /**
     * The exception's stack, newest call first, as "file:line" and the call
     * made there, capped so the event stays within the server's size limit.
     *
     * @return list<array{file: string, call: string}>
     */
    private function trace(Throwable $exception): array
    {
        return array_map(fn (array $frame): array => [
            'file' => isset($frame['file'])
                ? $this->relativePath($frame['file']).(isset($frame['line']) ? ':'.$frame['line'] : '')
                : '[internal]',
            'call' => ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()',
        ], array_slice($exception->getTrace(), 0, self::MAX_TRACE_FRAMES));
    }

    /**
     * A path relative to the app's base path, so traces do not carry the
     * server's directory layout.
     */
    private function relativePath(string $path): string
    {
        return Str::after($path, rtrim(base_path(), '/').'/');
    }

    /**
     * Post the recorded events to the server and forget them, whatever the
     * outcome. A failed post is logged, so a wrong token or an unreachable
     * server shows up in the app's log instead of as a quiet dashboard.
     */
    private function send(): void
    {
        $events = $this->events;
        $this->events = [];
        $this->traceId = null;
        $url = rtrim((string) config('hootery.url'), '/').'/api/ingest';

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('hootery.token'))
                ->connectTimeout(config()->float('hootery.connect_timeout'))
                ->timeout(config()->float('hootery.timeout'))
                ->post($url, ['events' => $events]);
        } catch (Throwable $exception) {
            $this->warnAboutFailedDelivery($url, null, $exception->getMessage());

            return;
        }

        if ($response->failed()) {
            $this->warnAboutFailedDelivery($url, $response->status(), $response->reason());

            return;
        }

        if ((int) $response->json('rejected', 0) > 0) {
            $this->warnOnce('hootery-agent:events-rejected', 'Hootery server rejected events', [
                'url' => $url,
                'rejected' => (int) $response->json('rejected'),
                'errors' => $response->json('errors', []),
            ]);
        }
    }

    /**
     * Log a post that did not arrive, so a wrong token or an unreachable
     * server shows up in the app's log.
     */
    private function warnAboutFailedDelivery(string $url, ?int $status, string $error): void
    {
        $this->warnOnce('hootery-agent:delivery-failed', 'Hootery agent could not deliver events', [
            'url' => $url,
            'status' => $status,
            'error' => $error,
        ]);
    }

    /**
     * Log a warning at most once every ten minutes per kind, so a lasting
     * problem cannot flood the app's log. A plain log line, never a reported
     * exception, so the agent cannot capture its own failure.
     *
     * @param  array<string, mixed>  $context
     */
    private function warnOnce(string $key, string $message, array $context): void
    {
        $warn = fn () => Log::warning($message, $context);

        try {
            RateLimiter::attempt($key, maxAttempts: 1, callback: $warn, decaySeconds: 600);
        } catch (Throwable) {
            // The cache that counts the warnings is down too: warn anyway.
            $warn();
        }
    }
}
