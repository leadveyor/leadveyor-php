<?php

declare(strict_types=1);

namespace Leadveyor;

use Leadveyor\Exception\ConnectionException;
use Leadveyor\Exception\LeadveyorException;
use Leadveyor\Exception\TimeoutException;
use Leadveyor\HttpClient\CurlClient;
use Leadveyor\HttpClient\HttpClientInterface;
use Leadveyor\HttpClient\TransportException;

/**
 * Shared HTTP layer; the generated `Leadveyor` class adds the resources on top.
 *
 * @phpstan-type ClientOptions array{
 *     apiKey?: string,
 *     baseUrl?: string,
 *     workspace?: string,
 *     account?: string,
 *     timeout?: int|float,
 *     maxRetries?: int,
 *     httpClient?: HttpClientInterface,
 *     defaultHeaders?: array<string, string>,
 *     sleep?: callable(float): void,
 * }
 * @phpstan-type RequestOptions array{
 *     workspace?: string,
 *     account?: string,
 *     timeout?: int|float,
 *     maxRetries?: int,
 *     headers?: array<string, string>,
 * }
 */
abstract class BaseClient
{
    public const DEFAULT_BASE_URL = 'https://core.leadveyor.com';

    /** HTTP statuses retried for requests that are safe to repeat. */
    private const RETRY_STATUSES = [500, 502, 503, 504];

    /** POST can create things twice if retried after the server got it; only 429 (rejected up front) is retried for POST. */
    private const IDEMPOTENT_METHODS = ['GET', 'PUT', 'PATCH', 'DELETE'];

    /** @var ClientOptions The options as given (for withOptions / withWorkspace). */
    protected array $options;

    private string $apiKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private HttpClientInterface $http;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param string|ClientOptions|null $apiKeyOrOptions
     * @param ClientOptions $options
     */
    public function __construct(string|array|null $apiKeyOrOptions = null, array $options = [])
    {
        $opts = is_string($apiKeyOrOptions)
            ? array_merge($options, ['apiKey' => $apiKeyOrOptions])
            : array_merge($apiKeyOrOptions ?? [], $options);
        $apiKey = ($opts['apiKey'] ?? null) ?: self::env('LEADVEYOR_API_KEY');
        if (!$apiKey) {
            throw new Exception\AuthenticationException(
                'No API key. Pass it to `new Leadveyor(\'lv_live_…\')` or set LEADVEYOR_API_KEY. Keys: console → API keys.',
                type: 'authentication_error'
            );
        }
        $this->options = $opts;
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim(($opts['baseUrl'] ?? null) ?: (self::env('LEADVEYOR_BASE_URL') ?: self::DEFAULT_BASE_URL), '/');
        $this->timeout = (float) ($opts['timeout'] ?? 60);
        $this->maxRetries = (int) ($opts['maxRetries'] ?? 2);
        $this->http = $opts['httpClient'] ?? new CurlClient();
        $this->sleep = $opts['sleep'] ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /** `lv_test_…` keys work on test data only. */
    public function livemode(): bool
    {
        return !str_starts_with($this->apiKey, 'lv_test_');
    }

    /**
     * Sends one API request: retries, timeout, error mapping. Returns the response as objects, or null when empty.
     *
     * @internal Use the resource methods (`$lv->leads->list()` …).
     * @param array<string, mixed>|null $query
     * @param array<mixed>|object|null $body
     * @param RequestOptions $options
     * @param class-string<LeadveyorObject>|null $class
     * @param bool|null $idempotent Safe to repeat after a 5xx / network error although it is a POST.
     */
    public function request(
        string $method,
        string $path,
        ?array $query = null,
        array|object|null $body = null,
        array $options = [],
        ?string $class = null,
        ?bool $idempotent = null,
    ): mixed {
        $url = $this->baseUrl . $path . self::buildQuery($query);
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'leadveyor-sdk-php/' . Version::VERSION . ' PHP/' . PHP_VERSION,
        ];
        foreach ($this->options['defaultHeaders'] ?? [] as $k => $v) {
            $headers[$k] = $v;
        }
        $workspace = $options['workspace'] ?? $this->options['workspace'] ?? null;
        $account = $options['account'] ?? $this->options['account'] ?? null;
        if ($workspace) {
            $headers['Leadveyor-Workspace'] = $workspace;
        }
        if ($account) {
            $headers['Leadveyor-Account'] = $account;
        }
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = self::encodeBody($body);
        }
        foreach ($options['headers'] ?? [] as $k => $v) {
            $headers[$k] = $v;
        }

