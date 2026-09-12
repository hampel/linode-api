# hampel/linode-api

A PHP client for the Linode (Akamai Cloud) API v4, over any PSR-18 HTTP client. It wraps the
DNS endpoints and the two that identify a credential — a dozen of some three hundred paths,
deliberately, with a first-class extension point for the rest.

## Commands

```bash
composer check          # lint, analyse, test - what CI runs
composer test           # phpunit
composer analyse        # phpstan, level 10, PHP 8.3-8.5 in one pass
composer format         # pint
```

`composer-require-checker` is **not** in `composer check` — it lives outside the package and
only CI runs it, so nothing local re-runs it when an import changes. Run it by hand after
adding or changing any `use` in `src/`:

```bash
mkdir -p /tmp/crc && composer -d /tmp/crc require maglnet/composer-require-checker
/tmp/crc/vendor/bin/composer-require-checker check composer.json
```

## Layout

| path | what it is |
|---|---|
| `src/Client.php` | the entry point; named accessors and `endpoint()` |
| `src/Connection.php` | everything that touches HTTP |
| `src/Config.php` | which API, which version, and how URLs are built |
| `src/Authentication/` | the credential, and the interface a refreshing one would implement |
| `src/Endpoint/` | endpoint groups, and the `Endpoint` base class every extension builds on |
| `src/Entity/` | what an endpoint answers with; each reads and writes |
| `src/Enum/` | the closed sets - record type, domain type and status, CAA tag |
| `src/Result/` | pagination, response metadata, scopes, the token check |
| `src/Support/` | casting, the `X-Filter` builder, the TTL rules, PSR-17 discovery |
| `src/Exception/` | the hierarchy, from `LinodeException` down |
| `harness/` | rig exercises - real calls to the real API |

## One class per record type would have been wrong, and one constructor would too

Linode rejects a field that does not belong to the record type being created, so a single
constructor with eleven optional arguments produces a payload the API refuses and a call site
that cannot be read. Hence a named constructor per type on `DomainRecord`, and a `toArray()`
that emits only the fields that type may carry.

They are not separate CLASSES because Linode's create body is a subset of what a read returns:
a second write-only class would duplicate a dozen fields to omit one, and the round trip -
read a record, change a field, send it back - would need a conversion nobody would keep in
step.

## Facts about the Linode API worth not rediscovering

Each is asserted in `tests/` or printed by a harness exercise. Those marked *measured* were
read off the live API on 12 September 2026.

- **AN INSUFFICIENT SCOPE IS A 401, NOT A 403** — *measured* with a token holding
  `domains:read_write` and nothing else. The same status as a bad credential, and the
  opposite fix. `X-OAuth-Scopes` discriminates: Linode reports a token's own scopes only for
  a token it recognises, so a 401 naming them is a scope failure and a 401 saying `unknown`
  is a bad credential. `ApiException::fromResponse()` routes on that, which is the one place
  in this package where the exception type deliberately does not follow the status. It was
  written the other way round first, from the specification, and the `verify` exercise found
  it on its first run.
- **A `Retry-After` is on every response, including a 200** — *measured*: 60 on a fresh
  window and 46 later in the same one, so it counts down to the reset rather than being
  fixed. Its presence is not a throttle signal, and a client that backed off on seeing one
  would sleep after every successful call. Only a 429 means it.
- **`GET /v4/profile` needs no OAuth scope**, which is what makes it the token check. Every
  other endpoint conflates "your token is wrong" with "your token may not do this".
- **`GET /v4/profile/grants` answers 204 for an UNRESTRICTED user.** Decoded as an ordinary
  body that is an empty grants object, which says the user may do nothing — the opposite of
  the truth. `Profile::grants()` returns null for it and `Profile::$restricted` is the field
  to branch on. This is the trap in this API most likely to be read backwards.
- **A missing token and a made-up one are indistinguishable** — *measured*, byte for byte:
  `401 {"errors":[{"reason":"Invalid Token"}]}` for both, and for an expired or revoked one.
  So no exception message here claims to know which of the four it was.
- **Filtering is a request HEADER, not a query string.** `X-Filter` carries a JSON object.
  There is no `?domain=example.com` on this API.
- **A filter that stopped being honoured would be a silent success**, not an error: a 200
  carrying the whole collection. `findByName()` therefore re-checks the name it got back, and
  the `filtering` exercise compares a filtered count against an unfiltered one, which is a
  question a mocked suite cannot ask - the mock honours the filter by construction.
