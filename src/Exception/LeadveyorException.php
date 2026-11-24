<?php

declare(strict_types=1);

namespace Leadveyor\Exception;

/**
 * Base class of every exception the SDK throws. API errors carry the fields of the error envelope
 * (`type`, `code`, `param`, `errors`, `request_id`); see https://leadveyor.com/docs/errors.
 */
class LeadveyorException extends \Exception
{
    /**
     * @param int|null $httpStatus HTTP status; null for connection problems and timeouts.
     * @param string $type `invalid_request_error`, `authentication_error`, `permission_error`, `not_found_error`,
     *   `conflict_error`, `rate_limit_error`, `quota_error`, `api_error`, or `connection_error`.
     * @param string|null $errorCode Machine-readable reason, e.g. `profile_invalid`, `insufficient_permission`.
     * @param string|null $param The request field the error is about.
     * @param list<array{path: string, message: string}> $errors All field-level problems (profile validation).
     * @param string|null $requestId Quote this when contacting support.
     * @param array<string, string> $headers Response headers, lower-case names.
     * @param mixed $raw The parsed response body.
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly string $type = 'api_error',
        public readonly ?string $errorCode = null,
        public readonly ?string $param = null,
        public readonly array $errors = [],
        public readonly ?string $requestId = null,
        public readonly array $headers = [],
        public readonly mixed $raw = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    private const BY_STATUS = [
        400 => InvalidRequestException::class,
        401 => AuthenticationException::class,
        402 => QuotaExceededException::class,
        403 => PermissionException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        429 => RateLimitException::class,
    ];

    /**
     * Builds the right exception class from a non-2xx response. Works for non-JSON bodies too (proxy errors).
     *
     * @internal
     * @param array<string, string> $headers lower-case names
     */
    public static function fromResponse(int $status, mixed $body, array $headers): self
    {
        $env = ($body instanceof \stdClass && isset($body->error) && $body->error instanceof \stdClass) ? $body->error : null;
        $str = static fn (mixed $v): ?string => is_string($v) ? $v : null;
        $cls = self::BY_STATUS[$status] ?? ($status >= 500 ? ApiException::class : self::class);
        $fallbackType = $status >= 500 ? 'api_error' : ($status === 429 ? 'rate_limit_error' : 'invalid_request_error');
        $message = $str($env->message ?? null)
            ?? (is_string($body) && $body !== '' ? "HTTP $status: " . substr($body, 0, 200) : "HTTP $status");
        $errors = [];
        if (isset($env->errors) && is_array($env->errors)) {
            foreach ($env->errors as $e) {
                if ($e instanceof \stdClass) {
                    $errors[] = ['path' => (string) ($e->path ?? ''), 'message' => (string) ($e->message ?? '')];
                }
            }
        }
        return new $cls(
            $message,
            httpStatus: $status,
            type: $str($env->type ?? null) ?? $fallbackType,
            errorCode: $str($env->code ?? null),
            param: $str($env->param ?? null),
            errors: $errors,
            requestId: $str($env->request_id ?? null) ?? ($headers['x-request-id'] ?? null),
            headers: $headers,
            raw: $body,
        );
    }
}
