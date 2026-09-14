# Linode API client for PHP

[![Tests](https://github.com/hampel/linode-api/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/linode-api/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/linode-api.svg?style=flat-square)](https://packagist.org/packages/hampel/linode-api)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/linode-api.svg?style=flat-square)](https://packagist.org/packages/hampel/linode-api)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/linode-api.svg?style=flat-square)](https://github.com/hampel/linode-api/issues)
[![License](https://img.shields.io/packagist/l/hampel/linode-api.svg?style=flat-square)](https://packagist.org/packages/hampel/linode-api)

By [Simon Hampel](mailto:simon@hampelgroup.com)

A PHP client for the
[Linode (Akamai Cloud) API](https://techdocs.akamai.com/linode-api/reference/api), built on
**PSR-18**.

It wraps the **DNS endpoints** — zones and records — and the two endpoints that answer *does
this token work, and what may it do*. That is a dozen of Linode's three hundred, deliberately:
the rest are reachable through the same client without waiting for a release, and
[extending it](#extending-it) is one class.

## Installation

```bash
composer require hampel/linode-api
```

You also need a PSR-18 client and a PSR-17 factory. Guzzle provides both, 7 or 8:

```bash
composer require guzzlehttp/guzzle
```

## Usage

```php
use GuzzleHttp\Client as Guzzle;
use Hampel\Linode\Api\Client;
use Hampel\Linode\Api\Entity\DomainRecord;

$linode = Client::withToken('MY-TOKEN', new Guzzle());

$linode->verify();                                    // does this token work?

$zone = $linode->domains()->findByName('example.com');   // null when the account has no such zone

$linode->domains()->records($zone)->create(
    DomainRecord::a('www', '203.0.113.10')->withTtl(300)
);
```

`Client::withToken()` finds a PSR-17 factory for you — Guzzle's, Nyholm's or Diactoros',
whichever is installed. The long form takes everything explicitly, including a PSR-3 logger:

```php
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Linode\Api\Authentication\AccessToken;
use Hampel\Linode\Api\Config;

$factory = new HttpFactory();   // PSR-17, fills both the request and stream roles

$linode = new Client(new Config(), new AccessToken('MY-TOKEN'), new Guzzle(), $factory, $factory, $logger);
```

Requests are logged at `debug`, failures at `error`, and a nearly-spent rate limit at
`warning`. The token is never logged: `AccessToken::describe()` prints its length and last
four characters and nothing else.

## Does this token work?

The question every integration should ask before doing anything, and the API makes it cheap
to answer. `GET /v4/profile` needs **no OAuth scope at all**, so it succeeds for any
credential that is valid and 401s for one that is not — while every other endpoint conflates
*your token is wrong* with *your token may not do this*.

```php
use Hampel\Linode\Api\Exception\NotAuthenticatedException;

try {
    $status = $linode->verify();
} catch (NotAuthenticatedException) {
    // missing, wrong, expired or revoked - Linode answers the same "Invalid Token" for all four
}

$status->username();                       // 'exampleuser'
$status->isRestricted();                   // is this user limited object by object?
$status->allows('domains:read_write');     // read_write also satisfies read_only
$status->missing(['domains:read_write']);  // [] when the token can manage DNS
$status->summary();                        // one line for a startup log; carries no token
```

One request. The scopes come back in the `X-OAuth-Scopes` response header, which Linode sends
on **every** response — so asking what a token may do costs nothing extra.

**A bad token raises rather than returning a status object that says so.** Returning
`$status->valid === false` invites a caller who forgot to check a boolean to carry on as
though everything were fine, and the next thing they do is delete a record.

### Restricted users, and the 204 that means the opposite of what it looks like

A Linode user is either unrestricted — able to do whatever the token's scopes allow — or
restricted to particular objects, with the limits written down in `GET /v4/profile/grants`.

**That endpoint answers `204 No Content` for an unrestricted user.** Read as an ordinary
response, an empty body is an empty grants object, which says the user may do *nothing* — the
exact opposite of the truth. This package returns `null` for it, and `restricted` is what to
branch on:

```php
$profile = $linode->profile()->get();
$grants  = $profile->restricted ? $linode->profile()->grants() : null;

$grants?->canAddDomains();          // account-wide: may this user create new zones?
$grants?->canWriteDomain($id);      // per zone
$grants?->accountAccess();          // 'read_only', 'read_write', or null
```

So there are three answers to "may this token create a zone", not two: unrestricted, or
restricted-and-granted, or refused.

### The account

`GET /v4/account` needs `account:read_only`, which a DNS token will not usually carry — and
that separation is correct rather than a limitation to work around. Use `verify()` to check a
token; use this only when the billing details are the thing you actually want.

```php
$account = $linode->account()->get();     // raises NotPermittedException without the scope
$account = $linode->account()->find();    // null instead, for a diagnostic that reports what it can
```

## Domains

```php
$linode->domains()->list();                       // one page
$linode->domains()->each();                       // every zone, lazily
$linode->domains()->all();                        // every zone, as a list
$linode->domains()->get($id);                     // raises if it is not there
$linode->domains()->find($id);                    // null if it is not there
$linode->domains()->findByName('example.com');    // null if it is not there
$linode->domains()->zoneFile($id);                // what Linode serves, line by line (see below)
```

Creating one:

```php
use Hampel\Linode\Api\Entity\Domain;

$linode->domains()->create(
    Domain::master('example.com', 'hostmaster@example.com')
        ->withTtl(300)
        ->withTags(['production'])
);

$linode->domains()->create(Domain::slave('example.com', ['203.0.113.1']));
```

`findByName()` is the one to reach for on a real account: the name is what you have and the
id is what the API wants. **A domain is unique across the whole of Linode**, not just across
your account, so at most one thing can come back.

**The methods that act on a zone take the zone itself**, so a lookup feeds straight into what
follows — `update()`, `delete()`, `zoneFile()`, `cloneTo()` and `records()` all accept a
`Domain` or an id. `get()` and `find()` take an id only, on purpose: they *produce* a `Domain`,
so passing one in would be a round trip to fetch what you already hold.

```php
$zone = $linode->domains()->findByName('example.com');

if ($zone !== null) {
    $linode->domains()->update($zone, ['ttl_sec' => 300]);
}
```

That is worth more than symmetry. `findByName()` answers `?Domain`, and `Domain::$id` is
`?int` in its own right because a zone built locally has no id — so narrowing away the first
null does not narrow away the second, and `update($zone->id, …)` fails static analysis even
after a correct null check. `$zone->requireId()` is the alternative and this is the shorter
one.

### Updating, and the PUT that is really a PATCH

Every write on this API is a `PUT`, and every one of them applies only the fields it is given.
Sending `{"ttl_sec": 300}` changes the TTL and leaves the zone alone.

**Optional fields on an entity are null until set, and only the ones that are not null are
sent.** So a domain built from scratch sends what you set on it, and one read back from the
API sends everything it has:

```php
$linode->domains()->update($id, ['ttl_sec' => 3600]);                 // one field

$domain = $linode->domains()->get($id);
$linode->domains()->update($domain, $domain->disabled());             // every field, with status changed
```

The smaller request is also the one that cannot overwrite a change somebody else made in
between.

**Disabling is not deleting.** A disabled zone keeps every record and answers none of them,
which makes it the reversible way to take a zone out of service. `delete()` is not reversible
and takes the records with it.

## Records

```php
$records = $linode->domains()->records($zone);   // a Domain or an id; bound, so no id per call

$records->all();
$records->named('www');
$records->ofType(RecordType::MX);
$records->create(DomainRecord::a('www', '203.0.113.10'));
$records->update($recordId, ['ttl_sec' => 300]);
$records->delete($recordId);
```

The same operations are on `$linode->records()` with the zone id as the first argument, for
when a call is on its own.

**Which fields mean anything depends entirely on the type**, and Linode rejects a field that
does not belong to the type being created rather than ignoring it. So there is a named
constructor per type, which takes what that type needs:

```php
DomainRecord::a('www', '203.0.113.10');
DomainRecord::aaaa('www', '2001:db8::1');
DomainRecord::cname('shop', 'shops.example.net');
DomainRecord::ns('ns1.linode.com');
DomainRecord::mx('mail.example.com', priority: 10);
DomainRecord::nullMx();                                    // RFC 7505: this domain takes no mail
DomainRecord::txt('_dmarc', 'v=DMARC1; p=quarantine');
DomainRecord::srv('sip', 'tcp', 'sip.example.com', port: 5060, priority: 10, weight: 5);
DomainRecord::caa(CaaTag::Issue, 'letsencrypt.org');
DomainRecord::ptr('10', 'www.example.com');
```

`name` is **relative to the zone**, not an FQDN: `www` in `example.com` is `www.example.com`,
and an empty name is the zone apex — which is what an MX or a CAA for the domain itself wants.
`$record->fqdn('example.com')` renders the whole thing.

Four traps live in these, and each is why the constructor looks the way it does:

- **SRV takes its service and protocol undecorated.** Linode prepends the underscore itself
  and appends the period, so it is `srv('sip', 'tcp', ...)` and never `'_sip._tcp'` — the
  decorated form becomes `__sip.` and matches nothing, with no error. This package refuses a
  leading underscore rather than passing it through.
- **SRV has no name of its own.** Linode composes it from the service and the protocol, so
  `name` is not sent for one.
- **A record's type cannot be changed.** The field is not in the update schema at all;
  turning an A into a CNAME means deleting and recreating.
- **Nothing stops a duplicate.** Linode will hold two identical A records for one name and
  DNS will serve both. `named()` first, where that matters.

### TTLs are rounded up, silently

Linode accepts a fixed list of intervals — 0, 30, 120, 300, 3600, 7200, 14400, 28800, 57600,
86400, 172800, 345600, 604800, 1209600, 2419200 — and quietly rounds anything else **up** to
the next one, with a 200 and no indication that the stored value is not the one you sent. Ask
for 60 and you get 120; ask for 900 and you get 3600.

The same rule governs a zone's `ttl_sec`, `refresh_sec`, `retry_sec` and `expire_sec` and a
record's `ttl_sec`.

> **This contradicts Linode's own documentation**, which describes a record's `ttl_sec` as
> rounded to the *nearest* valid value off a list starting at 300. Measured against the live
> API on 12 September 2026 by writing each value to a real record and reading back what was
> stored:
>
> | asked | 0 | 1 | 30 | 60 | 120 | 300 | 900 | 3000 | 86401 | 2419201 |
> |---|---|---|---|---|---|---|---|---|---|---|
> | stored | 0 | 30 | 30 | 120 | 120 | 300 | 3600 | 3600 | 172800 | 2419200 |
>
> 30 and 120 *are* accepted for a record, and 900 stores 3600 rather than the 300 "nearest"
> would give. The zone rule was measured in the same run and does match its documentation.

Nothing in this package rewrites your number — a client that quietly changes a value is the
same failure one layer in. `Support\Ttl` reports what a value will become:

```php
Ttl::round(60);                                        // 120
Ttl::round(900);                                       // 3600
Domain::master(...)->withTtl(900)->effectiveTtl();     // 3600
DomainRecord::a('www', '203.0.113.1')->withTtl(900)->effectiveTtl();   // 3600
```

**Zero is not "no caching", and it means different things on a zone and on a record.** Both
were *measured* off the authoritative nameserver:

- On a **zone** it means "use the default", which differs per field: 86400 for a TTL, 14400 for
  refresh and retry, 1209600 for expire.
- On a **record** it **inherits the zone's TTL** — which Linode does not document. So
  `effectiveTtl()` takes the zone to resolve it, and answers `null` without one rather than
  guessing:

```php
$record->effectiveTtl($zone);     // 3600, inherited from the zone
$record->effectiveTtl();          // null - a record does not know its zone
```

Zero is also what every one of these reports until it has been set.

## Filtering and sorting

**Filtering on this API is a request header, not a query string.** There is no
`?domain=example.com`; there is an `X-Filter` header carrying a JSON object.

```php
use Hampel\Linode\Api\Support\Filter;

$linode->domains()->all(Filter::where('tags', 'production')->orderBy('domain'));

$records->all(Filter::anyOf(
    Filter::where('type', 'A'),
    Filter::where('type', 'AAAA'),
));

$linode->domains()->list(1, Filter::make()->contains('domain', 'example')->orderBy('domain', 'desc'));
```

A filter is immutable, so a base filter can be kept in a property and specialised per call.
Every operator Linode defines is a method: `and`, `not`, `contains`, `greaterThan`,
`greaterOrEqual`, `lessThan`, `lessOrEqual`, `orderBy`, and `Filter::anyOf()` for `+or`.

**Only some fields are filterable, and the API decides which.** Naming another is a 400 rather
than an ignored condition — the better failure, but still one. `Domain::FILTERABLE` and
`DomainRecord::FILTERABLE` are the lists.

## Pagination

Every collection answers in the same envelope, and `Page` reads it:

```php
$page = $linode->domains()->list(2);

$page->items;         // this page
count($page);         // how many are on it
$page->total;         // how many there are altogether  ("results")
$page->lastPage;      // the last page number           ("pages")
$page->hasMore();
```

`each()` walks the pages lazily, fetching one only when the previous is consumed — so stopping
early stops making requests. **A walk is a sample, not a snapshot**: each page is its own
request against a collection that can change between them, so order explicitly where it
matters and de-duplicate by id where completeness does.

**Linode refuses a page smaller than 25.** `?page_size=1` is a 400 — a surprise to anyone who
has asked another API for one item to see the shape of it. This package refuses it before
spending a request. The default is 100, the maximum 500, and `new Config(pageSize: 500)` sets
it for every list a client makes.

## Errors

Everything is reported in one envelope — `{"errors": [{"field": "…", "reason": "…"}]}` — and
the **status** is what separates the kinds. The exception hierarchy is that separation, so a
consumer catches what it can act on instead of matching on a message:

| exception | status | what it means |
|---|---|---|
| `ValidationException` | 400 | a value was rejected. `fieldErrors()` is the shape a form wants |
| `NotAuthenticatedException` | 401 | the credential is no good — missing, wrong, expired, revoked |
| `NotPermittedException` | **401** or 403 | the token is real and lacks the scope, or the user lacks the grant |
| `NotFoundException` | 404 | no such record — or no such path; Linode does not distinguish |
| `TooManyRequestsException` | 429 | rate limited, and the only thing that means so |
| `ServerException` | 5xx | Linode failed; a retry is reasonable |
| `MalformedResponseException` | 2xx | success, with a body that is not JSON |
| `RequestException` | — | the request never got an answer: DNS, TLS, a timeout |

All but the last extend `ApiException`; all of them implement `ExceptionInterface`.

```php
use Hampel\Linode\Api\Exception\ValidationException;

try {
    $records->create(DomainRecord::mx('mail.example.com'));
} catch (ValidationException $e) {
    $e->reasons();              // ['Priority must be 0-255']
    $e->fieldErrors();          // ['priority' => ['Priority must be 0-255']]
    $e->concerns('priority');   // true
}
```

### A scope failure is a 401, not a 403

This is the one place the exception type deliberately does not follow the status, and it is
not a preference. Measured on 12 September 2026 with a token holding `domains:read_write` and
nothing else:

```
401  X-OAuth-Scopes: domains:read_write      "Your OAuth token is not authorized
     X-Accepted-OAuth-Scopes: account:read_only     to use this endpoint."

401  X-OAuth-Scopes: unknown                 "Invalid Token"
```

Same status; opposite fixes. The first says *widen the token's scopes*, the second says
*replace the credential*. Collapsing them into one type would leave you matching on the reason
string, which is what this hierarchy exists to prevent.

**`X-OAuth-Scopes` separates them on a fact rather than on prose:** Linode can only report a
token's scopes for a token it recognises. So a 401 naming them becomes `NotPermittedException`
and a 401 saying `unknown` becomes `NotAuthenticatedException`. A stripped header degrades to
the second, which is the conservative reading.

```php
catch (NotPermittedException $e) {
    $e->isScopeFailure();      // true: the token's scopes; false: a restricted user's grants
    $e->requiredScopes();      // account:read_only  - what the endpoint wanted
    $e->heldScopes();          // domains:read_write - what the token has
}
```

The message says so too, because the status contradicts it and the message is what most
people read.

**`MalformedResponseException` is the one worth understanding.** A maintenance page, a proxy's
error document and a truncated body are all a 200 with something other than JSON in it.
Decoded permissively they become an empty array, which reaches the caller as *this account has
no domains* — and code that acts on that answer deletes things. So a 2xx that is not JSON
raises, and it extends `ApiException` so an existing catch still sees it.

## Things about the Linode API worth not rediscovering

Each of these is asserted in `tests/` or exercised in `harness/`, so if one stops being true
something should say so. The ones marked *measured* were read off the live API on 12 September
2026.

- **A `Retry-After` is on every response, including a 200** — *measured*: 60 beside
  `X-RateLimit-Remaining: 1839` on a fresh window, 46 on a later successful call, so it counts
  down to the reset rather than being fixed. Its presence says nothing about whether you were
  throttled; a client that backed off on seeing one would sleep after every call. Only
  `TooManyRequestsException` means you were throttled.
- **An insufficient scope is a 401, not a 403** — *measured*; see above. `X-OAuth-Scopes` is
  what tells it apart from a bad credential.
- **The rate limit is reported on every response** as `X-RateLimit-Limit`,
  `X-RateLimit-Remaining` and `X-RateLimit-Reset` (a unix timestamp, not a duration).
  `$response->meta` carries them, so an integration walking a large account can slow itself
  down rather than wait to be refused. 1840 on an unauthenticated request, *measured*; it
  varies by endpoint, so read it rather than assuming a figure.
- **The deployed API runs ahead of the published specification.** `X-Spec-Version` reported
  4.235.1 live while the specification on GitHub was at 4.215.0 — *measured*. Worth knowing
  before concluding that an endpoint does not exist.
- **A missing token and a made-up one are indistinguishable.** Both answer
  `401 {"errors": [{"reason": "Invalid Token"}]}`, byte for byte — *measured*. Nothing in the
  reply says which of the four causes it was, so no message here pretends to know.
- **`page_size` below 25 is a 400**, `{"field": "page_size", "reason": "Must be 25-500"}` —
  *measured*.
- **An `X-Filter` that is not JSON is a 400** naming `X-Filter` — *measured*. A filter naming
  a field that is not filterable is a 400 too, rather than an ignored condition.
- **Dates carry no timezone and are UTC.** Every `created`, `updated` and `active_since` is
  `2018-01-01T00:01:01` — no `Z`, no offset. `new DateTimeImmutable()` on that reads it in
  PHP's own default timezone, so the same response is a different instant on a box set to
  Australia/Sydney, silently. This package supplies UTC rather than inferring it.
- **A successful DELETE is `{}` with a 200** — `Content-Length: 2`, *measured* — not a 204 and
  not an empty body. So it decodes like any other response.
- **A 204 is the only success with a legitimately empty body**, and that is `GET
  /v4/profile/grants` on an unrestricted user. An empty-bodied **200** raises
  `MalformedResponseException`, because on this API nothing legitimately answers one — see the
  note under *Testing code that uses this*.
- **`GET /v4/profile/grants` is a 204 for an unrestricted user** — see above; it is the trap
  in this API most likely to be read backwards.
- **`GET /v4/profile` needs no scope**, which is what makes it the token check.
- **A zone's SOA and NS records are not records.** Linode generates and serves them without
  representing them in the record endpoint, so a zone that resolves perfectly well can answer
  with an empty list. `zoneFile()` is what shows the whole picture.
- **The zone file lags the record endpoints, by minutes, and is not a read-your-writes
  view** — *measured*. Straight after a change it rendered a TTL two edits old and a record
  that had already been deleted; on another run a change had still not appeared after 160
  seconds. It is authoritative about what is being *served*, which is exactly why it lags; it
  is the wrong thing to diff straight after an edit to confirm the edit landed. Read the
  record endpoint for that.
- **A slave zone's records cannot be written** — they arrive by transfer.
- **`v4beta` is a URL segment**, so pointing at it moves every request a client makes, not
  just the beta ones. That is why it is `$linode->withVersion()` returning a second client
  rather than a flag on a call.
- **Linode's DNS has no SSHFP, TLSA, NAPTR, DNSKEY or DS.** `RecordType` is the complete list,
  and a zone that needs one of those cannot be hosted here whatever this package does.

## Extending it

This package wraps a dozen endpoints. For the rest, two ways past it, neither needing a
release:

```php
$linode->connection()->get('linode/instances')->array('data');   // once
$linode->endpoint(Instances::class)->all();                      // more than once
```

The second is the one to build on. An `Endpoint` subclass gets the pagination helpers, which
work for an endpoint this package has never heard of, because every collection on this API
answers in the same envelope:

```php
use Hampel\Linode\Api\Endpoint\Endpoint;

final class Instances extends Endpoint
{
    /** @return \Generator<int, array<string, mixed>> */
    public function all(): \Generator
    {
        return $this->apiEach('linode/instances', static fn (array $row): array => $row);
    }
}
```

There is nothing to register, no container and no string keys — the class *is* the
registration, and static analysis follows the return type through.
`tests/Fixture/Instances.php` is a worked example with a test behind it.

## Testing code that uses this

**Fake the PSR-18 client, not this package.** The seam the package offers its consumers is the
same one its own test suite drives it through — `sendRequest()`, one method — so a stub client
that answers from a queue needs no mocking framework and no network:

```php
final class StubClient implements \Psr\Http\Client\ClientInterface
{
    public function __construct(private array $queue) {}

    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return array_shift($this->queue);
    }
}

$linode = Client::withToken('test-token', new StubClient([
    new Response(200, [], json_encode(['data' => [...], 'page' => 1, 'pages' => 1, 'results' => 1])),
]));
```

Mocking `Client` or an endpoint class instead tests your own mock. A framework's HTTP facade
fake will not see this traffic either, unless the framework's own client is what you injected —
which is what the Laravel wrapper below is for.

**Give every fake a body.** A 200 with an empty body raises `MalformedResponseException`
rather than reading as an empty collection, and that is deliberate: Laravel's `Http::fake()`
with no arguments answers every request with exactly that, so a fake whose body was forgotten
would otherwise read as "this account has no zones" and let the assertions pass. The only
success with a legitimately empty body is a 204.

## Laravel

Nothing here depends on Laravel, and the transport is injected rather than chosen — so a
framework's own HTTP client can carry this traffic by implementing PSR-18's one method over
it. That is what makes `Http::fake()` and `Http::preventStrayRequests()` able to see these
requests, which they cannot when a package holds its own client.

[`hampel/linode-api-laravel`](https://github.com/hampel/linode-api-laravel) provides that
adapter, with a service provider, a manager for named accounts and a facade.

## Versioning and support

`^1.0` is the constraint to write. PHP 8.3 or later.

**The public API is stable.** A break in any class, method or signature outside `tests/` and
`harness/` means `2.0.0`. Two consequences of that are worth knowing before you rely on them:

- **`RecordType`, `DomainType`, `DomainStatus` and `CaaTag` are enums, so an exhaustive
  `match` over one throws `UnhandledMatchError` the day a case is added.** Adding a case is
  therefore a major here — but write a `default` arm anyway.
- **`ApiException::$statusCode`, `$errors`, `$body`, `$retryAfter` and `$meta` are the
  supported surface.** What is inside one of Linode's error objects, and the shape of an
  entity's `raw`, are the API's rather than this package's and are not covered.

Entities serialise to the payload the API sent, unchanged, so a field added to the API after
a release is reachable through `$entity->raw` without waiting for one.

**One question about Linode's own behaviour is still open**, and it cannot move a signature: a
restricted user's grants are covered by the test suite and have not been exercised against a
real restricted account. The trap in that endpoint — the `204` it answers for an *unrestricted*
user — is measured.

## License

MIT — see [LICENSE.md](LICENSE.md).
