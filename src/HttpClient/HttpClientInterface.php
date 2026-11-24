<?php

declare(strict_types=1);

namespace Leadveyor\HttpClient;

/**
 * Sends one HTTP request. The default is CurlClient; pass your own as the `httpClient` option
 * (proxies, a framework's client, tests).
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param float $timeout seconds for the whole request
     * @throws TransportException when no response was received (`timedOut` set for timeouts)
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
