<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * The credential itself is no good - missing, malformed, expired or revoked.
 *
 * Linode answers `{"errors": [{"reason": "Invalid Token"}]}` for all four, and for a request
 * that carried no Authorization header at all, so there is nothing in the response to tell
 * those apart. Measured on 12 September 2026: no header and a made-up bearer token produce
 * byte-identical replies.
 *
 * NOT EVERY 401 REACHES HERE. A valid token refused for its scopes is also a 401 on this API,
 * and becomes NotPermittedException instead - the two need opposite responses from whoever
 * reads the error, so they are separated on `X-OAuth-Scopes` rather than left to the status.
 * ApiException has the detail. What arrives here is the case where Linode did not recognise
 * the credential at all, and the answer is a new one.
 */
final class NotAuthenticatedException extends ApiException
{
}