- **`page_size` below 25 is a 400** — *measured*, `{"field":"page_size","reason":"Must be
  25-500"}`. Surprising to anyone who has asked another API for one item to see the shape of
  it. `Page::assertValidPageSize()` refuses it before spending a request.
- **A page past the end is an empty page, not an error** — unlike some APIs, so "read until
  empty" works here. `apiEach()` still terminates on `hasMore()`, which saves one request per
  walk on an API that counts them.
- **Every update is a PUT and every PUT is partial.** Sending one field changes that field.
  The `records` exercise is what proves it against the live API rather than against a mock.
- **A record's type cannot be changed** - the field is absent from the update schema, so
  `DomainRecords::update()` strips it rather than sending it to be rejected.
- **TTLs are rounded UP, silently, by ONE rule** - and the specification says otherwise for
  records. It describes a record's `ttl_sec` as rounded to the *nearest* valid value off a
  list starting at 300; measured on 12 September 2026 by writing each value to a real record,
  it rounds up off the same list a zone uses, starting at 30:

  ```
  asked      0    1   30   60  120  300   900  3000  86401  2419201
  stored     0   30   30  120  120  300  3600  3600 172800  2419200
  ```

  The zone rule was measured in the same run and does match its documentation. `Support\Ttl`
  carried the two-rule model until the harness contradicted it; `SupportTest::ttlCases()`
  pins the table so prose cannot drift back in. Zero on a ZONE means "use the default", which
  differs per field. Zero on a RECORD is undocumented and `DomainRecord::effectiveTtl()`
  returns null for it rather than guessing again.
- **The zone file lags the record endpoints, by MINUTES** - *measured*: straight after a
  write it rendered a TTL two edits old and a record that had already been deleted, and on
  another run a change had still not appeared after 160 seconds. It is authoritative about
  what is SERVED, which is why it lags. Do not diff it to confirm an edit landed; read the
  record endpoint. Any harness probe reading it has to wait for convergence rather than break
  on the first sight of what it is looking for - the `records` exercise did exactly that once
  and manufactured a false reading. It is also why the ttl-0 question is still open: the
  probe is correct now and the file simply does not settle inside a reasonable ceiling.
- **SRV takes its service and protocol undecorated.** Linode prepends the underscore and
  appends the period itself, so `_sip` becomes `__sip` and matches nothing, with no error.
  `DomainRecord::srv()` refuses a leading underscore. SRV also has no `name` of its own -
  Linode composes it - so one is not sent.
- **Dates carry no timezone and are UTC** — `2018-01-01T00:01:01`, no `Z`, no offset. PHP
  reads an unqualified string in its own default timezone, so the same response is a
  different instant on a box set to Australia/Sydney. `Cast::datetime()` supplies UTC rather
  than inferring it, and leaves a value that does carry an offset alone.
- **A successful DELETE is `{}` with a 200** - `Content-Length: 2`, *measured* on 2026-09-13 -
  not a 204 and not an empty body, so it decodes like anything else.
- **204 IS THE ONLY SUCCESS WITH A LEGITIMATELY EMPTY BODY** - `GET profile/grants` on an
  unrestricted user - so an empty-bodied 200 raises `MalformedResponseException`. The check
  accepted any empty-bodied 2xx until the first consumer found what that masked: Laravel's
  `Http::fake()` with no arguments answers every request with an empty 200, so a fake with a
  forgotten body read as "this account has no zones" and the assertions passed. The DELETE
  response looks like the case that needs the wider check and is not - which is why it was
  measured rather than reasoned about.
- **A zone's SOA and NS records are not records.** Linode generates and serves them without
  representing them in the record endpoint, so a healthy zone can answer with an empty list.
  `Domains::zoneFile()` is the whole picture.
- **A domain is unique across the whole of Linode**, not just across one account - which is
  why a create can fail for a reason that is about the world rather than about the request.
- **`v4beta` is a URL segment**, so it moves every request a client makes. Hence
  `Client::withVersion()` returning a second client rather than a flag on a call.
- **The deployed API runs ahead of the published specification** — *measured*,
  `X-Spec-Version: 4.235.1` against a document at 4.215.0.
- **Linode's DNS has no SSHFP, TLSA, NAPTR, DNSKEY or DS.** `RecordType` is the complete set.

## On a version bump, grep the tree for the constraint you just superseded

The constraint appears in more places than the file you are editing, and the CHANGELOG is the
one you will remember. `0.2.0` shipped with a README still telling readers `^0.1` is the
constraint to write — which resolves to `0.1.0` and therefore excludes the release they are
reading about, with a paragraph underneath explaining that a `0.2.0` will not arrive unasked.

