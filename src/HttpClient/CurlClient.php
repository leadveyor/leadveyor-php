<?php

declare(strict_types=1);

namespace Leadveyor\HttpClient;

/** Default transport (ext-curl). */
final class CurlClient implements HttpClientInterface
{
    /** @param array<int, mixed> $curlOptions extra CURLOPT_* values (proxy, CA bundle…) */
    public function __construct(private readonly array $curlOptions = [])
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        // No "Expect: 100-continue" round trip for larger bodies.
        $lines[] = 'Expect:';
        $responseHeaders = [];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(30_000, $timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $i = strpos($line, ':');
                if ($i !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $i)))] = trim(substr($line, $i + 1));
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (after a 100 Continue): forget the previous headers
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $this->curlOptions + $opts);

        $text = curl_exec($ch);
        if ($text === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            throw new TransportException($error !== '' ? $error : "curl error $errno", $errno === CURLE_OPERATION_TIMEDOUT);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new Response($status, (string) $text, $responseHeaders);
    }
}
