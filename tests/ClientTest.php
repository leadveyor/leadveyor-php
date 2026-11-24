<?php

declare(strict_types=1);

namespace Leadveyor\Tests;

use Leadveyor\Exception\ApiException;
use Leadveyor\Exception\AuthenticationException;
use Leadveyor\Exception\ConnectionException;
use Leadveyor\Exception\InvalidRequestException;
use Leadveyor\Exception\LeadveyorException;
use Leadveyor\Exception\NotFoundException;
use Leadveyor\Exception\QuotaExceededException;
use Leadveyor\Exception\RateLimitException;
use Leadveyor\Exception\TimeoutException;
use Leadveyor\HttpClient\Response;
use Leadveyor\HttpClient\TransportException;
use Leadveyor\Leadveyor;
use Leadveyor\LeadveyorObject;
use Leadveyor\Model\Evaluation;
use Leadveyor\Model\InboundResult;
use Leadveyor\Model\Lead;
use Leadveyor\Page;
use Leadveyor\Version;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** @var list<float> */
    private array $slept = [];

    private function lv(FakeHttpClient $http, array $extra = []): Leadveyor
    {
        return new Leadveyor('lv_test_abc', $extra + [
            'httpClient' => $http,
            'baseUrl' => 'https://api.test/',
            'sleep' => function (float $s): void {
                $this->slept[] = $s;
            },
        ]);
    }

    private static function page(array $ids, ?string $next): array
    {
        return ['object' => 'list', 'data' => array_map(fn ($id) => ['object' => 'lead', 'id' => $id], $ids), 'has_more' => $next !== null, 'next_cursor' => $next];
    }

    private static function catch(callable $fn): LeadveyorException
    {
        try {
            $fn();
        } catch (LeadveyorException $e) {
            return $e;
        }
        self::fail('Expected an exception');
    }

    public function testSendsAuthWorkspaceAndJsonAndBuildsPathsAndQueries(): void
    {
        $f = new FakeHttpClient(fn () => FakeHttpClient::json(200, ['object' => 'lead', 'id' => 'lead_1']));
        $c = $this->lv($f, ['workspace' => 'ws_1']);
        $c->leads->retrieve('lead/1?x');
        $c->inbounds->create(['text' => 'Hi'], ['wait' => true], ['workspace' => 'ws_2']);
        $c->leads->list(['tier' => 'hot', 'min_score' => 50, 'limit' => 10, 'source' => null]);
        $c->sources->update('src_1', []);

        self::assertSame('https://api.test/v1/leads/lead%2F1%3Fx', $f->calls[0]['url']);
        self::assertSame('GET', $f->calls[0]['method']);
        self::assertSame('Bearer lv_test_abc', $f->calls[0]['headers']['authorization']);
        self::assertSame('ws_1', $f->calls[0]['headers']['leadveyor-workspace']);
        self::assertStringStartsWith('leadveyor-sdk-php/' . Version::VERSION . ' PHP/', $f->calls[0]['headers']['user-agent']);
        self::assertArrayNotHasKey('content-type', $f->calls[0]['headers']);
        self::assertNull($f->calls[0]['body']);

        self::assertSame('POST', $f->calls[1]['method']);
        self::assertSame('https://api.test/v1/inbounds?wait=true', $f->calls[1]['url']);
        self::assertSame('ws_2', $f->calls[1]['headers']['leadveyor-workspace']);
        self::assertSame('application/json', $f->calls[1]['headers']['content-type']);
        self::assertSame('{"text":"Hi"}', $f->calls[1]['body']);

        self::assertSame('https://api.test/v1/leads?tier=hot&min_score=50&limit=10', $f->calls[2]['url']);
        // An empty array body is an empty JSON object.
        self::assertSame('PATCH', $f->calls[3]['method']);
        self::assertSame('{}', $f->calls[3]['body']);
    }

    public function testResponsesBecomeTypedObjects(): void
    {
        $f = new FakeHttpClient(fn () => FakeHttpClient::json(200, [
            'object' => 'inbound', 'id' => 'inb_1', 'metadata' => new \stdClass(),
            'leads' => [['object' => 'lead', 'id' => 'lead_1', 'evaluation' => ['tier' => 'hot', 'score' => 90, 'reasons' => []], 'fields' => ['guests' => 80]]],
        ]));
        $r = $this->lv($f)->inbounds->create(['text' => 'Hi'], ['wait' => true]);

        self::assertInstanceOf(InboundResult::class, $r);
        self::assertInstanceOf(Lead::class, $r->leads[0]);
        self::assertInstanceOf(Evaluation::class, $r->leads[0]->evaluation);
        self::assertSame('hot', $r->leads[0]->evaluation->tier);
        self::assertSame(90, $r['leads'][0]['evaluation']['score']);
        self::assertSame(80, $r->leads[0]->fields->guests);
        self::assertNull($r->nope);
        self::assertSame([], $r->leads[0]->evaluation->reasons);
        // {} stays an object, [] stays a list
        self::assertSame('{"object":"inbound","id":"inb_1","metadata":{},"leads":[{"object":"lead","id":"lead_1","evaluation":{"tier":"hot","score":90,"reasons":[]},"fields":{"guests":80}}]}', json_encode($r));
        self::assertSame(['tier' => 'hot', 'score' => 90, 'reasons' => []], $r->leads[0]->evaluation->toArray());
    }

    public function testWithWorkspaceAndWithOptions(): void
    {
        $f = new FakeHttpClient(fn () => FakeHttpClient::json(200, ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null]));
        $base = $this->lv($f);
        $base->withWorkspace('ws_9')->leads->list();
        $base->leads->list();
        self::assertSame('ws_9', $f->calls[0]['headers']['leadveyor-workspace']);
        self::assertArrayNotHasKey('leadveyor-workspace', $f->calls[1]['headers']);
        self::assertInstanceOf(Leadveyor::class, $base->withOptions(['timeout' => 5]));
        $base->withOptions(['timeout' => 5])->leads->list();
        self::assertSame(5.0, $f->calls[2]['timeout']);
        $base->leads->list([], ['timeout' => 2]);
        self::assertSame(2.0, $f->calls[3]['timeout']);
        self::assertFalse($base->livemode());
    }

    public function testNeedsAnApiKey(): void
    {
        $old = getenv('LEADVEYOR_API_KEY');
        putenv('LEADVEYOR_API_KEY');
        try {
            $e = self::catch(fn () => new Leadveyor());
            self::assertInstanceOf(AuthenticationException::class, $e);
            self::assertStringContainsString('No API key', $e->getMessage());
            putenv('LEADVEYOR_API_KEY=lv_live_x');
            self::assertTrue((new Leadveyor())->livemode());
        } finally {
            putenv($old === false ? 'LEADVEYOR_API_KEY' : "LEADVEYOR_API_KEY=$old");
        }
    }

    public function testMapsTheErrorEnvelopeToTypedExceptions(): void
    {
        $f = new FakeHttpClient(fn () => FakeHttpClient::json(400, ['error' => [
            'type' => 'invalid_request_error', 'code' => 'profile_invalid', 'param' => 'criteria', 'message' => 'Bad profile',
            'request_id' => 'req_1', 'errors' => [['path' => 'criteria.0', 'message' => 'Required']],
        ]]));
        $e = self::catch(fn () => $this->lv($f)->profiles->validate(['document' => []]));
        self::assertInstanceOf(InvalidRequestException::class, $e);
        self::assertSame(400, $e->httpStatus);
        self::assertSame('invalid_request_error', $e->type);
        self::assertSame('profile_invalid', $e->errorCode);
        self::assertSame('criteria', $e->param);
        self::assertSame('req_1', $e->requestId);
        self::assertSame('Bad profile', $e->getMessage());
        self::assertSame([['path' => 'criteria.0', 'message' => 'Required']], $e->errors);
    }

    public function testStatusClassesAndNonJson5xx(): void
    {
        $f = new FakeHttpClient(fn (int $n) => match ($n) {
            1 => FakeHttpClient::json(401, ['error' => ['type' => 'authentication_error', 'message' => 'Invalid key']]),
            2 => FakeHttpClient::json(404, ['error' => ['type' => 'not_found_error', 'message' => 'No such lead']]),
            default => new Response(502, '<html>Bad gateway</html>', ['Content-Type' => 'text/html', 'X-Request-Id' => 'req_px']),
        });
        $c = $this->lv($f, ['maxRetries' => 0]);
        self::assertInstanceOf(AuthenticationException::class, self::catch(fn () => $c->leads->retrieve('x')));
        self::assertInstanceOf(NotFoundException::class, self::catch(fn () => $c->leads->retrieve('x')));
        $e = self::catch(fn () => $c->leads->retrieve('x'));
        self::assertInstanceOf(ApiException::class, $e);
        self::assertStringContainsString('HTTP 502', $e->getMessage());
        self::assertSame('req_px', $e->requestId);
    }

    public function testRetriesGetOn5xxAnd429HonouringRetryAfter(): void
    {
        $f = new FakeHttpClient(fn (int $n) => match ($n) {
            1 => FakeHttpClient::json(503, ['error' => ['message' => 'busy']]),
            2 => FakeHttpClient::json(429, ['error' => ['message' => 'slow down']], ['Retry-After' => '3']),
            default => FakeHttpClient::json(200, ['id' => 'lead_1']),
        });
        $lead = $this->lv($f)->leads->retrieve('lead_1');
        self::assertSame('lead_1', $lead->id);
        self::assertCount(3, $f->calls);
        self::assertSame(3.0, $this->slept[1]);
        self::assertGreaterThan(0, $this->slept[0]);
    }

    public function testPostIsRetriedOn429OnlyNeverOn5xx(): void
    {
        $f500 = new FakeHttpClient(fn () => FakeHttpClient::json(500, ['error' => ['message' => 'oops']]));
        self::assertInstanceOf(ApiException::class, self::catch(fn () => $this->lv($f500)->inbounds->create(['text' => 'x'])));
        self::assertCount(1, $f500->calls);

        $f429 = new FakeHttpClient(fn (int $n) => $n < 3 ? FakeHttpClient::json(429, ['error' => ['message' => 'x']], ['Retry-After' => '0']) : FakeHttpClient::json(202, ['object' => 'inbound_result']));
        self::assertSame('inbound_result', $this->lv($f429)->inbounds->create(['text' => 'x'])->object);
        self::assertCount(3, $f429->calls);
    }

    public function testSafePostsAreRetriedOn5xx(): void
    {
        $f = new FakeHttpClient(fn (int $n) => $n === 1 ? FakeHttpClient::json(500, []) : FakeHttpClient::json(200, ['object' => 'inbound']));
        self::assertSame('inbound', $this->lv($f)->inbounds->create(['text' => 'x', 'externalId' => 'form-1'])->object);
        self::assertCount(2, $f->calls);

        $g = new FakeHttpClient(fn (int $n) => $n === 1 ? FakeHttpClient::json(503, []) : FakeHttpClient::json(200, ['object' => 'evaluation_result']));
        $this->lv($g)->evaluate(['text' => 'x']);
        self::assertCount(2, $g->calls);
    }

    public function testQuotaExceededIsNotRetried(): void
    {
        $f = new FakeHttpClient(fn () => FakeHttpClient::json(402, ['error' => ['type' => 'quota_error', 'code' => 'quota_exceeded', 'message' => 'Plan limit reached']]));
        $e = self::catch(fn () => $this->lv($f)->leads->list());
        self::assertInstanceOf(QuotaExceededException::class, $e);
        self::assertSame('quota_error', $e->type);
        self::assertSame('quota_exceeded', $e->errorCode);
        self::assertCount(1, $f->calls);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $f = new FakeHttpClient(fn () => FakeHttpClient::json(429, ['error' => ['message' => 'x']], ['Retry-After' => '0']));
        self::assertInstanceOf(RateLimitException::class, self::catch(fn () => $this->lv($f, ['maxRetries' => 1])->leads->retrieve('x')));
        self::assertCount(2, $f->calls);
    }

    public function testNetworkErrorsAndTimeouts(): void
    {
        $f = new FakeHttpClient(fn (int $n) => $n === 1 ? throw new TransportException('Connection reset') : FakeHttpClient::json(200, ['id' => 'ok']));
        self::assertSame('ok', $this->lv($f)->leads->retrieve('x')->id);
        self::assertCount(2, $f->calls);

        $g = new FakeHttpClient(fn () => throw new TransportException('Connection reset'));
        $e = self::catch(fn () => $this->lv($g)->inbounds->create(['text' => 'x']));
        self::assertInstanceOf(ConnectionException::class, $e);
        self::assertNotInstanceOf(TimeoutException::class, $e);
        self::assertStringContainsString('Connection reset', $e->getMessage());
        self::assertCount(1, $g->calls);

        $t = new FakeHttpClient(fn () => throw new TransportException('Operation timed out', timedOut: true));
        $e = self::catch(fn () => $this->lv($t, ['maxRetries' => 0])->leads->retrieve('x'));
        self::assertInstanceOf(TimeoutException::class, $e);
        self::assertSame('connection_error', $e->type);
    }

    public function testEmptyResponseIsNull(): void
    {
        $f = new FakeHttpClient(fn () => new Response(204));
        self::assertNull($this->lv($f)->request('DELETE', '/v1/x'));
    }

    public function testPagination(): void
    {
        $f = new FakeHttpClient(function (int $n, array $call) {
            parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $q);
            return match ($q['starting_after'] ?? null) {
                null => FakeHttpClient::json(200, self::page(['a', 'b'], 'b')),
                'b' => FakeHttpClient::json(200, self::page(['c', 'd'], 'd')),
                default => FakeHttpClient::json(200, self::page(['e'], null)),
            };
        });
        $c = $this->lv($f);
        $first = $c->leads->list(['limit' => 2]);
        self::assertInstanceOf(Page::class, $first);
        self::assertSame(['a', 'b'], array_map(fn ($x) => $x->id, $first->data));
        self::assertInstanceOf(Lead::class, $first->data[0]);
        self::assertTrue($first->hasMore);
        self::assertSame('b', $first->nextCursor);
        self::assertCount(1, $f->calls);

        $ids = [];
        foreach ($c->leads->list(['limit' => 2]) as $lead) {
            $ids[] = $lead->id;
        }
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $ids);
        self::assertSame([
            'https://api.test/v1/leads?limit=2',
            'https://api.test/v1/leads?limit=2&starting_after=b',
            'https://api.test/v1/leads?limit=2&starting_after=d',
        ], array_map(fn ($x) => $x['url'], array_slice($f->calls, 1)));

        self::assertSame(['a', 'b', 'c'], array_map(fn ($x) => $x->id, $c->leads->list()->toArray(3)));
        self::assertNull($c->leads->list(['starting_after' => 'd'])->nextPage());
    }

    public function testListStartsAfterAGivenCursor(): void
    {
        $f = new FakeHttpClient(fn () => FakeHttpClient::json(200, self::page([], null)));
        $this->lv($f)->events->list(['starting_after' => 'evt_5']);
        self::assertSame('https://api.test/v1/events?starting_after=evt_5', $f->calls[0]['url']);
    }

    public function testVersionIsSemver(): void
    {
        // Packagist takes the version from the git tag (vX.Y.Z); keep Version::VERSION equal to it.
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::VERSION);
    }

    public function testEveryServiceMethodExists(): void
    {
        $c = new Leadveyor('lv_test_x');
        foreach (['inbounds' => 'create', 'leads' => 'recordOutcome', 'profiles' => 'generateCriterion', 'reports' => 'compareWorkspaces', 'apiKeys' => 'revoke', 'billing' => 'setSpendLimit', 'usage' => 'retrieve'] as $res => $m) {
            self::assertTrue(method_exists($c->{$res}, $m), "$res->$m");
        }
        self::assertTrue(method_exists($c, 'evaluate'));
        self::assertInstanceOf(LeadveyorObject::class, new Lead());
    }
}
