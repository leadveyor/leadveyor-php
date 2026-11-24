<?php

declare(strict_types=1);

namespace Leadveyor\HttpClient;

final class Response
{
    /** @var array<string, string> lower-case names */
    public readonly array $headers;

    /** @param array<string, string> $headers any case; stored lower-case */
    public function __construct(public readonly int $status, public readonly string $body = '', array $headers = [])
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = $v;
        }
        $this->headers = $h;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
