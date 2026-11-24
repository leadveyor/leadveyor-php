<?php

declare(strict_types=1);

namespace Leadveyor;

use Leadveyor\Exception\SignatureVerificationException;

/**
 * Checks the `Leadveyor-Signature` header of a webhook request. Docs: https://leadveyor.com/docs/webhooks
 *
 * ```php
 * $event = \Leadveyor\Webhook::constructEvent(
 *     file_get_contents('php://input'),              // the raw body
 *     $_SERVER['HTTP_LEADVEYOR_SIGNATURE'] ?? '',
 *     getenv('LEADVEYOR_WEBHOOK_SECRET')             // whsec_…
 * );
 * if ($event->type === 'lead.scored') {
 *     echo $event->data->object->evaluation->tier;   // data.object is a Model\Lead
 * }
 * ```
 */
final class Webhook
{
    /** Default allowed clock difference between Leadveyor and your server, in seconds. Same as the server's. */
    public const DEFAULT_TOLERANCE = 300;
    public const SIGNATURE_HEADER = 'Leadveyor-Signature';

    /**
     * Verifies the signature and returns the event. `data->object` is a Model\Lead for `lead.*` events and a
     * Model\Inbound for `inbound.*` events. Throws SignatureVerificationException when it does not match.
     *
     * @param string $payload the raw request body, exactly as received — not decoded and re-encoded JSON
     * @param int|null $now for tests: "now" in unix seconds
     */
    public static function constructEvent(string $payload, ?string $signatureHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Model\Event
    {
        if ($secret === '') {
            throw self::fail('missing_secret', 'No signing secret. It starts with whsec_ and is shown when the destination is created.');
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            throw self::fail('missing_signature', 'No Leadveyor-Signature header on the request.');
        }
        [$t, $v1] = self::parseHeader($signatureHeader);
        if ($t === null || !$v1) {
            throw self::fail('malformed_signature', 'Leadveyor-Signature header is malformed (expected t=…,v1=…).');
        }
        $now ??= time();
        if (abs($now - $t) > $tolerance) {
            throw self::fail('timestamp_out_of_range', "Signature timestamp is more than $tolerance s away from now (replay, or the server clock is off).");
        }
        $expected = hash_hmac('sha256', "$t.$payload", $secret);
        $ok = false;
        foreach ($v1 as $sig) {
            $ok = $ok || hash_equals($expected, $sig);
        }
        if (!$ok) {
            throw self::fail('signature_mismatch', 'Signature does not match. Check the secret, and that the body is the raw, unmodified request body.');
        }
        try {
            $data = json_decode($payload, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw self::fail('invalid_json', 'Webhook body is not valid JSON.', $e);
        }
        if (!$data instanceof \stdClass) {
            throw self::fail('invalid_json', 'Webhook body is not a JSON object.');
        }
        $event = Model\Event::constructFrom($data);
        $type = isset($data->type) && is_string($data->type) ? $data->type : '';
        $object = $data->data->object ?? null;
        $eventData = $event['data'];
        if ($object instanceof \stdClass && $eventData instanceof LeadveyorObject) {
            if (str_starts_with($type, 'lead.')) {
                $eventData['object'] = Model\Lead::constructFrom($object);
            } elseif (str_starts_with($type, 'inbound.')) {
                $eventData['object'] = Model\Inbound::constructFrom($object);
            }
        }
        return $event;
    }

    /** Same check as constructEvent(), as a boolean. */
    public static function verify(string $payload, ?string $signatureHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        try {
            self::constructEvent($payload, $signatureHeader, $secret, $tolerance, $now);
            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    /** Signs a payload like Leadveyor does — for testing your webhook handler. */
    public static function generateTestHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        return "t=$timestamp,v1=" . hash_hmac('sha256', "$timestamp.$payload", $secret);
    }

    /** @return array{0: int|null, 1: list<string>} `t=<unix>,v1=<hex>` (several `v1` allowed). */
    private static function parseHeader(string $header): array
    {
        $t = null;
        $v1 = [];
        foreach (explode(',', $header) as $part) {
            $i = strpos($part, '=');
            if ($i === false) {
                continue;
            }
            $k = trim(substr($part, 0, $i));
            $v = trim(substr($part, $i + 1));
            if ($k === 't' && ctype_digit($v)) {
                $t = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $v1[] = $v;
            }
        }
        return [$t, $v1];
    }

    private static function fail(string $code, string $message, ?\Throwable $previous = null): SignatureVerificationException
    {
        return new SignatureVerificationException($message, type: 'invalid_request_error', errorCode: $code, previous: $previous);
    }
}
