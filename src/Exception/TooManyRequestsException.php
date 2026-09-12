<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * Rate limited - HTTP 429.
 *
 * THE ONLY THING THAT MEANS YOU WERE THROTTLED. `retryAfter` is on every response this API
 * sends, so its presence is not the signal; this type is. See ApiException::retryAfter().
 *
 * Linode's limit is reported on every response as `X-RateLimit-Limit`,
 * `X-RateLimit-Remaining` and `X-RateLimit-Reset` (a unix timestamp) - Result\\ResponseMeta
 * carries them, so a caller can slow down before being told to.
 */
final class TooManyRequestsException extends ApiException
{
}
