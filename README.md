# Leadveyor SDK for PHP

[![Packagist](https://img.shields.io/packagist/v/leadveyor/leadveyor-php)](https://packagist.org/packages/leadveyor/leadveyor-php) [![Tests](https://github.com/leadveyor/leadveyor-php/actions/workflows/tests.yml/badge.svg)](https://github.com/leadveyor/leadveyor-php/actions/workflows/tests.yml)

Official client for the [Leadveyor API](https://leadveyor.com/docs/api): send messages, get scored leads, manage profiles, sources and webhooks. PHP 8.1+, no dependencies besides `ext-curl` and `ext-json`.

```bash
composer require leadveyor/leadveyor-php
```

## Quick start

```php
<?php
require 'vendor/autoload.php';

$lv = new \Leadveyor\Leadveyor(getenv('LEADVEYOR_API_KEY')); // lv_live_… or lv_test_…

$inbound = $lv->inbounds->create(
    ['text' => 'Hi, we need catering for 80 guests on 12 March in Richmond. Budget around $6k.'],
    ['wait' => true] // wait for scoring instead of getting a webhook later
);
$lead = $inbound->leads[0] ?? null;
echo $lead?->evaluation->tier, ' ', $lead?->evaluation->score;
```

Create API keys in the [console](https://console.leadveyor.com) → API keys. Without an argument the client reads `LEADVEYOR_API_KEY`.

## Resources

Methods are named like the [TypeScript SDK](https://leadveyor.com/docs/sdk). Arguments: path IDs, then the request body (an array), then query parameters, then per-request options.

| | Methods |
|---|---|
| `$lv->inbounds` | `create`, `list`, `retrieve`, `reprocess` |
| `$lv->leads` | `list`, `retrieve`, `listEvaluations`, `recordOutcome`, `clearOutcome` |
| `$lv->contacts` | `list`, `retrieve`, `update`, `listLeads` |
| `$lv->profiles` | `list`, `create`, `retrieve`, `delete`, `validate`, `generate`, `generateCriterion`, `saveDraft`, `updateDraft`, `discardDraft`, `publish`, `activate`, `listVersions`, `retrieveVersion` |
| `$lv->templates` | `list`, `retrieve` |
| `$lv->sources` | `list`, `create`, `retrieve`, `update`, `delete` |
| `$lv->destinations` | `list`, `create`, `retrieve`, `update`, `delete`, `rotateSecret`, `test`, `listDeliveries`, `retryDelivery` |
| `$lv->events` | `list`, `retrieve` |
| `$lv->workspaces` | `list`, `create`, `retrieve`, `update` |
| `$lv->apiKeys` | `list`, `create`, `revoke` |
| `$lv->billing` | `retrieve`, `checkout`, `changePlan`, `portal`, `setSpendLimit` |
| `$lv->usage` | `retrieve` — this period's leads against the plan |
| `$lv->reports` | `retrieve`, `compareWorkspaces` |
| `$lv->evaluate()` | Score a message without saving anything |

```php
// Record what happened to a lead — this is what teaches scoring what "good" means
$lv->leads->recordOutcome('lead_2Ph6Tc9Ks4Wm1Lx7Qv3Nb8Dr', ['status' => 'won', 'amount' => 6200]);

// Send a form submission; safe to repeat with the same externalId
$lv->inbounds->create([
    'fields' => ['name' => 'Ann Lee', 'email' => 'ann@example.com', 'guests' => 80],
    'externalId' => 'form-4711',
]);
```

## Responses

Responses are objects. Read fields as properties or as array keys; a missing field reads as `null`:

```php
$lead = $lv->leads->retrieve('lead_…');   // \Leadveyor\Model\Lead
$lead->evaluation->tier;                  // 'hot'
$lead['evaluation']['score'];             // 90
$lead->toArray();                         // plain PHP arrays
json_encode($lead);                       // the API's JSON
```

Every object has a class in `Leadveyor\Model` (`Lead`, `Inbound`, `Profile`, `Source` …) with `@property` docs, so the editor completes field names.

## Pagination

List methods return a `\Leadveyor\Page`:

```php
// This page
$page = $lv->leads->list(['tier' => 'hot', 'limit' => 50]);
$page->data; $page->hasMore; $page->nextCursor;

// Every item; next pages are fetched as you go
foreach ($lv->leads->list(['outcome' => 'none']) as $lead) {
    echo $lead->id, ' ', $lead->evaluation->score, "\n";
}

// Up to N items as an array
$recent = $lv->events->list(['type' => 'lead.*'])->toArray(500);
```

## Webhooks

Verify the `Leadveyor-Signature` header with the **raw** request body:

```php
$payload = file_get_contents('php://input');
try {
    $event = \Leadveyor\Webhook::constructEvent(
        $payload,
        $_SERVER['HTTP_LEADVEYOR_SIGNATURE'] ?? null,
        getenv('LEADVEYOR_WEBHOOK_SECRET') // whsec_…
    );
} catch (\Leadveyor\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

switch ($event->type) {
    case 'lead.scored':
        saveLead($event->data->object); // \Leadveyor\Model\Lead
        break;
    case 'lead.outcome_recorded':
        echo $event->data->object->outcome->status, ' was ', $event->data->previous->outcome?->status;
        break;
}
http_response_code(200);
```

Laravel: `Webhook::constructEvent($request->getContent(), $request->header('Leadveyor-Signature'), $secret)`. Symfony: `$request->getContent()` and `$request->headers->get('Leadveyor-Signature')`.

`Webhook::verify(...)` returns a boolean instead of throwing. `Webhook::generateTestHeader($payload, $secret)` signs a payload like Leadveyor does, for your tests.

## Workspaces

A workspace key works in its own workspace. With an account-wide key (agencies, several clients) choose the workspace:

```php
$client = $lv->withWorkspace('ws_1Bq7Xn4KpRz9Lc2Vt6Hy0Mde');   // every request
$lv->leads->list([], ['workspace' => 'ws_…']);                // one request
```

## Errors

Failed calls throw a subclass of `\Leadveyor\Exception\LeadveyorException`:

| Class | When |
|---|---|
| `InvalidRequestException` | 400: invalid parameters; `$e->errors` lists every field problem |
| `AuthenticationException` | 401: missing, invalid or revoked key |
| `PermissionException` | 403: the key's permission or workspace doesn't allow it |
| `NotFoundException` | 404 |
| `ConflictException` | 409 |
| `QuotaExceededException` | 402: the plan's leads for this period are used up ([plans and limits](https://leadveyor.com/docs/plans-and-limits)); not retried |
| `RateLimitException` | 429, after the automatic retries |
| `ApiException` | 5xx |
| `ConnectionException` / `TimeoutException` | the API could not be reached / no answer in time |

```php
try {
    $lead = $lv->leads->retrieve('lead_…');
} catch (\Leadveyor\Exception\NotFoundException $e) {
    $lead = null;
}
// $e->httpStatus, $e->type, $e->errorCode, $e->param, $e->requestId (quote it to support)
```

## Retries and timeouts

- 429 responses are retried for every method, honouring `Retry-After`.
- 5xx responses and network errors are retried for `GET`, `PUT`, `PATCH` and `DELETE`, for `evaluate()` and `profiles->validate()`, and for `inbounds->create()` **with an `externalId`** (the API returns the original inbound for a repeated ID). Other `POST`s are not retried on them, because the request may already have been applied.
- Two retries by default with exponential backoff.

```php
$lv = new \Leadveyor\Leadveyor($key, ['maxRetries' => 4, 'timeout' => 30]);
$lv->evaluate(['text' => $text], ['timeout' => 120]);
```

## Options

| Option | Default | |
|---|---|---|
| `apiKey` | `LEADVEYOR_API_KEY` | |
| `workspace` | — | `Leadveyor-Workspace` for account-wide keys |
| `baseUrl` | `https://core.leadveyor.com` | or `LEADVEYOR_BASE_URL` |
| `timeout` | `60` | seconds per attempt |
| `maxRetries` | `2` | |
| `httpClient` | `CurlClient` | your own `HttpClientInterface` (proxies, tests); `new CurlClient([CURLOPT_PROXY => …])` for curl options |
| `defaultHeaders` | — | added to every request |

Per-request options (last argument of every method): `workspace`, `timeout`, `maxRetries`, `headers`.

## Test mode

Keys starting with `lv_test_` work with test data, kept apart from live data (`livemode: false`). `$lv->livemode()` tells which kind of key the client uses.

## Links

- Docs: https://leadveyor.com/docs/sdk/php
- API reference: https://leadveyor.com/docs/inbounds
- Support: support@leadveyor.com
