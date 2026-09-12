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
* `Support\Ttl`, because Linode silently rounds every interval UP to its own list. One rule
  for a zone's four interval fields and a record's `ttl_sec` alike — which is not what the
  specification says about records, and the difference was measured rather than read. Nothing
  here rewrites a caller's value; `effectiveTtl()` reports what it will become, and returns
  null for a record's zero because what that inherits is undocumented
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

* **an insufficient OAuth scope is a 401, not a 403** — the same status as a bad credential,
  and the opposite fix. `X-OAuth-Scopes` tells them apart, because Linode reports a token's
  own scopes only for a token it recognises: a 401 naming them is a scope failure, a 401
  saying `unknown` is a bad credential. `NotPermittedException` is therefore raised for both
  that 401 and a 403, and carries `isScopeFailure()`, `requiredScopes()` and `heldScopes()`.
  This is the one place the exception type does not follow the status, and the message says
  so, because the status contradicts it
* a `Retry-After` is sent on **every** response, including a 200 beside
  `X-RateLimit-Remaining: 1839` — 60 on a fresh window and 46 later in the same one, so it
  counts down to the reset rather than being fixed. It is not evidence of being throttled;
  only a 429 is
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

### Confirmed against a real account, same day

All four read-only exercises green with a `domains:read_write` token. `verify` is what found
the 401-for-scope behaviour above, on its first run, against a package that had taken 403 from
the specification — the suite was green the whole time, because the stub agreed with the code.

* the token check, the grants 204 for an unrestricted user, and the account refusal all
  behave as documented
* every status in `errors` mapped to the type the hierarchy claims
* the domain filter is honoured — 170 zones unfiltered against 0 for a name that cannot
  exist — and an account that size walks two pages, so the lazy page walk is exercised across
  a real boundary

### Found by the write exercise, same day

`records` was run against a real zone — one throwaway TXT record, created, probed and deleted,
plus the zone's own TTL changed and restored.

* **a record's `ttl_sec` rounds UP off the same list a zone uses, not to the nearest off a
  shorter one.** Linode's documentation says the latter. Measured by writing each value and
  reading back what was stored: 60 stores 120 (not 300), 900 stores 3600 (not 300), and 30 and
  120 are accepted for a record though the documented list starts at 300. `Support\Ttl` had
  the documented rule and has been corrected to the measured one; `RECORD_VALUES`,
  `roundForRecord()` and `isValidForRecord()` are gone, there being one rule rather than two.
  A re-run then predicted all nine record values and all four zone values
* **the zone file lags the record endpoints, by minutes rather than seconds.** Straight after
  a write it rendered a TTL two edits old and a record already deleted; on another run a
  change had still not appeared after 160 seconds. It is authoritative about what is served,
  which is why it lags — it is not a read-your-writes view, and diffing it to confirm an edit
  landed will mislead
* confirmed: a PUT of one field really is partial, and a record is readable from the record
  endpoint immediately after its create returns
* `DomainRecord::effectiveTtl()` now returns `?int` — null for a `ttl_sec` of 0. It returned
  86400 on the reasoning that zero means the zone default, which is a guess about a field
  Linode does not document, from the same documentation already found wrong twice here

### Still not measured

* **the separator between multiple scopes in `X-OAuth-Scopes`.** The token it was run with
  holds one scope, so no separator was observable and `Result\Scopes` still splits on commas,
  whitespace or both. A multi-scope token settles it; `verify` prints the raw header
* **what a record's `ttl_sec` of 0 inherits** — the fixed 86400, or the zone's own TTL. Only
  the rendered zone file can answer it and that file did not converge inside the probe's
  150-second ceiling, so the `records` exercise reports it inconclusive rather than guessing.
  `effectiveTtl()` returns null for the case, which is right either way
* **a restricted user's grants.** The account's user is unrestricted, so `/profile/grants`
  answers the 204 and the `Grants` branch is covered only by the suite
