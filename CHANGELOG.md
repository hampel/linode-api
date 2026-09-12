CHANGELOG
=========

Unreleased
----------

* an empty-bodied `200` now raises `MalformedResponseException`. Only a `204` is treated as a
  success with no body

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
