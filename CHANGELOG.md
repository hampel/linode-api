CHANGELOG
=========

Unreleased
----------

* documents the `X-OAuth-Scopes` separator as a single space, and why `Scopes::fromHeader()`
  accepts a comma anyway. No behaviour change

0.3.0 (2026-09-13)
------------------

* `DomainRecord::effectiveTtl()` takes the zone, as a `Domain` or a `ttl_sec`, and resolves a
  record `ttl_sec` of `0` to the zone's TTL. A record's zero inherits the zone rather than
  falling back to a fixed default; without a zone it still answers `null`

0.2.1 (2026-09-13)
------------------

* README states `^0.2` as the constraint to write. `0.2.0` shipped saying `^0.1`, which
  resolves to `0.1.0` and excludes the release it documents

0.2.0 (2026-09-13)
------------------

**Breaking, hence 0.2.0 rather than 0.1.1:** code that relied on an empty-bodied `200`
resolving to an empty response now gets an exception.

* an empty-bodied `200` raises `MalformedResponseException`. Only a `204` is a success with no
  body, which on this API is `GET /v4/profile/grants` for an unrestricted user
* a successful `DELETE` answers `200` with a body of `{}`, so it decodes like any other
  response and is unaffected

0.1.0 (2026-09-13)
------------------

Initial release.

* a PHP client for the Linode (Akamai Cloud) API v4 over any PSR-18 HTTP client
* covers the domain and domain-record endpoints, `GET /v4/profile` and `GET /v4/account`
* `Client::verify()` reports whether a token works and which OAuth scopes it holds
* `Support\Filter` builds the `X-Filter` header; `Result\Page` walks a collection
* an exception per failure the API distinguishes, under `ApiException`
* `Endpoint` subclasses reach any endpoint the package does not wrap

Written against [linode/linode-api-docs](https://github.com/linode/linode-api-docs)
`openapi.json` on the `development` branch, version 4.215.0.
