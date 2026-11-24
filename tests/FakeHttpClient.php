<?php

declare(strict_types=1);

namespace Leadveyor\Tests;

use Leadveyor\HttpClient\HttpClientInterface;
use Leadveyor\HttpClient\Response;

/** Records requests; `$respond(int $n, array $call)` returns a Response or throws. */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $calls = [];

    /** @param \Closure(int, array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}): Response $respond */
    public function __construct(private readonly \Closure $respond)
    {
    }

    /** JSON response helper. */
    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, json_encode($body, JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json'] + $headers);
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        $call = ['method' => $method, 'url' => $url, 'headers' => $h, 'body' => $body, 'timeout' => $timeout];
        $this->calls[] = $call;
        return ($this->respond)(count($this->calls), $call);
    }
}
