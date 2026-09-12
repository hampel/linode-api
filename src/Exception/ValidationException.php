<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * Linode rejected a value in the request - HTTP 400.
 *
 * Much the commonest failure against this API and the one worth catching by name, because it
 * is the only one a caller can usually fix from what came back: every error carries a
 * `reason`, and most carry the `field` that caused it. See ApiException::fieldErrors().
 *
 * Note that 400 covers more than a malformed value - asking for a `page_size` outside 25-500
 * is one, and so is an `X-Filter` that is not JSON.
 */
final class ValidationException extends ApiException
{
}
