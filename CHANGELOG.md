CHANGELOG
=========

Unreleased
----------

Initial build. Nothing is released, so everything here is the first version of it.

* a PSR-18 client for the Linode (Akamai Cloud) API v4, covering the DNS endpoints and the
  two that identify a credential — `Client`, `Config`, `Connection` and `Authentication`,
  with the transport injected rather than chosen
* `Domains` — list, walk, find by id or by name, create, update, delete, import, clone, and
  the rendered zone file
* `DomainRecords`, and `Domains::records()` which binds the zone so the id is written once.
  A named constructor per record type, because Linode rejects a field the type does not use:
  `a`, `aaaa`, `cname`, `ns`, `mx`, `nullMx`, `txt`, `srv`, `caa`, `ptr`
* `Profile::verify()` — one request answering whether a token works and what it may do, off
  `GET /v4/profile`, which needs no OAuth scope and so is the only endpoint that cannot
  confuse a bad credential with an unauthorised one. Scopes come from the response header, so
  it costs nothing extra
* `Profile::grants()` returns null for the 204 Linode answers an unrestricted user with —
  read as a body, that empty response says the user may do nothing, which is the opposite of
  what it means
* `Account`, behind `account:read_only`, with `find()` for a diagnostic that would rather
  report what it can than stop at the first thing it may not see
* `Support\Filter` for `X-Filter`, which is a request header on this API rather than a query
  string, and carries the ordering too
* `Support\Ttl`, because Linode silently rounds every interval to its own list — and by two
  different rules: a zone's fields round up from a list starting at 30, a record's `ttl_sec`
  rounds to the nearest off one starting at 300. Nothing here rewrites a caller's value;
  `effectiveTtl()` reports what it will become
* an exception per failure the API distinguishes, so a consumer catches what it can act on:
  `ValidationException` (400, with `fieldErrors()`), `NotAuthenticatedException`,
  `NotPermittedException`, `NotFoundException`, `TooManyRequestsException`, `ServerException`,
  `MalformedResponseException` and `RequestException`
* `MalformedResponseException` for a 2xx whose body is not JSON. Decoded permissively that is
  an empty array, which reads downstream as "this account has no domains" — the failure worth
  being loudest about in a package used to manage DNS
* `Domains::findByName()` checks the name on the way back as well as filtering on the way
  out. Its correctness rests on the server honouring `X-Filter`, and a filter that stopped
  being honoured would answer 200 with the whole collection rather than an error — so the
  first zone on the account would be returned as the one that was asked for
* `Result\ResponseMeta` carries the rate limit, the token's scopes and `X-Spec-Version` off
  every response, so an integration can slow down before being refused
* entities keep the payload they were built from, so a field Linode adds after a release is
  reachable through `$entity->raw` without waiting for one
* PSR-17 factories are optional and discovered by class name — Guzzle's, Nyholm's or
  Diactoros' — which keeps the declared dependencies to four PSR interfaces
* harness: `verify`, `domains`, `errors` and `filtering` are read-only; `records` writes to
  real DNS and needs `LINODE_WRITE_RECORDS=yes`, and refuses under an agent without a second
  opt-in given on the command line
* `filtering` exists for a failure a test cannot see: it asks the same question filtered and
  unfiltered and prints the counts against each other, because a filter being ignored is a
  200 that looks like success

### Measured against the live API on 12 September 2026

Read off `api.linode.com` with unauthenticated requests, and each one is now either asserted
in `tests/` or printed by a harness exercise:

* `Retry-After: 60` is sent on **every** response, including a 200 beside
  `X-RateLimit-Remaining: 1839`. It is not evidence of being throttled; only a 429 is
* a missing `Authorization` header and a made-up bearer token produce byte-identical
  `401 {"errors":[{"reason":"Invalid Token"}]}` replies
* `?page_size=1` is `400 {"field":"page_size","reason":"Must be 25-500"}`
* an `X-Filter` that is not JSON is `400 {"field":"X-Filter","reason":"Filter not valid JSON"}`
* an unknown path is `404 {"errors":[{"reason":"Not found"}]}`; a POST to a read-only path is
  405
* the deployed API reported `X-Spec-Version: 4.235.1` while the published specification was at
  4.215.0, so the documentation runs behind the thing it documents

The specification this package was written against is
[linode/linode-api-docs](https://github.com/linode/linode-api-docs) `openapi.json` on the
`development` branch, version 4.215.0.

### Not yet measured

* **the separator between multiple scopes in `X-OAuth-Scopes`.** An unauthenticated request
  reports `unknown`, and no token was available when this was written, so `Result\Scopes`
  splits on commas, whitespace or both. The `verify` exercise prints the raw header, and the
  first run against a real token settles it
* every write path. The suite drives them through a stubbed PSR-18 client, which by
  construction agrees with whatever the package believes; only the `records` exercise can
  contradict it