**Reviewing the release diff cannot find this**, because the stale line is in a file the
release did not touch. One command does:

```bash
git grep -nF '^0.1'
```

Found in this package by its first consumer, who hit the same thing in their own tree — the
constraint was in three files there, against a list of edits that named one. Fixed in `0.2.1`.
`~/.claude/rules/stale-documentation.md` is the general form of this and was written before any
of it.

## The harness

`vendor/bin/rig` lists the exercises. `verify`, `domains`, `errors` and `filtering` are
read-only.

`records` **writes to real DNS**: it creates a throwaway TXT record in `LINODE_DOMAIN`, reads
it back, updates it and deletes it in a `finally`. It is opt-in:

```bash
LINODE_DOMAIN=example.com LINODE_WRITE_RECORDS=yes vendor/bin/rig records
```

`yes` rather than `1`, so it cannot be set by habit. Under an agent it refuses even then,
unless `LINODE_AGENT_MAY_WRITE_RECORDS=1` is also given **on the command line** for that one
run — never in `.env`, because a persisted authorisation is one nobody gave. See
`harness/lib/agent.php`.

**If an exercise fails for want of a credential, that is the guard working.** The rig does not
load `.env` in an agent session. Do not go looking for the token.

## What the live runs settled, 12 September 2026

Against a real account with a `domains:read_write` token, all four read-only exercises green:

- `verify` — the token check works end to end, and **found the 401-for-scope behaviour above
  on its first run**, where the package had assumed 403 from the specification. That is the
  case for the harness in one line: the suite was green throughout, because the stub agreed
  with the code.
- `X-OAuth-Scopes` carries **one scope per token here and no separator was observable**, so
  `Result\Scopes` still splits on commas and whitespace. Not yet settled; a multi-scope token
  would settle it.
- `errors` — every status maps to the type the hierarchy claims: 401 Invalid Token, 400
  page_size, 400 X-Filter, 404 for both a missing record and a missing path.
- `filtering` — 170 zones unfiltered against 0 for a name that cannot exist, so the filter is
  honoured. That account size also walks two pages, so `apiEach()` is exercised across a real
  page boundary.
- `domains` — 170 zones read and parsed, every one master/active.
- `records` **has now been run**, and found the TTL rule above. It also confirmed that the
  PUT really is partial - a ttl-only update left the target intact - and that a record is
  readable from the record endpoint immediately after its create returns.

**Two lessons from that run, both worth keeping:**

- **Verify a restore, not just the restore call.** The exercise reported the zone TTL
  restored and it was; reading the zone file afterwards showed 120 and a deleted record,
  which looked like a botched cleanup and was actually the zone-file lag above. Checking the
  API rather than the rendered file is what told the two apart.
- **A probe that breaks on the first sight of its own marker reads the previous state.** The
  ttl-0 probe did that, matched a stale line, and printed a confident-looking measurement
  with `waited 0s`. It now settles on a distinctive value, waits for that to render, and only
  then changes it and waits for the value to move.

## What the suite cannot tell you

Every test drives a stubbed PSR-18 client, so the stub encodes the same assumptions the code
does: when the API changes, both stay agreed with each other and disagreed with reality. The
harness is the only instrument that can see that, which is the whole argument for it being
assertion-free and run by a person.

Two things in particular are still unverified against a live account, and both are noted in
the CHANGELOG: the separator in the `X-OAuth-Scopes` header, and what a record's `ttl_sec` of
0 inherits.

### A test that pins one outcome of a branch says nothing about the others

`Connection::send()` has three outcomes for a 2xx - decoded, legitimately empty, somebody
else's answer - and for a while the suite had a test for only the middle one,
`test_a_204_with_no_body_is_a_success`. That reads as coverage of the branch and is coverage of
one arm of it.

Measured on 2026-09-13 rather than reasoned about, by putting the WRONG narrowing in
deliberately - `if (trim($body) === '')` where the correct form is `if ($status === 204)` - and
running both suites against it:

| suite | result |
|---|---|
| as it was, with the single 204 test | 188 green |
| with the four empty-200 tests added | 3 failures, naming the behaviour |

So the old suite could not distinguish the right narrowing from the wrong one, and would have
been green either way. **It was not the behaviour that was untested, it was the question.**
Worth repeating the deliberate-wrong-answer check on any branch here whose arms are a success
and a raise, because a green suite over one arm is the shape that hides it.

Restoring afterwards is `git checkout -- <files>` on a clean tree, which is why the tree should
be committed before a probe like this rather than after.
