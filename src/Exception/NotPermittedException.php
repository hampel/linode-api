<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * The token is valid and does not have the standing for this - HTTP 403.
 *
 * Two different situations reach here and both are configuration rather than code: the token
 * was created without the OAuth scope the endpoint needs (`domains:read_write` to write a
 * record), or it belongs to a restricted user whose grants do not cover the object. Profile
 * grants are how to tell which - see the Profile endpoint.
 */
final class NotPermittedException extends ApiException
{
}