        $maxRetries = (int) ($options['maxRetries'] ?? $this->maxRetries);
        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        $idempotent ??= in_array($method, self::IDEMPOTENT_METHODS, true);

        for ($attempt = 0; ; $attempt++) {
            $canRetry = $attempt < $maxRetries;
            try {
                $res = $this->http->send($method, $url, $headers, $payload, $timeout);
            } catch (TransportException $e) {
                if ($canRetry && $idempotent) {
                    ($this->sleep)(self::retryDelay($attempt, null));
                    continue;
                }
                $message = $e->timedOut
                    ? sprintf('Request timed out after %s s: %s %s', $timeout, $method, $path)
                    : sprintf('Could not reach the Leadveyor API (%s %s): %s', $method, $path, $e->getMessage());
                $cls = $e->timedOut ? TimeoutException::class : ConnectionException::class;
                throw new $cls($message, type: 'connection_error', previous: $e);
            }

            $parsed = self::parseBody($res->body, $res->header('content-type'));
            if ($res->status >= 200 && $res->status < 300) {
                if ($parsed === null) {
                    return null;
                }
                if ($class !== null && $parsed instanceof \stdClass) {
                    return $class::constructFrom($parsed);
                }
                return LeadveyorObject::convert($parsed);
            }
            $retryable = $res->status === 429 || (in_array($res->status, self::RETRY_STATUSES, true) && $idempotent);
            if ($retryable && $canRetry) {
                ($this->sleep)(self::retryDelay($attempt, $res->header('retry-after')));
                continue;
            }
            throw LeadveyorException::fromResponse($res->status, $parsed, $res->headers);
        }
    }

    /**
     * A paginated list: the first page now, the next ones as you iterate.
     *
     * @internal
     * @template T of LeadveyorObject
     * @param array<string, mixed> $query
     * @param RequestOptions $options
     * @param class-string<T> $itemClass
     * @return Page<T>
     */
    public function requestPage(string $path, array $query, array $options, string $itemClass): Page
    {
        $fetch = function (?string $cursor) use ($path, $query, $options): LeadveyorObject {
            $q = $cursor !== null ? array_merge($query, ['starting_after' => $cursor]) : $query;
            $raw = $this->request('GET', $path, query: $q, options: $options);
            if (!$raw instanceof LeadveyorObject) {
                throw new LeadveyorException('Unexpected response for a list: ' . get_debug_type($raw));
            }
            return $raw;
        };
        $start = isset($query['starting_after']) && is_string($query['starting_after']) ? $query['starting_after'] : null;
        return new Page($fetch, $itemClass, $fetch($start));
    }

    /**
     * Turns `['limit' => 10, 'wait' => true, 'skip' => null]` into `?limit=10&wait=true`.
     *
     * @param array<string, mixed>|null $query
     */
    public static function buildQuery(?array $query): string
    {
        $parts = [];
        foreach ($query ?? [] as $k => $v) {
            if ($v === null) {
                continue;
            }
            $s = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode($s);
        }
        return $parts ? '?' . implode('&', $parts) : '';
    }

    /** Exponential backoff with jitter (0.5 s, 1 s, 2 s … capped at 8 s); `Retry-After` wins when present. In seconds. */
    public static function retryDelay(int $attempt, ?string $retryAfter): float
    {
        if ($retryAfter !== null && $retryAfter !== '') {
            if (is_numeric($retryAfter) && (float) $retryAfter >= 0) {
                return min((float) $retryAfter, 60.0);
            }
            $date = strtotime($retryAfter);
            if ($date !== false) {
                return min(max($date - time(), 0), 60);
            }
        }
        $base = min(0.5 * (2 ** $attempt), 8.0);
        return $base * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
    }

    /** @param array<mixed>|object $body */
    private static function encodeBody(array|object $body): string
    {
        // An empty PHP array is an empty JSON object here: every request body is an object.
        if ($body === []) {
            return '{}';
        }
        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** JSON as objects (stdClass keeps `{}` apart from `[]`); other text as a string; empty → null. */
    private static function parseBody(string $text, ?string $contentType): mixed
    {
        if ($text === '') {
            return null;
        }
        $trimmed = ltrim($text);
        if (str_contains((string) $contentType, 'json') || ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '['))) {
            try {
                return json_decode($text, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                return $text;
            }
        }
        return $text;
    }

    private static function env(string $name): ?string
    {
        $v = getenv($name);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }
}
