<?php

declare(strict_types=1);

namespace Leadveyor\Tests;

use Leadveyor\Exception\SignatureVerificationException;
use Leadveyor\Model\Event;
use Leadveyor\Model\Lead;
use Leadveyor\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    /** Signed by the server's own signWebhook() in @leadveyor/core. */
    private const BODY = '{"object":"event","id":"evt_1","type":"lead.scored","livemode":false,"workspaceId":"ws_1","createdAt":"2026-11-23T00:00:00Z","data":{"object":{"object":"lead","id":"lead_1","evaluation":{"tier":"hot","score":90}}}}';
    private const SECRET = 'whsec_test123';
    private const T = 1795000000;
    private const HEADER = 't=1795000000,v1=2f6536601191dcc9c22c74a425c157609577065c2fc6e3dc1ae6df37f0682da3';

    public function testAcceptsTheServersOwnSignature(): void
    {
        $event = Webhook::constructEvent(self::BODY, self::HEADER, self::SECRET, now: self::T + 10);
        self::assertInstanceOf(Event::class, $event);
        self::assertSame('lead.scored', $event->type);
        self::assertInstanceOf(Lead::class, $event->data->object);
        self::assertSame('lead_1', $event->data->object->id);
        self::assertSame('hot', $event->data->object->evaluation->tier);
        self::assertTrue(Webhook::verify(self::BODY, self::HEADER, self::SECRET, now: self::T));
    }

    public function testRejectsBadSignatures(): void
    {
        $code = function (callable $fn): ?string {
            try {
                $fn();
            } catch (SignatureVerificationException $e) {
                return $e->errorCode;
            }
            return null;
        };
        $now = self::T;
        self::assertSame('signature_mismatch', $code(fn () => Webhook::constructEvent(self::BODY, self::HEADER, 'whsec_other', now: $now)));
        self::assertSame('signature_mismatch', $code(fn () => Webhook::constructEvent(str_replace('lead_1', 'lead_2', self::BODY), self::HEADER, self::SECRET, now: $now)));
        self::assertSame('timestamp_out_of_range', $code(fn () => Webhook::constructEvent(self::BODY, self::HEADER, self::SECRET, now: $now + 600)));
        self::assertSame('missing_signature', $code(fn () => Webhook::constructEvent(self::BODY, null, self::SECRET)));
        self::assertSame('malformed_signature', $code(fn () => Webhook::constructEvent(self::BODY, 'garbage', self::SECRET)));
        self::assertSame('missing_secret', $code(fn () => Webhook::constructEvent(self::BODY, self::HEADER, '')));
        self::assertFalse(Webhook::verify(self::BODY, self::HEADER, 'whsec_other', now: $now));
    }

    public function testGenerateTestHeaderMatchesTheServer(): void
    {
        self::assertSame(self::HEADER, Webhook::generateTestHeader(self::BODY, self::SECRET, self::T));
        $h = Webhook::generateTestHeader(self::BODY, self::SECRET);
        self::assertTrue(Webhook::verify(self::BODY, $h, self::SECRET));
    }

    public function testSeveralV1SignaturesDuringSecretRotation(): void
    {
        $other = Webhook::generateTestHeader(self::BODY, 'whsec_old', self::T);
        $header = $other . ',' . explode(',', self::HEADER)[1];
        self::assertTrue(Webhook::verify(self::BODY, $header, self::SECRET, now: self::T));
    }
}
