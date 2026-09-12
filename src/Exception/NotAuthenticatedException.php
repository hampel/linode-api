<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * The token was missing, malformed, expired or revoked - HTTP 401.
 *
 * Linode answers `{"errors": [{"reason": "Invalid Token"}]}` for all four, and for a
 * request that carried no Authorization header at all, so there is nothing in the response to
 * tell them apart. Measured on 12 September 2026: no header and a made-up bearer token
 * produce byte-identical replies.
 *
 * Distinct from NotPermittedException, which means the token is real and may not do this.
 */
final class NotAuthenticatedException extends ApiException
{
}
