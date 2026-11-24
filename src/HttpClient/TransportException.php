<?php

declare(strict_types=1);

namespace Leadveyor\HttpClient;

/** Thrown by an HttpClientInterface when no HTTP response came back. The client turns it into ConnectionException / TimeoutException. */
final class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
