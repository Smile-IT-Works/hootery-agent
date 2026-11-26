<?php

declare(strict_types=1);

namespace Siw\HooteryAgent;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
    /**
     * The events recorded since the last send.
     *
     * @var list<array<string, mixed>>
     */
    private array $events = [];

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
     * Record the finished request and send it with everything recorded during
     * it. Called by the HTTP kernel after the response is sent.
     */
    public function requestFinished(CarbonInterface $startedAt, Request $request, Response $response): void
    {
        try {
            if (! $this->isEnabled()) {
                return;
            }

            $this->events[] = $this->requestEvent($startedAt, $request, $response);

            $this->send();
        } catch (Throwable) {
            $this->events = [];
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

        return [
            'type' => 'request',
            'occurred_at' => $startedAt->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'method' => $request->getMethod(),
            'path' => $route instanceof Route ? '/'.ltrim($route->uri(), '/') : $realPath,
            'status_code' => $response->getStatusCode(),
            'duration_ms' => (int) round(abs($startedAt->diffInMilliseconds(now()))),
            'payload' => [
                'url_path' => $realPath,
                'route_name' => $route instanceof Route ? $route->getName() : null,
                'route_action' => $route instanceof Route ? $route->getActionName() : null,
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ],
        ];
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
        $url = rtrim((string) config('hootery.url'), '/').'/api/ingest';

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('hootery.token'))
                ->connectTimeout(config()->float('hootery.connect_timeout'))
                ->timeout(config()->float('hootery.timeout'))
                ->post($url, ['events' => $events]);
        } catch (ConnectionException $exception) {
            $this->warnAboutFailedDelivery($url, null, $exception->getMessage());

            return;
        }

        if ($response->failed()) {
            $this->warnAboutFailedDelivery($url, $response->status(), $response->reason());
        }
    }

    /**
     * Log a failed delivery at most once every ten minutes, so a server that
     * is down cannot flood the app's log. A plain log line, never a reported
     * exception, so the agent cannot capture its own failure.
     */
    private function warnAboutFailedDelivery(string $url, ?int $status, string $error): void
    {
        RateLimiter::attempt(
            'hootery-agent:delivery-failed',
            maxAttempts: 1,
            callback: fn () => Log::warning('Hootery agent could not deliver events', [
                'url' => $url,
                'status' => $status,
                'error' => $error,
            ]),
            decaySeconds: 600,
        );
    }
}
